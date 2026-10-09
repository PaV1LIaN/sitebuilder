<?php

declare(strict_types=1);

use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Sharing;

class DiskRightsVersionConflictException extends RuntimeException
{
    private string $currentRevision;

    public function __construct(string $currentRevision)
    {
        parent::__construct('DISK_RIGHTS_VERSION_CONFLICT');
        $this->currentRevision = $currentRevision;
    }

    public function currentRevision(): string
    {
        return $this->currentRevision;
    }
}

/**
 * Тонкий слой над штатным ACL модуля «Диск».
 *
 * Числовые TASK_ID намеренно нигде не зафиксированы: они могут отличаться
 * между установками и версиями. ID всегда запрашиваются у RightsManager.
 */
class BitrixDiskRightsService
{
    public const INHERIT = 'inherit';
    public const NONE = 'none';

    private const MAX_USERS = 1000;

    public static function getAccessMatrix(
        DiskContext $context,
        int $folderId
    ): array {
        $folder = self::loadFolder($folderId);
        $manager = Driver::getInstance()->getRightsManager();
        $tasks = self::taskDefinitions($manager);
        $specificRights = self::specificRights($manager, $folder);
        $directByAccessCode = self::groupRightsByAccessCode($specificRights);
        $pageUsers = DiskPageUserRepository::listUsersWithPageAccess(
            $context->siteId,
            $context->pageId
        );
        $managedBindings = DiskAclBindingRepository::listManagedForFolder(
            $context->siteId,
            $folderId
        );

        $users = [];
        foreach ($pageUsers as $pageUser) {
            $userId = (int)($pageUser['userId'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $accessCode = 'U' . $userId;
            $directRows = $directByAccessCode[$accessCode] ?? [];
            $isAclProtected = self::isAclProtectedPageUser($pageUser);
            $capabilities = $isAclProtected
                ? self::fullCapabilities()
                : self::effectiveCapabilities($folder, $userId);
            $physicalDirectTaskName = self::directTaskName(
                $directRows,
                $tasks
            );
            $binding = $managedBindings[$accessCode] ?? null;
            $managedAppliedTaskName = is_array($binding)
                ? (string)($binding['appliedLevel'] ?? '')
                : '';
            $implicitManagedDeny = !$isAclProtected
                && $physicalDirectTaskName === self::INHERIT
                && $managedAppliedTaskName === self::NONE
                && self::effectiveTaskName($capabilities) === self::NONE;

            $pageUser['accessCode'] = $accessCode;
            $pageUser['isCurrentUser'] = $userId === $context->currentUserId;
            $pageUser['isAclProtected'] = $isAclProtected;
            $pageUser['physicalDirectTaskName'] = $physicalDirectTaskName;
            $pageUser['directTaskName'] = $implicitManagedDeny
                ? self::NONE
                : $physicalDirectTaskName;
            $pageUser['managedTaskName'] = $managedAppliedTaskName;
            $pageUser['isImplicitManagedDeny'] = $implicitManagedDeny;
            $pageUser['effectiveTaskName'] = self::effectiveTaskName(
                $capabilities
            );
            $pageUser['effectiveCapabilities'] = $capabilities;
            $pageUser['rightSource'] = $isAclProtected
                ? 'system_admin'
                : ($implicitManagedDeny
                    ? 'managed_none'
                    : (empty($directRows) ? 'inherited' : 'direct'));

            $users[] = $pageUser;
        }

        return [
            'folderId' => $folderId,
            'tasks' => self::publicTaskDefinitions($tasks),
            'users' => $users,
            'rightsRevision' => self::revision(
                $folderId,
                $users,
                $specificRights,
                $managedBindings
            ),
        ];
    }

    public static function saveAccessMatrix(
        DiskContext $context,
        int $folderId,
        array $requestedRights,
        string $expectedRevision
    ): array {
        $expectedRevision = trim($expectedRevision);
        if ($expectedRevision === '') {
            throw new InvalidArgumentException('EXPECTED_RIGHTS_REVISION_REQUIRED');
        }

        if (count($requestedRights) > self::MAX_USERS) {
            throw new RuntimeException('TOO_MANY_DISK_RIGHTS');
        }

        $lock = self::acquireLock($folderId);

        try {
            $currentMatrix = self::getAccessMatrix($context, $folderId);
            if (!hash_equals(
                (string)$currentMatrix['rightsRevision'],
                $expectedRevision
            )) {
                throw new DiskRightsVersionConflictException(
                    (string)$currentMatrix['rightsRevision']
                );
            }

            $normalized = self::normalizeRequestedRights(
                $requestedRights,
                $currentMatrix
            );

            $folder = self::loadFolder($folderId);
            $manager = Driver::getInstance()->getRightsManager();
            $tasks = self::taskDefinitions($manager);
            $specificRights = self::specificRights($manager, $folder);
            $nativeSharingBackup = self::nativeUserSharings(
                $folder,
                array_keys($normalized)
            );
            $replacement = self::replaceSpecificUserRights(
                $specificRights,
                $normalized
            );

            try {
                self::applyRequestedRights(
                    $manager,
                    $folder,
                    $replacement,
                    $normalized,
                    $context->currentUserId
                );

                /*
                 * set()/append()/revokeByAccessCodes() перестраивают штатные
                 * simple-rights Диска. Проверяем результат после всех вызовов,
                 * перечитывая прямые правила и итоговый SecurityContext.
                 */
                $externalRights = self::assertRequestedRightsWritten(
                    $manager,
                    $folder,
                    $normalized,
                    $tasks
                );
                DiskAclBindingRepository::saveManagedIntents(
                    $context,
                    $folderId,
                    $normalized,
                    $externalRights
                );
            } catch (Throwable $exception) {
                self::restoreRightsSet($manager, $folder, $specificRights);
                self::restoreNativeUserSharings(
                    $manager,
                    $folder,
                    $nativeSharingBackup,
                    array_keys($normalized),
                    $context->currentUserId
                );
                throw $exception;
            }

            return self::getAccessMatrix($context, $folderId);
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Возвращает только прямые пользовательские правила корневой папки.
     * Группы и наследование намеренно не разворачиваются: контроллер должен
     * владеть только теми U-кодами, которые сам записал.
     *
     * @return array<int,string> userId => taskName
     */
    public static function getDirectUserRightsSnapshot(
        int $folderId,
        ?int $siteId = null
    ): array
    {
        $folder = self::loadFolder($folderId);
        $manager = Driver::getInstance()->getRightsManager();
        $tasks = self::taskDefinitions($manager);
        $byAccessCode = self::groupRightsByAccessCode(
            self::specificRights($manager, $folder)
        );

        $result = [];
        foreach ($byAccessCode as $accessCode => $rights) {
            if (!preg_match('/^U([1-9]\d*)$/', $accessCode, $matches)) {
                continue;
            }

            $userId = (int)$matches[1];
            $result[$userId] = self::semanticDirectTaskName(
                $folder,
                $userId,
                $rights,
                $tasks
            );
        }

        if ($siteId !== null && $siteId > 0) {
            foreach (DiskAclBindingRepository::listManagedForFolder(
                $siteId,
                $folderId
            ) as $accessCode => $binding) {
                if (
                    (string)($binding['appliedLevel'] ?? '') !== self::NONE
                    || !preg_match('/^U([1-9]\d*)$/', $accessCode, $matches)
                ) {
                    continue;
                }

                $userId = (int)$matches[1];
                if (
                    !isset($result[$userId])
                    && !self::userCanReadStrict($folder, $userId)
                ) {
                    $result[$userId] = self::NONE;
                }
            }
        }

        ksort($result, SORT_NUMERIC);
        return $result;
    }

    /**
     * Идемпотентно заменяет набор прямых U-прав под файловой блокировкой.
     * expectedRights защищает план сверки от параллельного ручного изменения.
     * Значение inherit удаляет прямое правило, но не трогает группы и родителей.
     *
     * @param array<int,string> $requestedRights
     * @param array<int,string> $expectedRights
     * @param int $actorUserId Пользователь, от имени которого создаётся Sharing.
     */
    public static function replaceDirectUserRights(
        int $folderId,
        array $requestedRights,
        array $expectedRights,
        int $actorUserId
    ): array {
        if (count($requestedRights) > self::MAX_USERS) {
            throw new RuntimeException('TOO_MANY_DISK_RIGHTS');
        }
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('INVALID_DISK_RIGHTS_ACTOR');
        }

        $lock = self::acquireLock($folderId);

        try {
            $folder = self::loadFolder($folderId);
            $manager = Driver::getInstance()->getRightsManager();
            $tasks = self::taskDefinitions($manager);
            $requestedRights = self::normalizeDirectUserTaskMap(
                $requestedRights,
                $tasks
            );
            $expectedRights = self::normalizeDirectUserTaskMap(
                $expectedRights,
                $tasks
            );

            $specificRights = self::specificRights($manager, $folder);
            $directByAccessCode = self::groupRightsByAccessCode($specificRights);
            $nativeCurrent = self::nativeUserSharings(
                $folder,
                array_keys($requestedRights)
            );
            $current = [];

            foreach ($directByAccessCode as $accessCode => $rights) {
                if (!preg_match('/^U([1-9]\d*)$/', $accessCode, $matches)) {
                    continue;
                }
                $userId = (int)$matches[1];
                $current[$userId] = self::semanticDirectTaskName(
                    $folder,
                    $userId,
                    $rights,
                    $tasks
                );
            }

            $semanticCurrent = $current;
            foreach ($expectedRights as $userId => $expectedTask) {
                $currentTask = $current[$userId] ?? self::INHERIT;
                if (
                    $expectedTask === self::NONE
                    && $currentTask === self::INHERIT
                    && !self::userCanReadStrict($folder, (int)$userId)
                ) {
                    $currentTask = self::NONE;
                    $semanticCurrent[$userId] = self::NONE;
                }
                if (!hash_equals($expectedTask, $currentTask)) {
                    throw new DiskRightsVersionConflictException(
                        hash('sha256', json_encode($semanticCurrent))
                    );
                }
            }

            $changes = [];
            foreach ($requestedRights as $userId => $taskName) {
                $accessCode = 'U' . (int)$userId;
                $nativeRows = $nativeCurrent[$accessCode] ?? [];
                $directRows = $directByAccessCode[$accessCode] ?? [];
                $positiveTask = !in_array(
                    (string)$taskName,
                    [self::INHERIT, self::NONE],
                    true
                );
                $nativeStateMatches = $positiveTask
                    ? self::nativeSharingIsWritten(
                        $manager,
                        $accessCode,
                        (string)$taskName,
                        $nativeRows,
                        $directRows
                    )
                    : empty($nativeRows);

                if (
                    ($semanticCurrent[$userId] ?? self::INHERIT) !== $taskName
                    || !$nativeStateMatches
                ) {
                    $changes[$userId] = $taskName;
                }
            }

            if (empty($changes)) {
                ksort($semanticCurrent, SORT_NUMERIC);
                return [
                    'folderId' => $folderId,
                    'changedUserIds' => [],
                    'before' => $semanticCurrent,
                    'after' => $semanticCurrent,
                ];
            }

            $nativeSharingBackup = $nativeCurrent;
            $replacement = self::replaceSpecificUserRights(
                $specificRights,
                $changes
            );

            try {
                self::applyRequestedRights(
                    $manager,
                    $folder,
                    $replacement,
                    $changes,
                    $actorUserId
                );
                self::assertRequestedRightsWritten(
                    $manager,
                    $folder,
                    $changes,
                    $tasks
                );
            } catch (Throwable $exception) {
                self::restoreRightsSet($manager, $folder, $specificRights);
                self::restoreNativeUserSharings(
                    $manager,
                    $folder,
                    $nativeSharingBackup,
                    array_keys($changes),
                    $actorUserId
                );
                throw $exception;
            }

            $after = self::getDirectUserRightsSnapshot($folderId);
            return [
                'folderId' => $folderId,
                'changedUserIds' => array_map('intval', array_keys($changes)),
                'before' => $semanticCurrent,
                'after' => $after,
            ];
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Вычисляет права текущего пользователя штатным SecurityContext Диска.
     * Управление настройками блока остаётся в SiteBuilder и не делегируется ACL.
     */
    public static function resolvePermissions(
        DiskContext $context,
        int $folderId,
        array $sitebuilderPermissions
    ): array {
        $management = [
            'canManageAccess' => !empty($sitebuilderPermissions['canManageAccess']),
            'canEditSettings' => !empty($sitebuilderPermissions['canEditSettings']),
        ];

        if (
            $folderId <= 0
            || !PageAccessService::canViewPage(
                $context->siteId,
                $context->pageId,
                $context->currentUserId
            )
        ) {
            return array_merge(self::emptyCapabilities(), $management, [
                'role' => '',
                'accessSource' => 'bitrix_disk_acl',
                'effectiveTaskName' => self::NONE,
            ]);
        }

        $folder = self::loadFolder($folderId);
        $capabilities = self::effectiveCapabilities(
            $folder,
            $context->currentUserId
        );

        return array_merge($capabilities, $management, [
            'role' => 'bitrix_disk_' . self::effectiveTaskName($capabilities),
            'accessSource' => 'bitrix_disk_acl',
            'effectiveTaskName' => self::effectiveTaskName($capabilities),
        ]);
    }

    private static function loadFolder(int $folderId): Folder
    {
        if ($folderId <= 0) {
            throw new RuntimeException('DISK_ROOT_FOLDER_NOT_FOUND');
        }

        $folder = Folder::loadById($folderId);
        if (!$folder instanceof Folder) {
            throw new RuntimeException('DISK_ROOT_FOLDER_NOT_FOUND');
        }

        if (method_exists($folder, 'getRealObject')) {
            $realObject = $folder->getRealObject();
            if ($realObject instanceof Folder) {
                $folder = $realObject;
            }
        }

        return $folder;
    }

    private static function taskDefinitions($manager): array
    {
        $names = [
            'read' => $manager::TASK_READ,
            'add' => $manager::TASK_ADD,
            'edit' => $manager::TASK_EDIT,
            'full' => $manager::TASK_FULL,
        ];
        $labels = [
            'read' => 'Чтение',
            'add' => 'Добавление',
            'edit' => 'Редактирование',
            'full' => 'Полный доступ',
        ];
        $descriptions = [
            'read' => 'Просмотр и скачивание файлов',
            'add' => 'Чтение, загрузка файлов и создание папок',
            'edit' => 'Работа с содержимым без управления правами',
            'full' => 'Полный контроль, включая настройки доступа',
        ];
        $definitions = [];

        foreach ($names as $key => $taskName) {
            $taskName = (string)$taskName;
            $taskId = (int)$manager->getTaskIdByName($taskName);
            if ($taskName === '' || $taskId <= 0) {
                throw new RuntimeException('DISK_ACCESS_TASK_NOT_FOUND_' . strtoupper($key));
            }

            $definitions[$taskName] = [
                'name' => $taskName,
                'id' => $taskId,
                'key' => $key,
                'label' => $labels[$key],
                'description' => $descriptions[$key],
                'rank' => array_search($key, ['read', 'add', 'edit', 'full'], true) + 1,
            ];
        }

        return $definitions;
    }

    private static function publicTaskDefinitions(array $tasks): array
    {
        $result = [
            [
                'name' => self::INHERIT,
                'label' => 'Наследовать',
                'description' => 'Не создавать прямое правило для пользователя',
                'rank' => 0,
            ],
            [
                'name' => self::NONE,
                'label' => 'Нет доступа',
                'description' => 'Явно запретить все операции с папкой',
                'rank' => -1,
            ],
        ];

        foreach ($tasks as $task) {
            $result[] = [
                'name' => (string)$task['name'],
                'label' => (string)$task['label'],
                'description' => (string)$task['description'],
                'rank' => (int)$task['rank'],
            ];
        }

        return $result;
    }

    private static function specificRights($manager, Folder $folder): array
    {
        if (!method_exists($manager, 'getSpecificRights')) {
            throw new RuntimeException('DISK_SPECIFIC_RIGHTS_API_UNAVAILABLE');
        }

        $rawRights = $manager->getSpecificRights($folder);
        if (!is_array($rawRights)) {
            return [];
        }

        $rights = [];
        foreach ($rawRights as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $accessCode = trim((string)($row['ACCESS_CODE'] ?? ''));
            if ($accessCode === '' && is_string($key)) {
                $accessCode = trim($key);
            }
            $taskId = (int)($row['TASK_ID'] ?? 0);

            if ($accessCode === '' || $taskId <= 0) {
                continue;
            }

            $rights[] = [
                'OBJECT_ID' => (int)($row['OBJECT_ID'] ?? $folder->getId()),
                'ACCESS_CODE' => mb_strtoupper($accessCode),
                'TASK_ID' => $taskId,
                'DOMAIN' => array_key_exists('DOMAIN', $row)
                    ? ($row['DOMAIN'] === null ? null : (string)$row['DOMAIN'])
                    : null,
                'NEGATIVE' => self::boolValue($row['NEGATIVE'] ?? false),
            ];
        }

        return self::sortSpecificRights($rights);
    }

    private static function groupRightsByAccessCode(array $rights): array
    {
        $result = [];
        foreach ($rights as $right) {
            $accessCode = (string)$right['ACCESS_CODE'];
            $result[$accessCode][] = $right;
        }

        return $result;
    }

    private static function directTaskName(array $rights, array $tasks): string
    {
        if (empty($rights)) {
            return self::INHERIT;
        }

        foreach ($rights as $right) {
            if (!empty($right['NEGATIVE'])) {
                return self::NONE;
            }
        }

        $nameById = [];
        foreach ($tasks as $taskName => $task) {
            $nameById[(int)$task['id']] = [
                'name' => $taskName,
                'rank' => (int)$task['rank'],
            ];
        }

        $selected = null;
        foreach ($rights as $right) {
            $task = $nameById[(int)$right['TASK_ID']] ?? null;
            if ($task === null) {
                continue;
            }
            if ($selected === null || $task['rank'] > $selected['rank']) {
                $selected = $task;
            }
        }

        return $selected['name'] ?? self::INHERIT;
    }

    /**
     * Контроллер распознаёт только каноническое одиночное правило, которое
     * умеет сам записывать. Неизвестные/составные ручные ACL нельзя принимать
     * за inherit: иначе первая сверка могла бы их молча перезаписать.
     */
    private static function canonicalDirectTaskName(
        array $rights,
        array $tasks
    ): string {
        if (empty($rights)) {
            return self::INHERIT;
        }

        if (count($rights) !== 1) {
            return 'unknown:' . substr(hash('sha256', json_encode($rights)), 0, 16);
        }

        $right = array_values($rights)[0];
        $taskId = (int)($right['TASK_ID'] ?? 0);
        $negative = !empty($right['NEGATIVE']);

        foreach ($tasks as $taskName => $task) {
            if ((int)($task['id'] ?? 0) !== $taskId) {
                continue;
            }
            if ($negative) {
                return (string)($task['key'] ?? '') === 'full'
                    ? self::NONE
                    : 'unknown:' . substr(hash('sha256', json_encode($right)), 0, 16);
            }
            return (string)$taskName;
        }

        return 'unknown:' . substr(hash('sha256', json_encode($right)), 0, 16);
    }

    private static function effectiveCapabilities(Folder $folder, int $userId): array
    {
        if ($userId <= 0) {
            return self::emptyCapabilities();
        }

        $securityContext = new DiskSecurityContext($userId);
        $objectId = (int)$folder->getId();
        $canRead = self::contextAllows($securityContext, 'canRead', $objectId);
        $canAdd = self::contextAllows($securityContext, 'canAdd', $objectId);
        $canUpdate = self::contextAllows($securityContext, 'canUpdate', $objectId);
        $canRename = self::contextAllows($securityContext, 'canRename', $objectId)
            || $canUpdate;
        $canDelete = self::contextAllows($securityContext, 'canDelete', $objectId);
        $canShare = self::contextAllows($securityContext, 'canShare', $objectId)
            || self::contextAllows($securityContext, 'canChangeRights', $objectId)
            || self::contextAllows($securityContext, 'canChangeSettings', $objectId);

        return [
            'canView' => $canRead,
            'canUpload' => $canAdd,
            'canCreateFolder' => $canAdd,
            'canEditFile' => $canUpdate,
            'canRename' => $canRename,
            'canDelete' => $canDelete,
            'canDownload' => $canRead,
            'canShare' => $canShare,
        ];
    }

    private static function emptyCapabilities(): array
    {
        return [
            'canView' => false,
            'canUpload' => false,
            'canCreateFolder' => false,
            'canEditFile' => false,
            'canRename' => false,
            'canDelete' => false,
            'canDownload' => false,
            'canShare' => false,
        ];
    }

    private static function fullCapabilities(): array
    {
        return [
            'canView' => true,
            'canUpload' => true,
            'canCreateFolder' => true,
            'canEditFile' => true,
            'canRename' => true,
            'canDelete' => true,
            'canDownload' => true,
            'canShare' => true,
        ];
    }

    /**
     * SecurityContext принимает числовой ID объекта Диска, а не модель Folder.
     * Передача модели приводит к исключению внутри RightsManager и ко всем false.
     */
    private static function contextAllows(
        $securityContext,
        string $method,
        int $objectId
    ): bool
    {
        if ($objectId <= 0 || !method_exists($securityContext, $method)) {
            return false;
        }

        try {
            return (bool)$securityContext->{$method}($objectId);
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Disk ACL check failed: %s(%d): %s',
                $method,
                $objectId,
                $exception->getMessage()
            ));
            return false;
        }
    }

    private static function isAclProtectedPageUser(array $user): bool
    {
        if (!empty($user['isBitrixAdmin'])) {
            return true;
        }

        return in_array(
            mb_strtoupper(trim((string)($user['globalRole'] ?? ''))),
            ['ADMIN', 'OWNER'],
            true
        );
    }

    private static function effectiveTaskName(array $capabilities): string
    {
        if (!empty($capabilities['canShare'])) {
            return 'disk_access_full';
        }
        if (
            !empty($capabilities['canEditFile'])
            || !empty($capabilities['canRename'])
            || !empty($capabilities['canDelete'])
        ) {
            return 'disk_access_edit';
        }
        if (!empty($capabilities['canUpload'])) {
            return 'disk_access_add';
        }
        if (!empty($capabilities['canView'])) {
            return 'disk_access_read';
        }

        return self::NONE;
    }

    private static function normalizeRequestedRights(array $rights, array $matrix): array
    {
        $allowedTasks = [];
        foreach ((array)($matrix['tasks'] ?? []) as $task) {
            $allowedTasks[(string)($task['name'] ?? '')] = true;
        }

        $eligibleIds = [];
        $protectedIds = [];
        foreach ((array)($matrix['users'] ?? []) as $user) {
            $userId = (int)($user['userId'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            if (!empty($user['isAclProtected'])) {
                $protectedIds[$userId] = true;
                continue;
            }
            $eligibleIds[$userId] = true;
        }

        $normalized = [];
        $seenIds = [];
        foreach ($rights as $right) {
            if (!is_array($right)) {
                throw new RuntimeException('INVALID_DISK_RIGHT_ROW');
            }

            $userId = (int)($right['userId'] ?? 0);
            $taskName = trim((string)($right['taskName'] ?? ''));

            if (
                $userId <= 0
                || !isset($allowedTasks[$taskName])
                || isset($seenIds[$userId])
            ) {
                throw new RuntimeException('INVALID_DISK_RIGHT_ROW');
            }

            $seenIds[$userId] = true;
            if (isset($protectedIds[$userId])) {
                /* Старые клиенты присылали disabled-строки администраторов. */
                continue;
            }
            if (!isset($eligibleIds[$userId])) {
                throw new RuntimeException('INVALID_DISK_RIGHT_ROW');
            }
            $normalized[$userId] = $taskName;
        }

        $requestedIds = array_keys($normalized);
        $expectedIds = array_keys($eligibleIds);
        sort($requestedIds, SORT_NUMERIC);
        sort($expectedIds, SORT_NUMERIC);

        if ($requestedIds !== $expectedIds) {
            throw new DiskRightsVersionConflictException(
                (string)($matrix['rightsRevision'] ?? '')
            );
        }

        ksort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    /** @return array<int,string> */
    private static function normalizeDirectUserTaskMap(
        array $rights,
        array $tasks
    ): array {
        $allowed = [
            self::INHERIT => true,
            self::NONE => true,
        ];
        foreach ($tasks as $taskName => $task) {
            $allowed[(string)$taskName] = true;
        }

        $normalized = [];
        foreach ($rights as $userId => $taskName) {
            $userId = (int)$userId;
            $taskName = trim((string)$taskName);
            if (
                $userId <= 0
                || !isset($allowed[$taskName])
                || isset($normalized[$userId])
            ) {
                throw new RuntimeException('INVALID_DISK_RIGHT_ROW');
            }
            $normalized[$userId] = $taskName;
        }

        ksort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    /**
     * Удаляет выбранные прямые U-строки из полного набора ACL. Положительные
     * права будут добавлены через Sharing::add() с настоящим sharing-domain,
     * чтобы штатное окно прав Битрикс.Диска видело тех же пользователей.
     * Группы, DOMAIN и чужие U-коды остаются без изменений.
     */
    private static function replaceSpecificUserRights(
        array $specificRights,
        array $requestedRights
    ): array {
        $targetAccessCodes = [];
        foreach (array_keys($requestedRights) as $userId) {
            $targetAccessCodes['U' . (int)$userId] = true;
        }

        $replacement = [];
        foreach ($specificRights as $right) {
            $accessCode = (string)($right['ACCESS_CODE'] ?? '');
            if (!isset($targetAccessCodes[$accessCode])) {
                $replacement[] = $right;
            }
        }

        return self::sortSpecificRights($replacement);
    }

    /**
     * set() сначала удаляет старые управляемые U-строки. Затем положительные
     * права создаются штатной моделью Sharing, поэтому появляются и в ACL, и в
     * списке «Поделиться» корпоративного портала. `none` дополнительно
     * применяется через revokeByAccessCodes(), чтобы перекрыть наследование.
     */
    private static function applyRequestedRights(
        $manager,
        Folder $folder,
        array $replacement,
        array $requestedRights,
        int $actorUserId
    ): void {
        if (!method_exists($manager, 'set')) {
            throw new RuntimeException('DISK_RIGHTS_SET_API_UNAVAILABLE');
        }

        $positiveRights = [];
        $negativeRights = [];
        foreach ($replacement as $right) {
            if (!empty($right['NEGATIVE'])) {
                $negativeRights[] = $right;
            } else {
                $positiveRights[] = $right;
            }
        }

        $positiveRights = self::sortSpecificRights($positiveRights);
        $negativeRights = self::uniqueSpecificRights($negativeRights);

        self::assertManagerResult(
            $manager->set($folder, $positiveRights),
            'DISK_RIGHTS_SET_FAILED'
        );

        if (!empty($negativeRights)) {
            if (!method_exists($manager, 'append')) {
                throw new RuntimeException('DISK_RIGHTS_APPEND_API_UNAVAILABLE');
            }

            self::assertManagerResult(
                $manager->append($folder, $negativeRights),
                'DISK_RIGHTS_APPEND_FAILED'
            );
        }

        self::syncNativeUserSharings(
            $manager,
            $folder,
            $requestedRights,
            $actorUserId
        );

        $revokedAccessCodes = [];
        foreach ($requestedRights as $userId => $taskName) {
            if ((string)$taskName === self::NONE && (int)$userId > 0) {
                $revokedAccessCodes[] = 'U' . (int)$userId;
            }
        }
        $revokedAccessCodes = array_values(array_unique($revokedAccessCodes));

        if (empty($revokedAccessCodes)) {
            return;
        }
        if (!method_exists($manager, 'revokeByAccessCodes')) {
            throw new RuntimeException('DISK_RIGHTS_REVOKE_API_UNAVAILABLE');
        }

        self::assertManagerResult(
            $manager->revokeByAccessCodes($folder, $revokedAccessCodes),
            'DISK_RIGHTS_REVOKE_FAILED'
        );
    }

    /**
     * @param array<int,string> $requestedRights userId => taskName
     */
    private static function syncNativeUserSharings(
        $manager,
        Folder $folder,
        array $requestedRights,
        int $actorUserId
    ): void {
        self::assertNativeSharingApiAvailable();

        $existing = self::nativeUserSharings(
            $folder,
            array_keys($requestedRights)
        );

        foreach ($requestedRights as $userId => $taskName) {
            $userId = (int)$userId;
            $taskName = (string)$taskName;
            $accessCode = 'U' . $userId;
            $rows = array_values($existing[$accessCode] ?? []);

            if (in_array($taskName, [self::INHERIT, self::NONE], true)) {
                foreach ($rows as $row) {
                    self::deleteNativeSharing($row, $actorUserId);
                }
                continue;
            }

            if (!$manager->isValidTaskName($taskName)) {
                throw new RuntimeException('INVALID_DISK_ACCESS_TASK');
            }

            if (empty($rows)) {
                self::createNativeSharing(
                    $folder,
                    $accessCode,
                    $taskName,
                    $actorUserId
                );
                continue;
            }

            $primary = array_shift($rows);
            self::updateNativeSharingTask(
                $manager,
                $folder,
                $primary,
                $taskName
            );

            /* Старые дубли дают несколько строк одному пользователю в портале. */
            foreach ($rows as $duplicate) {
                self::deleteNativeSharing($duplicate, $actorUserId);
            }
        }

        $driver = Driver::getInstance();
        if (method_exists($driver, 'getTrackedObjectManager')) {
            $trackedObjectManager = $driver->getTrackedObjectManager();
            if (is_object($trackedObjectManager) && method_exists($trackedObjectManager, 'refresh')) {
                $trackedObjectManager->refresh($folder);
            }
        }
    }

    private static function createNativeSharing(
        Folder $folder,
        string $accessCode,
        string $taskName,
        int $actorUserId
    ): Sharing {
        $errors = new ErrorCollection();
        $sharing = Sharing::add([
            'FROM_ENTITY' => Sharing::CODE_USER . $actorUserId,
            'REAL_OBJECT' => $folder,
            'CREATED_BY' => $actorUserId,
            'CAN_FORWARD' => false,
            'TO_ENTITY' => $accessCode,
            'TASK_NAME' => $taskName,
        ], $errors);

        if (!$sharing instanceof Sharing || (int)$sharing->getId() <= 0) {
            throw new RuntimeException(
                'DISK_NATIVE_SHARING_CREATE_FAILED'
                . self::errorDetails($errors)
            );
        }

        return $sharing;
    }

    private static function updateNativeSharingTask(
        $manager,
        Folder $folder,
        array $row,
        string $taskName
    ): void {
        $sharingId = (int)($row['ID'] ?? 0);
        $accessCode = mb_strtoupper(trim((string)($row['TO_ENTITY'] ?? '')));
        if ($sharingId <= 0 || !preg_match('/^U[1-9]\d*$/', $accessCode)) {
            throw new RuntimeException('DISK_NATIVE_SHARING_INVALID');
        }

        $domain = $manager->getSharingDomain($sharingId);
        self::assertManagerResult(
            $manager->deleteByDomain($folder, $domain),
            'DISK_NATIVE_SHARING_RIGHT_DELETE_FAILED'
        );

        $updateResult = SharingTable::update($sharingId, [
            'TASK_NAME' => $taskName,
        ]);
        self::assertManagerResult(
            $updateResult,
            'DISK_NATIVE_SHARING_UPDATE_FAILED'
        );

        self::assertManagerResult(
            $manager->append($folder, [[
                'ACCESS_CODE' => $accessCode,
                'TASK_ID' => (int)$manager->getTaskIdByName($taskName),
                'DOMAIN' => $domain,
            ]]),
            'DISK_NATIVE_SHARING_RIGHT_APPEND_FAILED'
        );
    }

    private static function deleteNativeSharing(
        array $row,
        int $actorUserId
    ): void {
        $sharingId = (int)($row['ID'] ?? 0);
        $sharing = $sharingId > 0 ? Sharing::loadById($sharingId) : null;
        if (!$sharing instanceof Sharing) {
            throw new RuntimeException('DISK_NATIVE_SHARING_NOT_FOUND');
        }

        if (!$sharing->delete($actorUserId)) {
            throw new RuntimeException(
                'DISK_NATIVE_SHARING_DELETE_FAILED'
                . self::errorDetails($sharing)
            );
        }
    }

    /**
     * @param array<int,int|string> $userIds
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function nativeUserSharings(
        Folder $folder,
        array $userIds
    ): array {
        self::assertNativeSharingApiAvailable();

        $wanted = [];
        foreach ($userIds as $userId) {
            $userId = (int)$userId;
            if ($userId > 0) {
                $wanted['U' . $userId] = true;
            }
        }
        if (empty($wanted)) {
            return [];
        }

        $query = Sharing::getList([
            'filter' => [
                'REAL_OBJECT_ID' => (int)$folder->getRealObjectId(),
                'REAL_STORAGE_ID' => (int)$folder->getStorageId(),
                '!=STATUS' => SharingTable::STATUS_IS_DECLINED,
                'PARENT_ID' => null,
            ],
        ]);

        $result = [];
        while ($row = $query->fetch()) {
            $accessCode = mb_strtoupper(trim((string)($row['TO_ENTITY'] ?? '')));
            if (!isset($wanted[$accessCode])) {
                continue;
            }
            $result[$accessCode][] = $row;
        }

        foreach ($result as &$rows) {
            usort($rows, static function (array $left, array $right): int {
                return (int)($left['ID'] ?? 0) <=> (int)($right['ID'] ?? 0);
            });
        }
        unset($rows);

        return $result;
    }

    private static function assertNativeSharingApiAvailable(): void
    {
        if (
            !class_exists(Sharing::class)
            || !class_exists(SharingTable::class)
            || !class_exists(ErrorCollection::class)
            || !method_exists(Sharing::class, 'add')
            || !method_exists(Sharing::class, 'getList')
        ) {
            throw new RuntimeException('DISK_NATIVE_SHARING_API_UNAVAILABLE');
        }
    }

    private static function errorDetails($source): string
    {
        $errors = [];
        if (is_object($source) && method_exists($source, 'getErrors')) {
            $errors = (array)$source->getErrors();
        } elseif (is_object($source) && method_exists($source, 'toArray')) {
            $errors = (array)$source->toArray();
        }

        $messages = [];
        foreach ($errors as $error) {
            if (is_object($error) && method_exists($error, 'getMessage')) {
                $messages[] = trim((string)$error->getMessage());
            } elseif (is_array($error) && isset($error['message'])) {
                $messages[] = trim((string)$error['message']);
            } elseif (is_scalar($error)) {
                $messages[] = trim((string)$error);
            }
        }
        $messages = array_values(array_filter(array_unique($messages)));

        return empty($messages) ? '' : ': ' . implode('; ', $messages);
    }

    /**
     * Убирает точные дубли перед append(), не объединяя разные DOMAIN/TASK_ID.
     */
    private static function uniqueSpecificRights(array $rights): array
    {
        $unique = [];
        foreach (self::sortSpecificRights($rights) as $right) {
            $key = implode('|', [
                (string)($right['ACCESS_CODE'] ?? ''),
                (int)($right['TASK_ID'] ?? 0),
                !empty($right['NEGATIVE']) ? '1' : '0',
                (string)($right['DOMAIN'] ?? ''),
            ]);
            $unique[$key] = $right;
        }

        return array_values($unique);
    }

    /**
     * revokeByAccessCodes() может создать несколько отрицательных строк вместо
     * одного TASK_FULL. Для контроллера это всё равно каноническое `none`, если
     * прямой набор содержит запрет и SecurityContext реально не даёт чтение.
     */
    private static function semanticDirectTaskName(
        Folder $folder,
        int $userId,
        array $rights,
        array $tasks
    ): string {
        $canonical = self::canonicalDirectTaskName($rights, $tasks);
        $hasNegative = false;
        foreach ($rights as $right) {
            if (!empty($right['NEGATIVE'])) {
                $hasNegative = true;
                break;
            }
        }

        if ($hasNegative && !self::userCanReadStrict($folder, $userId)) {
            return self::NONE;
        }

        if ($hasNegative && $canonical === self::NONE) {
            return 'unknown:' . substr(hash('sha256', json_encode($rights)), 0, 16);
        }

        return $canonical;
    }

    /** @return array<int,string> Фактические прямые права после set()/append(). */
    private static function assertRequestedRightsWritten(
        $manager,
        Folder $folder,
        array $requestedRights,
        array $tasks
    ): array {
        $writtenByAccessCode = self::groupRightsByAccessCode(
            self::specificRights($manager, $folder)
        );
        $nativeByAccessCode = self::nativeUserSharings(
            $folder,
            array_keys($requestedRights)
        );
        $externalRights = [];
        foreach ($requestedRights as $userId => $taskName) {
            $accessCode = 'U' . (int)$userId;
            $nativeRows = array_values($nativeByAccessCode[$accessCode] ?? []);
            $writtenTaskName = self::canonicalDirectTaskName(
                $writtenByAccessCode[$accessCode] ?? [],
                $tasks
            );
            $externalRights[(int)$userId] = $writtenTaskName;

            if ((string)$taskName === self::NONE) {
                if (!empty($nativeRows)) {
                    throw new RuntimeException(
                        'DISK_NATIVE_SHARING_DELETE_VERIFICATION_FAILED: '
                        . $accessCode
                    );
                }
                $canRead = self::userCanReadStrict($folder, (int)$userId);
                if (!$canRead) {
                    $externalRights[(int)$userId] = self::NONE;
                    continue;
                }

                throw new RuntimeException(
                    'DISK_RIGHTS_EFFECTIVE_VERIFICATION_FAILED: '
                    . $accessCode
                    . '; direct=' . $writtenTaskName
                    . '; canRead=1'
                );
            }

            if ((string)$taskName === self::INHERIT) {
                if (!empty($nativeRows)) {
                    throw new RuntimeException(
                        'DISK_NATIVE_SHARING_DELETE_VERIFICATION_FAILED: '
                        . $accessCode
                    );
                }
            } else {
                self::assertNativeSharingWritten(
                    $manager,
                    $accessCode,
                    (string)$taskName,
                    $nativeRows,
                    $writtenByAccessCode[$accessCode] ?? []
                );
            }

            if (!hash_equals((string)$taskName, $writtenTaskName)) {
                throw new RuntimeException(
                    'DISK_RIGHTS_WRITE_VERIFICATION_FAILED: ' . $accessCode
                );
            }
        }

        return $externalRights;
    }

    private static function assertNativeSharingWritten(
        $manager,
        string $accessCode,
        string $taskName,
        array $nativeRows,
        array $directRights
    ): void {
        if (self::nativeSharingIsWritten(
            $manager,
            $accessCode,
            $taskName,
            $nativeRows,
            $directRights
        )) {
            return;
        }

        throw new RuntimeException(
            'DISK_NATIVE_SHARING_WRITE_VERIFICATION_FAILED: ' . $accessCode
        );
    }

    private static function nativeSharingIsWritten(
        $manager,
        string $accessCode,
        string $taskName,
        array $nativeRows,
        array $directRights
    ): bool {
        $taskId = (int)$manager->getTaskIdByName($taskName);
        foreach ($nativeRows as $row) {
            if ((string)($row['TASK_NAME'] ?? '') !== $taskName) {
                continue;
            }

            $sharingId = (int)($row['ID'] ?? 0);
            if ($sharingId <= 0) {
                continue;
            }
            $domain = (string)$manager->getSharingDomain($sharingId);

            foreach ($directRights as $right) {
                if (
                    mb_strtoupper((string)($right['ACCESS_CODE'] ?? '')) === $accessCode
                    && (int)($right['TASK_ID'] ?? 0) === $taskId
                    && (string)($right['DOMAIN'] ?? '') === $domain
                    && empty($right['NEGATIVE'])
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Строгая проверка для подтверждения запрета. В отличие от отображения
     * матрицы, исключение Bitrix здесь нельзя превращать в false: иначе сбой
     * SecurityContext выглядел бы как успешно применённое `Нет доступа`.
     */
    private static function userCanReadStrict(
        Folder $folder,
        int $userId
    ): bool {
        if ($userId <= 0) {
            throw new RuntimeException('DISK_RIGHTS_EFFECTIVE_VERIFICATION_FAILED');
        }

        $securityContext = new DiskSecurityContext($userId);
        if (!method_exists($securityContext, 'canRead')) {
            throw new RuntimeException('DISK_RIGHTS_EFFECTIVE_VERIFICATION_FAILED');
        }

        try {
            return (bool)$securityContext->canRead((int)$folder->getId());
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'DISK_RIGHTS_EFFECTIVE_VERIFICATION_FAILED: U' . $userId,
                0,
                $exception
            );
        }
    }

    private static function sortSpecificRights(array $rights): array
    {
        usort($rights, static function (array $left, array $right): int {
            return [
                (string)($left['ACCESS_CODE'] ?? ''),
                (int)($left['TASK_ID'] ?? 0),
                (int)!empty($left['NEGATIVE']),
                (string)($left['DOMAIN'] ?? ''),
            ] <=> [
                (string)($right['ACCESS_CODE'] ?? ''),
                (int)($right['TASK_ID'] ?? 0),
                (int)!empty($right['NEGATIVE']),
                (string)($right['DOMAIN'] ?? ''),
            ];
        });
        return array_values($rights);
    }

    private static function revision(
        int $folderId,
        array $users,
        array $specificRights,
        array $managedBindings = []
    ): string {
        $userIds = [];
        foreach ($users as $user) {
            $userIds[] = (int)($user['userId'] ?? 0);
        }
        sort($userIds, SORT_NUMERIC);

        return hash('sha256', json_encode([
            'folderId' => $folderId,
            'userIds' => $userIds,
            'specificRights' => $specificRights,
            'managedBindings' => $managedBindings,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function restoreRightsSet(
        $manager,
        Folder $folder,
        array $backup
    ): void
    {
        try {
            if (!method_exists($manager, 'set')) {
                throw new RuntimeException('DISK_RIGHTS_SET_API_UNAVAILABLE');
            }

            $positiveRights = [];
            $negativeRights = [];
            foreach ($backup as $right) {
                if (!empty($right['NEGATIVE'])) {
                    $negativeRights[] = $right;
                } else {
                    $positiveRights[] = $right;
                }
            }

            self::assertManagerResult(
                $manager->set($folder, self::sortSpecificRights($positiveRights)),
                'DISK_RIGHTS_ROLLBACK_FAILED'
            );

            if (!empty($negativeRights)) {
                if (!method_exists($manager, 'append')) {
                    throw new RuntimeException(
                        'DISK_RIGHTS_ROLLBACK_APPEND_API_UNAVAILABLE'
                    );
                }
                self::assertManagerResult(
                    $manager->append(
                        $folder,
                        self::uniqueSpecificRights($negativeRights)
                    ),
                    'DISK_RIGHTS_ROLLBACK_APPEND_FAILED'
                );
            }
        } catch (Throwable $exception) {
            error_log('Disk ACL rollback failed: ' . $exception->getMessage());
        }
    }

    /**
     * Восстанавливает метаданные Sharing после отката ACL. Этот путь нужен
     * только при исключении, поэтому ошибки восстановления пишутся в журнал и
     * не скрывают исходную причину отказа сохранения.
     *
     * @param array<string,array<int,array<string,mixed>>> $backup
     * @param array<int,int|string> $userIds
     */
    private static function restoreNativeUserSharings(
        $manager,
        Folder $folder,
        array $backup,
        array $userIds,
        int $actorUserId
    ): void {
        try {
            $current = self::nativeUserSharings($folder, $userIds);
            $accessCodes = [];
            foreach ($userIds as $userId) {
                $userId = (int)$userId;
                if ($userId > 0) {
                    $accessCodes['U' . $userId] = true;
                }
            }

            foreach (array_keys($accessCodes) as $accessCode) {
                $backupRows = array_values($backup[$accessCode] ?? []);
                $currentRows = array_values($current[$accessCode] ?? []);
                $backupById = [];
                $currentById = [];

                foreach ($backupRows as $row) {
                    $backupById[(int)($row['ID'] ?? 0)] = $row;
                }
                foreach ($currentRows as $row) {
                    $currentById[(int)($row['ID'] ?? 0)] = $row;
                }

                foreach ($currentById as $sharingId => $row) {
                    if (!isset($backupById[$sharingId])) {
                        self::deleteNativeSharing($row, $actorUserId);
                    }
                }

                foreach ($backupById as $sharingId => $row) {
                    if (isset($currentById[$sharingId])) {
                        if (
                            (string)($currentById[$sharingId]['TASK_NAME'] ?? '')
                            !== (string)($row['TASK_NAME'] ?? '')
                        ) {
                            self::assertManagerResult(
                                SharingTable::update($sharingId, [
                                    'TASK_NAME' => (string)($row['TASK_NAME'] ?? ''),
                                ]),
                                'DISK_NATIVE_SHARING_ROLLBACK_UPDATE_FAILED'
                            );
                        }
                        continue;
                    }

                    /* restoreRightsSet() вернул старый DOMAIN уже удалённой записи. */
                    self::assertManagerResult(
                        $manager->deleteByDomain(
                            $folder,
                            $manager->getSharingDomain($sharingId)
                        ),
                        'DISK_NATIVE_SHARING_ROLLBACK_DOMAIN_DELETE_FAILED'
                    );

                    $errors = new ErrorCollection();
                    $sharing = Sharing::add([
                        'FROM_ENTITY' => (string)($row['FROM_ENTITY'] ?? ('U' . $actorUserId)),
                        'REAL_OBJECT' => $folder,
                        'CREATED_BY' => (int)($row['CREATED_BY'] ?? $actorUserId),
                        'CAN_FORWARD' => !empty($row['CAN_FORWARD']),
                        'TO_ENTITY' => $accessCode,
                        'TASK_NAME' => (string)($row['TASK_NAME'] ?? ''),
                        'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
                    ], $errors);

                    if (!$sharing instanceof Sharing) {
                        throw new RuntimeException(
                            'DISK_NATIVE_SHARING_ROLLBACK_CREATE_FAILED'
                            . self::errorDetails($errors)
                        );
                    }

                    if (
                        (int)($row['STATUS'] ?? 0) === SharingTable::STATUS_IS_APPROVED
                        && method_exists($sharing, 'isApproved')
                        && !$sharing->isApproved()
                        && !$sharing->approve()
                    ) {
                        throw new RuntimeException(
                            'DISK_NATIVE_SHARING_ROLLBACK_APPROVE_FAILED'
                            . self::errorDetails($sharing)
                        );
                    }
                }
            }
        } catch (Throwable $exception) {
            error_log(
                'Disk native sharing rollback failed: ' . $exception->getMessage()
            );
        }
    }

    private static function assertManagerResult($result, string $errorCode): void
    {
        if ($result === false) {
            throw new RuntimeException($errorCode);
        }

        if (
            is_object($result)
            && method_exists($result, 'isSuccess')
            && !$result->isSuccess()
        ) {
            $messages = [];
            if (method_exists($result, 'getErrorMessages')) {
                $messages = (array)$result->getErrorMessages();
            }

            throw new RuntimeException(
                $errorCode . (empty($messages) ? '' : ': ' . implode('; ', $messages))
            );
        }
    }

    private static function acquireLock(int $folderId)
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'sitebuilder_disk_rights_'
            . $folderId
            . '.lock';
        $handle = @fopen($path, 'c');

        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('DISK_RIGHTS_LOCK_FAILED');
        }

        return $handle;
    }

    private static function releaseLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private static function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            mb_strtolower(trim((string)$value)),
            ['1', 'true', 't', 'yes', 'y', 'on'],
            true
        );
    }
}

<?php

declare(strict_types=1);

use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\Application;

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

            $pageUser['accessCode'] = $accessCode;
            $pageUser['isCurrentUser'] = $userId === $context->currentUserId;
            $pageUser['isAclProtected'] = $isAclProtected;
            $pageUser['directTaskName'] = self::directTaskName(
                $directRows,
                $tasks
            );
            $pageUser['effectiveTaskName'] = self::effectiveTaskName(
                $capabilities
            );
            $pageUser['effectiveCapabilities'] = $capabilities;
            $pageUser['rightSource'] = $isAclProtected
                ? 'system_admin'
                : (empty($directRows) ? 'inherited' : 'direct');

            $users[] = $pageUser;
        }

        return [
            'folderId' => $folderId,
            'tasks' => self::publicTaskDefinitions($tasks),
            'users' => $users,
            'rightsRevision' => self::revision(
                $folderId,
                $users,
                $specificRights
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
        $connection = null;
        $transactionStarted = false;

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
            $directByAccessCode = self::groupRightsByAccessCode($specificRights);
            $backup = [];

            try {
                $connection = Application::getConnection();
                if (method_exists($connection, 'startTransaction')) {
                    $connection->startTransaction();
                    $transactionStarted = true;
                }
            } catch (Throwable $exception) {
                $connection = null;
                $transactionStarted = false;
            }

            try {
                foreach ($normalized as $userId => $taskName) {
                    $accessCode = 'U' . $userId;
                    $backup[$accessCode] = $directByAccessCode[$accessCode] ?? [];

                    self::assertManagerResult(
                        $manager->revokeByAccessCodes($folder, [$accessCode]),
                        'DISK_RIGHTS_REVOKE_FAILED'
                    );

                    $right = self::rightForTask(
                        $accessCode,
                        $taskName,
                        $tasks
                    );

                    if ($right !== null) {
                        self::assertManagerResult(
                            $manager->append($folder, [$right]),
                            'DISK_RIGHTS_APPEND_FAILED'
                        );
                    }
                }

                /*
                 * RightsManager может вернуть успешный результат, даже если
                 * конкретная версия модуля не сохранила ACL в ожидаемом виде.
                 * Не подтверждаем сохранение, пока не прочитаем правило обратно.
                 */
                $writtenByAccessCode = self::groupRightsByAccessCode(
                    self::specificRights($manager, $folder)
                );
                foreach ($normalized as $userId => $taskName) {
                    $accessCode = 'U' . $userId;
                    $writtenTaskName = self::canonicalDirectTaskName(
                        $writtenByAccessCode[$accessCode] ?? [],
                        $tasks
                    );
                    if (!hash_equals($taskName, $writtenTaskName)) {
                        throw new RuntimeException(
                            'DISK_RIGHTS_WRITE_VERIFICATION_FAILED: ' . $accessCode
                        );
                    }
                }

                if ($transactionStarted && $connection !== null) {
                    $connection->commitTransaction();
                    $transactionStarted = false;
                }
            } catch (Throwable $exception) {
                if ($transactionStarted && $connection !== null) {
                    try {
                        $connection->rollbackTransaction();
                    } catch (Throwable $rollbackException) {
                        error_log('Disk ACL transaction rollback failed: ' . $rollbackException->getMessage());
                    }
                    $transactionStarted = false;
                }

                /*
                 * Повторное восстановление через публичный RightsManager
                 * синхронизирует и БД, и управляемый кеш ACL.
                 */
                self::restoreRights($manager, $folder, $backup);

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
    public static function getDirectUserRightsSnapshot(int $folderId): array
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

            $result[(int)$matches[1]] = self::canonicalDirectTaskName(
                $rights,
                $tasks
            );
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
     */
    public static function replaceDirectUserRights(
        int $folderId,
        array $requestedRights,
        array $expectedRights
    ): array {
        if (count($requestedRights) > self::MAX_USERS) {
            throw new RuntimeException('TOO_MANY_DISK_RIGHTS');
        }

        $lock = self::acquireLock($folderId);
        $connection = null;
        $transactionStarted = false;

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
            $current = [];

            foreach ($directByAccessCode as $accessCode => $rights) {
                if (!preg_match('/^U([1-9]\d*)$/', $accessCode, $matches)) {
                    continue;
                }
                $current[(int)$matches[1]] = self::canonicalDirectTaskName(
                    $rights,
                    $tasks
                );
            }

            foreach ($expectedRights as $userId => $expectedTask) {
                $currentTask = $current[$userId] ?? self::INHERIT;
                if (!hash_equals($expectedTask, $currentTask)) {
                    throw new DiskRightsVersionConflictException(
                        hash('sha256', json_encode($current))
                    );
                }
            }

            $changes = [];
            foreach ($requestedRights as $userId => $taskName) {
                if (($current[$userId] ?? self::INHERIT) !== $taskName) {
                    $changes[$userId] = $taskName;
                }
            }

            if (empty($changes)) {
                ksort($current, SORT_NUMERIC);
                return [
                    'folderId' => $folderId,
                    'changedUserIds' => [],
                    'before' => $current,
                    'after' => $current,
                ];
            }

            $backup = [];
            try {
                $connection = Application::getConnection();
                if (method_exists($connection, 'startTransaction')) {
                    $connection->startTransaction();
                    $transactionStarted = true;
                }
            } catch (Throwable $exception) {
                $connection = null;
                $transactionStarted = false;
            }

            try {
                foreach ($changes as $userId => $taskName) {
                    $accessCode = 'U' . $userId;
                    $backup[$accessCode] = $directByAccessCode[$accessCode] ?? [];

                    self::assertManagerResult(
                        $manager->revokeByAccessCodes($folder, [$accessCode]),
                        'DISK_RIGHTS_REVOKE_FAILED'
                    );

                    $right = self::rightForTask($accessCode, $taskName, $tasks);
                    if ($right !== null) {
                        self::assertManagerResult(
                            $manager->append($folder, [$right]),
                            'DISK_RIGHTS_APPEND_FAILED'
                        );
                    }
                }

                if ($transactionStarted && $connection !== null) {
                    $connection->commitTransaction();
                    $transactionStarted = false;
                }
            } catch (Throwable $exception) {
                if ($transactionStarted && $connection !== null) {
                    try {
                        $connection->rollbackTransaction();
                    } catch (Throwable $rollbackException) {
                        error_log('Disk ACL transaction rollback failed: ' . $rollbackException->getMessage());
                    }
                    $transactionStarted = false;
                }

                self::restoreRights($manager, $folder, $backup);
                throw $exception;
            }

            $after = self::getDirectUserRightsSnapshot($folderId);
            foreach ($changes as $userId => $taskName) {
                if (($after[$userId] ?? self::INHERIT) !== $taskName) {
                    throw new DiskRightsVersionConflictException(
                        hash('sha256', json_encode($after))
                    );
                }
            }
            return [
                'folderId' => $folderId,
                'changedUserIds' => array_map('intval', array_keys($changes)),
                'before' => $current,
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
                'ACCESS_CODE' => mb_strtoupper($accessCode),
                'TASK_ID' => $taskId,
                'NEGATIVE' => self::boolValue($row['NEGATIVE'] ?? false),
            ];
        }

        usort($rights, static function (array $left, array $right): int {
            return [$left['ACCESS_CODE'], $left['TASK_ID'], (int)$left['NEGATIVE']]
                <=> [$right['ACCESS_CODE'], $right['TASK_ID'], (int)$right['NEGATIVE']];
        });

        return $rights;
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

    private static function rightForTask(
        string $accessCode,
        string $taskName,
        array $tasks
    ): ?array {
        if ($taskName === self::INHERIT) {
            return null;
        }

        if ($taskName === self::NONE) {
            foreach ($tasks as $task) {
                if (($task['key'] ?? '') === 'full') {
                    return [
                        'ACCESS_CODE' => $accessCode,
                        'TASK_ID' => (int)$task['id'],
                        'NEGATIVE' => true,
                    ];
                }
            }
        }

        if (!isset($tasks[$taskName])) {
            throw new RuntimeException('INVALID_DISK_ACCESS_TASK');
        }

        return [
            'ACCESS_CODE' => $accessCode,
            'TASK_ID' => (int)$tasks[$taskName]['id'],
            'NEGATIVE' => false,
        ];
    }

    private static function revision(
        int $folderId,
        array $users,
        array $specificRights
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
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function restoreRights($manager, Folder $folder, array $backup): void
    {
        foreach ($backup as $accessCode => $rights) {
            try {
                $manager->revokeByAccessCodes($folder, [$accessCode]);
                if (!empty($rights)) {
                    $manager->append($folder, array_values($rights));
                }
            } catch (Throwable $exception) {
                error_log(sprintf(
                    'Disk ACL rollback failed for %s: %s',
                    $accessCode,
                    $exception->getMessage()
                ));
            }
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

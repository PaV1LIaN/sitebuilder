<?php

/*
 * Keep deletion isolated in its existing action: no extra bootstrap dependency
 * and no changes to auth, ACL tables or block settings during a delete request.
 */
$sbDeleteBufferLevel = ob_get_level();
ob_start();
$sbDeleteResponse = (static function (): array {
    $stage = 'authorization';
    $userId = 0;
    $context = null;
    $rootFolderId = null;
    $failedItem = null;
    $object = null;
    $permissions = [];
    $deleted = [];
    $alreadyDeleted = [];
    $mutationAttempted = false;
    $name = '';

    $loadObject = static function (array $item) {
        $object = $item['entityType'] === 'folder'
            ? \Bitrix\Disk\Folder::loadById($item['id'])
            : \Bitrix\Disk\File::loadById($item['id']);
        if (!$object) {
            throw new RuntimeException('DISK_ITEM_NOT_FOUND');
        }
        return $object;
    };

    try {
        $userId = DiskCurrentUser::requireId();
        $stage = 'csrf';
        DiskCsrf::validateFromRequest();
        $stage = 'request';
        $data = disk_read_json_body();
        $context = DiskContextFactory::fromArray([
            'siteId' => (int)($data['siteId'] ?? 0),
            'pageId' => (int)($data['pageId'] ?? 0),
            'blockId' => (int)($data['blockId'] ?? 0),
            'currentUserId' => $userId,
        ]);
        $stage = 'context';
        DiskValidator::assertContext($context);

        $stage = 'items';
        $input = $data['items'] ?? [];
        if (!is_array($input) || !$input) {
            throw new RuntimeException('EMPTY_ITEMS');
        }
        $items = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('INVALID_ITEM');
            }
            $type = $item['entityType'] ?? null;
            $id = $item['id'] ?? null;
            if (!is_string($type) || !in_array(trim($type), ['file', 'folder'], true)) {
                throw new RuntimeException('INVALID_ENTITY_TYPE');
            }
            $id = (is_int($id) || is_string($id))
                ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($id === false) {
                throw new RuntimeException('INVALID_ENTITY_ID');
            }
            $type = trim($type);
            // Validate and execute the same canonical IDs; discard duplicates.
            $items[$type . ':' . $id] = ['entityType' => $type, 'id' => $id];
        }

        $stage = 'settings';
        // Deletion must not save normalized props or conflict with block edits.
        $settings = DiskSettingsRepository::getByBlockId($context->blockId);
        $stage = 'root';
        $rootFolderId = DiskRootResolver::resolve($context, $settings);
        if (!$rootFolderId || $rootFolderId <= 0) {
            throw new RuntimeException('ROOT_FOLDER_NOT_RESOLVED');
        }

        // Preflight the WHOLE batch before the first mutation. Keep the existing
        // Sitebuilder scope and parent-folder permission policy (no ACL bypass).
        foreach ($items as $item) {
            $failedItem = $item;
            $permissions = [];
            $stage = 'scope';
            DiskValidator::assertItemInsideRoot($item['entityType'], $item['id'], $rootFolderId, $context);
            $stage = 'permissions';
            $parentId = DiskValidator::itemParentFolderId($item['entityType'], $item['id']);
            $permissions = DiskValidator::permissionsForFolder($context, $settings, $parentId, $rootFolderId);
            DiskValidator::assertCan($permissions, 'canDelete');
        }

        foreach ($items as $item) {
            $failedItem = $item;
            $object = null;
            $name = '';
            $permissions = [];
            $mutationAttempted = false;
            // Recheck scope/permissions if another request moved the object or
            // changed its rights after preflight. Never trust client permissions.
            $stage = 'scope';
            DiskValidator::assertItemInsideRoot($item['entityType'], $item['id'], $rootFolderId, $context);
            $stage = 'permissions';
            $parentId = DiskValidator::itemParentFolderId($item['entityType'], $item['id']);
            $permissions = DiskValidator::permissionsForFolder($context, $settings, $parentId, $rootFolderId);
            DiskValidator::assertCan($permissions, 'canDelete');
            $stage = 'load';
            $object = $loadObject($item);
            $name = (string)$object->getName();
            if ($object->isDeleted()) {
                $alreadyDeleted[] = $item;
                continue;
            }

            $stage = 'bitrix_mark_deleted';
            $mutationAttempted = true;
            // Use the supported trash operation, NEVER permanent delete/SQL.
            if ($object->markDeleted($userId) !== true) {
                throw new RuntimeException('BITRIX_DELETE_REJECTED');
            }
            $deleted[] = $item;
        }

        return [
            'ok' => true,
            'data' => ['result' => array_merge($deleted, $alreadyDeleted)],
            'meta' => ['deletedCount' => count($deleted), 'alreadyDeleted' => $alreadyDeleted],
        ];
    } catch (Throwable $error) {
        // Every diagnostic probe is best-effort; never mask the original error.
        try { $diagnosticId = 'SD-' . bin2hex(random_bytes(8)); }
        catch (Throwable $ignored) { $diagnosticId = 'SD-' . str_replace('.', '', uniqid('', true)); }
        $isAdmin = false;
        $groupIds = [];
        $userName = '';
        $bitrixErrors = [];
        $currentItemState = 'not_attempted';
        try { $isAdmin = DiskCurrentUser::isAdmin(); } catch (Throwable $ignored) {}
        try { $groupIds = DiskCurrentUser::getGroupIds(); } catch (Throwable $ignored) {}
        try {
            if ($userId > 0 && class_exists('CUser')) {
                $row = \CUser::GetByID($userId)->Fetch();
                if (is_array($row)) {
                    $userName = trim(implode(' ', [
                        $row['LAST_NAME'] ?? '', $row['NAME'] ?? '', $row['SECOND_NAME'] ?? '',
                    ]));
                }
            }
        } catch (Throwable $ignored) {}
        try {
            if ($object && method_exists($object, 'getErrors')) {
                foreach ($object->getErrors() as $nativeError) {
                    if (is_object($nativeError) && method_exists($nativeError, 'getMessage')) {
                        $bitrixErrors[] = [
                            'code' => method_exists($nativeError, 'getCode') ? (string)$nativeError->getCode() : '',
                            'message' => (string)$nativeError->getMessage(),
                        ];
                    }
                }
            }
        } catch (Throwable $ignored) {}
        if ($mutationAttempted && $failedItem) {
            $currentItemState = 'unknown';
            try {
                // Fresh object, not the potentially stale pre-deletion model.
                $after = $loadObject($failedItem);
                $currentItemState = $after->isDeleted() ? 'in_trash' : 'present';
            } catch (Throwable $ignored) {}
        }

        $details = [
            'diagnosticId' => $diagnosticId,
            'stage' => $stage,
            'userId' => $userId,
            'failedItem' => $failedItem,
            'deletedBeforeFailure' => $deleted,
            'alreadyDeleted' => $alreadyDeleted,
            'currentItemState' => $currentItemState,
            'reloadRequired' => $mutationAttempted || (bool)$deleted || (bool)$alreadyDeleted,
        ];
        $diagnostics = [
            'userName' => $userName,
            'bitrixGroupIds' => $groupIds,
            'effectivePermissions' => $permissions,
            'siteId' => $context ? $context->siteId : null,
            'pageId' => $context ? $context->pageId : null,
            'blockId' => $context ? $context->blockId : null,
            'rootFolderId' => $rootFolderId,
            'itemName' => $name,
            'exceptionClass' => get_class($error),
            'technicalError' => $error->getMessage(),
            'bitrixErrors' => $bitrixErrors,
        ];
        try {
            error_log('SiteBuilder Disk delete ' . json_encode(
                $details + $diagnostics + [
                    'file' => $error->getFile(), 'line' => $error->getLine(),
                    'trace' => $error->getTraceAsString(),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));
        } catch (Throwable $ignored) {}

        $reason = $error->getMessage();
        $status = 500;
        $code = 'DISK_DELETE_FAILED';
        $message = 'Не удалось завершить удаление в Битрикс24.';
        if ($reason === 'NOT_AUTHORIZED') {
            $status = 401; $code = 'NOT_AUTHORIZED'; $message = 'Требуется авторизация.';
        } elseif (in_array($reason, ['EMPTY_SESSID', 'BAD_SESSID'], true)) {
            $status = 403; $code = $reason; $message = 'Сессия устарела. Обновите страницу.';
        } elseif (in_array($reason, ['ACCESS_DENIED', 'FOLDER_OUT_OF_SCOPE', 'ROOT_FOLDER_OPERATION_FORBIDDEN', 'BLOCK_CONTEXT_MISMATCH'], true)) {
            $status = 403; $code = $reason; $message = 'Удаление запрещено: проверьте права и расположение объекта.';
        } elseif (in_array($reason, ['DISK_ITEM_NOT_FOUND', 'DISK_FILE_NOT_FOUND', 'DISK_FOLDER_NOT_FOUND', 'BLOCK_NOT_FOUND', 'SITE_NOT_FOUND'], true)) {
            $status = 404; $code = $reason; $message = 'Объект не найден. Обновите список файлов.';
        } elseif (in_array($reason, ['EMPTY_ITEMS', 'INVALID_ITEM', 'INVALID_ENTITY_TYPE', 'INVALID_ENTITY_ID', 'INVALID_SITE_ID', 'INVALID_PAGE_ID', 'INVALID_BLOCK_ID'], true)) {
            $status = 422; $code = $reason; $message = 'Некорректные параметры удаления.';
        } elseif ($reason === 'BITRIX_DELETE_REJECTED') {
            $status = 409; $code = $reason; $message = 'Битрикс24 не подтвердил перемещение объекта в корзину.';
        }
        if ($failedItem) {
            $message .= ' Объект: ' . $failedItem['entityType'] . ' #' . $failedItem['id'] . '.';
        }
        $message .= ' Пользователь #' . $userId . '. Этап: ' . $stage . '. Код: ' . $diagnosticId . '.';
        if ($deleted) {
            $message .= ' Уже перемещено в корзину: ' . count($deleted) . '.';
        }
        if ($currentItemState === 'in_trash') {
            $message .= ' Проблемный объект уже в корзине, но обработка удаления завершилась ошибкой.';
        }
        if ($details['reloadRequired']) {
            $message .= ' Обновите список; повторное удаление автоматически не запускается.';
        }
        // Technical messages and memberships are for PORTAL admins only.
        if ($isAdmin) {
            $details += $diagnostics;
            $technical = $reason . ($bitrixErrors ? ': ' . implode(' | ', array_column($bitrixErrors, 'message')) : '');
            $message .= "\nПричина: " . substr($technical, 0, 1200);
        }
        return ['ok' => false, 'status' => $status, 'error' => $code, 'message' => $message, 'details' => $details];
    }
})();

// Discard output/warnings emitted by third-party delete event handlers.
while (ob_get_level() > $sbDeleteBufferLevel) {
    if (!@ob_end_clean()) { break; }
}
// Native exceptions can contain invalid bytes or a truncated multibyte name.
// Normalize them before the shared responder (which does not substitute UTF-8).
$sbDeleteResponse = json_decode(json_encode($sbDeleteResponse, JSON_INVALID_UTF8_SUBSTITUTE), true);
if (!$sbDeleteResponse['ok']) {
    http_response_code($sbDeleteResponse['status']);
    DiskResponse::error($sbDeleteResponse['error'], $sbDeleteResponse['message'], $sbDeleteResponse['details']);
} else {
    DiskResponse::success($sbDeleteResponse['data'], $sbDeleteResponse['meta']);
}

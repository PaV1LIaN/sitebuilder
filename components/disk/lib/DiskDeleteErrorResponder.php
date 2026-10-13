<?php

/**
 * Restricted diagnostics for delete operations in the Sitebuilder Disk.
 * Technical exceptions, group membership and effective ACLs stay in the PHP
 * error log; non-admin users only receive item/user IDs and a correlation ID.
 */
final class DiskDeleteErrorResponder
{
    public static function respond(Throwable $error, array $request): void
    {
        $diagnosticId = 'SD-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $actorId = (int)($request['userId'] ?? DiskCurrentUser::getId());
        $context = $request['context'] ?? null;
        $context = $context instanceof DiskContext ? $context : null;
        $settings = $request['settings'] ?? null;
        $rootFolderId = (int)($request['rootFolderId'] ?? 0);
        $body = $request['data'] ?? null;
        $stage = (string)($request['stage'] ?? 'unknown');

        $failedItem = null;
        $deletedBeforeFailure = [];
        if ($error instanceof DiskDeleteException) {
            $failedItem = [
                'entityType' => $error->getEntityType(),
                'id' => $error->getEntityId(),
            ];
            $deletedBeforeFailure = $error->getDeletedItems();
        } elseif (is_array($body) && isset($body['items']) && is_array($body['items']) && count($body['items']) === 1) {
            $item = reset($body['items']);
            if (is_array($item)) {
                $failedItem = [
                    'entityType' => (string)($item['entityType'] ?? ''),
                    'id' => (int)($item['id'] ?? 0),
                ];
            }
        }

        $groupIds = DiskCurrentUser::getGroupIds();
        $effectivePermissions = null;
        $permissionDiagnosticError = null;
        $parentFolderId = null;

        // Diagnostics must never mask the original deletion exception.
        if (
            $context !== null
            && is_array($settings)
            && $rootFolderId > 0
            && is_array($failedItem)
            && in_array($failedItem['entityType'], ['file', 'folder'], true)
            && $failedItem['id'] > 0
        ) {
            try {
                $parentFolderId = DiskValidator::itemParentFolderId(
                    $failedItem['entityType'],
                    $failedItem['id']
                );
                $effectivePermissions = DiskPermissionService::resolve(
                    $context,
                    $settings,
                    $parentFolderId,
                    $rootFolderId
                );
            } catch (Throwable $diagnosticError) {
                $permissionDiagnosticError = $diagnosticError->getMessage();
            }
        }

        $cause = $error instanceof DiskDeleteException && $error->getPrevious()
            ? $error->getPrevious()
            : $error;

        $log = [
            'diagnosticId' => $diagnosticId,
            'stage' => $stage,
            'userId' => $actorId,
            'bitrixGroupIds' => $groupIds,
            'siteId' => $context ? $context->siteId : null,
            'pageId' => $context ? $context->pageId : null,
            'blockId' => $context ? $context->blockId : null,
            'rootFolderId' => $rootFolderId,
            'parentFolderId' => $parentFolderId,
            'failedItem' => $failedItem,
            'requestedItems' => is_array($body) ? ($body['items'] ?? []) : [],
            'deletedBeforeFailure' => $deletedBeforeFailure,
            'effectivePermissions' => $effectivePermissions,
            'permissionDiagnosticError' => $permissionDiagnosticError,
            'exceptionClass' => get_class($cause),
            'exceptionMessage' => $cause->getMessage(),
            'exceptionFile' => $cause->getFile(),
            'exceptionLine' => $cause->getLine(),
        ];
        error_log(
            'SiteBuilder Disk delete error: '
            . json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n" . $cause->getTraceAsString()
        );

        $reason = $cause->getMessage();
        $accessDenied = in_array($reason, [
            'ACCESS_DENIED', 'FOLDER_OUT_OF_SCOPE', 'ROOT_FOLDER_OPERATION_FORBIDDEN',
        ], true);
        $notFound = in_array($reason, [
            'DISK_FILE_NOT_FOUND', 'DISK_FOLDER_NOT_FOUND', 'DISK_ITEM_NOT_FOUND',
        ], true);
        $invalid = in_array($reason, [
            'EMPTY_ITEMS', 'INVALID_ITEM', 'INVALID_ENTITY_ID', 'INVALID_ENTITY_TYPE',
        ], true);

        $noun = is_array($failedItem) && $failedItem['entityType'] === 'folder' ? 'папка' : 'файл';
        $itemLabel = is_array($failedItem) && $failedItem['id'] > 0
            ? ' (ID ' . $failedItem['id'] . ')' : '';
        $message = $accessDenied
            ? 'Недостаточно прав на удаление.'
            : ($notFound
                ? 'Объект не найден в Диске.'
                : 'Не удалось завершить удаление в Битрикс24.');
        $message .= ' Объект: ' . $noun . $itemLabel
            . '. Пользователь ID ' . $actorId
            . '. Код диагностики: ' . $diagnosticId . '.';
        if ($deletedBeforeFailure) {
            $message .= ' Часть объектов уже могла попасть в корзину. Обновите список.';
        }

        $details = [
            'diagnosticId' => $diagnosticId,
            'stage' => $stage,
            'userId' => $actorId,
            'failedItem' => $failedItem,
            'deletedBeforeFailure' => $deletedBeforeFailure,
        ];
        if (DiskCurrentUser::isAdmin()) {
            $details['bitrixGroupIds'] = $groupIds;
            $details['effectivePermissions'] = $effectivePermissions;
            $details['technicalError'] = $cause->getMessage();
        }

        http_response_code($accessDenied ? 403 : ($notFound ? 404 : ($invalid ? 422 : 500)));
        DiskResponse::error(
            $accessDenied ? 'ACCESS_DENIED' : ($notFound ? 'DISK_ITEM_NOT_FOUND' : 'DISK_DELETE_FAILED'),
            $message,
            $details
        );
    }
}

<?php

$action = (string)($_GET['action'] ?? '');

/*
 * Для обычных JSON-действий включаем буфер сразу,
 * чтобы если PHP выдаст warning/fatal/HTML — мы смогли вернуть нормальный JSON.
 * Для download буфер не включаем, чтобы не сломать скачивание файла.
 */
$sbDiskShouldBuffer = $action !== 'download';

if ($sbDiskShouldBuffer) {
    ob_start();
}

if (!function_exists('sb_disk_force_json_error')) {
    function sb_disk_force_json_error(string $error, string $message, array $details = []): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode([
            'ok' => false,
            'data' => [],
            'meta' => [],
            'error' => $error,
            'message' => $message,
            'details' => $details,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }
}

register_shutdown_function(static function () use ($sbDiskShouldBuffer) {
    if (!$sbDiskShouldBuffer) {
        return;
    }

    $error = error_get_last();

    if ($error === null) {
        return;
    }

    $fatalTypes = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
    ];

    if (!in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }

    sb_disk_force_json_error(
        'PHP_FATAL',
        (string)($error['message'] ?? 'PHP_FATAL'),
        [
            'file' => (string)($error['file'] ?? ''),
            'line' => (int)($error['line'] ?? 0),
        ]
    );
});

require_once __DIR__ . '/bootstrap.php';

sitebuilder_require_api_auth();

if (!function_exists('sb_disk_user_name_by_id')) {
    function sb_disk_user_name_by_id(int $userId): string
    {
        static $cache = [];

        if ($userId <= 0) {
            return '';
        }

        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        $name = '';

        if (class_exists('CUser')) {
            $rs = \CUser::GetByID($userId);

            if ($user = $rs->Fetch()) {
                $lastName = trim((string)($user['LAST_NAME'] ?? ''));
                $firstName = trim((string)($user['NAME'] ?? ''));
                $secondName = trim((string)($user['SECOND_NAME'] ?? ''));

                $name = trim($lastName . ' ' . $firstName . ' ' . $secondName);

                if ($name === '') {
                    $name = trim((string)($user['LOGIN'] ?? ''));
                }

                if ($name === '') {
                    $name = trim((string)($user['EMAIL'] ?? ''));
                }
            }
        }

        if ($name === '') {
            $name = 'ID ' . $userId;
        }

        $cache[$userId] = $name;

        return $name;
    }
}

if (!function_exists('sb_disk_extract_created_by_id_from_item')) {
    function sb_disk_extract_created_by_id_from_item(array $item): int
    {
        $possibleKeys = [
            'createdById',
            'createdBy',
            'authorId',
            'author',
            'userId',
            'user',
        ];

        foreach ($possibleKeys as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }

            $value = $item[$key];

            if (is_int($value)) {
                return $value;
            }

            if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
                return (int)$value;
            }
        }

        return 0;
    }
}

if (!function_exists('sb_disk_enrich_item_user_name')) {
    function sb_disk_enrich_item_user_name(array $item): array
    {
        $createdById = sb_disk_extract_created_by_id_from_item($item);

        if ($createdById <= 0) {
            return $item;
        }

        $createdByName = sb_disk_user_name_by_id($createdById);

        $item['createdById'] = $createdById;
        $item['createdByName'] = $createdByName;
        $item['createdByFullName'] = $createdByName;
        $item['authorName'] = $createdByName;
        $item['createdBy'] = $createdByName;

        return $item;
    }
}

if (!function_exists('sb_disk_enrich_json_response_with_user_names')) {
    function sb_disk_enrich_json_response_with_user_names(string $buffer): string
    {
        $trimmed = trim($buffer);

        if ($trimmed === '') {
            return $buffer;
        }

        $firstChar = substr($trimmed, 0, 1);

        if ($firstChar !== '{' && $firstChar !== '[') {
            return $buffer;
        }

        $json = json_decode($buffer, true);

        if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) {
            return $buffer;
        }

        if (
            isset($json['data']['items'])
            && is_array($json['data']['items'])
        ) {
            foreach ($json['data']['items'] as &$item) {
                if (is_array($item)) {
                    $item = sb_disk_enrich_item_user_name($item);
                }
            }
            unset($item);
        }

        if (
            isset($json['data']['item'])
            && is_array($json['data']['item'])
        ) {
            $json['data']['item'] = sb_disk_enrich_item_user_name($json['data']['item']);
        }

        return json_encode(
            $json,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}

/*
 * Обогащение ФИО включаем только для JSON-действий со списком.
 * Для upload/unpack не включаем, чтобы не мешать ответам.
 */
if (in_array($action, ['list', 'search', 'bootstrap'], true)) {
    ob_start('sb_disk_enrich_json_response_with_user_names');
}

try {
    switch ($action) {
        case 'resolveRoot':
            require __DIR__ . '/actions/resolve_root.php';
            break;

        case 'getSettings':
            require __DIR__ . '/actions/get_settings.php';
            break;

        case 'saveSettings':
            require __DIR__ . '/actions/save_settings.php';
            break;

        case 'getAccessMatrix':
            require __DIR__ . '/actions/get_access_matrix.php';
            break;

        case 'saveAccessMatrix':
            require __DIR__ . '/actions/save_access_matrix.php';
            break;

        case 'getPermissions':
            require __DIR__ . '/actions/get_permissions.php';
            break;

        case 'folderAccessList':
            require __DIR__ . '/actions/folder_access_list.php';
            break;

        case 'folderAccessSet':
            require __DIR__ . '/actions/folder_access_set.php';
            break;

        case 'folderAccessDelete':
            require __DIR__ . '/actions/folder_access_delete.php';
            break;

        case 'userSearch':
            require __DIR__ . '/actions/user_search.php';
            break;

        case 'getRootOptions':
            require __DIR__ . '/actions/get_root_options.php';
            break;

        case 'list':
            require __DIR__ . '/actions/list.php';
            break;

        case 'upload':
            require __DIR__ . '/actions/upload.php';
            break;

        case 'createFolder':
            require __DIR__ . '/actions/create_folder.php';
            break;

        case 'rename':
            require __DIR__ . '/actions/rename.php';
            break;

        case 'delete':
            require __DIR__ . '/actions/delete.php';
            break;

        case 'move':
            require __DIR__ . '/actions/move.php';
            break;

        case 'copy':
            require __DIR__ . '/actions/copy.php';
            break;

        case 'search':
            require __DIR__ . '/actions/search.php';
            break;

        case 'download':
            require __DIR__ . '/actions/download.php';
            break;

        case 'unpackArchive':
            require __DIR__ . '/actions/unpack_archive.php';
            break;

        case 'unpackArchiveStart':
            require __DIR__ . '/actions/unpack_archive_start.php';
            break;
            
        case 'unpackArchiveStep':
            require __DIR__ . '/actions/unpack_archive_step.php';
            break;

        case 'initSiteRoot':
            require __DIR__ . '/actions/init_site_root.php';
            break;

        case 'initBlockRoot':
            require __DIR__ . '/actions/init_block_root.php';
            break;

        case 'bootstrap':
            require __DIR__ . '/actions/bootstrap.php';
            break;

        default:
            DiskResponse::error('UNKNOWN_ACTION', 'Неизвестное действие');
    }
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if ($e instanceof DiskRightsVersionConflictException) {
        http_response_code(409);
        DiskResponse::error(
            'DISK_RIGHTS_VERSION_CONFLICT',
            'Права папки изменены в другой вкладке.',
            ['currentRightsRevision' => $e->currentRevision()]
        );
    }

    if ($e instanceof SiteBuilderVersionConflictException) {
        http_response_code(409);
        DiskResponse::error(
            'VERSION_CONFLICT',
            'Объект был изменён в другой вкладке.',
            $e->context()
        );
    }

    if (
        $e instanceof InvalidArgumentException
        && $e->getMessage() === 'EXPECTED_RIGHTS_REVISION_REQUIRED'
    ) {
        http_response_code(422);
        DiskResponse::error(
            'EXPECTED_RIGHTS_REVISION_REQUIRED',
            'Не передана ревизия прав папки.'
        );
    }

    if (
        $e instanceof InvalidArgumentException
        && $e->getMessage() === 'EXPECTED_VERSION_REQUIRED'
    ) {
        http_response_code(422);
        DiskResponse::error(
            'EXPECTED_VERSION_REQUIRED',
            'Не передана версия блока.'
        );
    }

    if (
        $e instanceof RuntimeException
        && str_starts_with(
            $e->getMessage(),
            'DISK_RIGHTS_WRITE_VERIFICATION_FAILED'
        )
    ) {
        http_response_code(500);
        DiskResponse::error(
            'DISK_RIGHTS_WRITE_VERIFICATION_FAILED',
            'Битрикс24.Диск не подтвердил запись прямого права. Изменения отменены.',
            ['diagnostic' => $e->getMessage()]
        );
    }

    if (
        $e instanceof RuntimeException
        && $e->getMessage() === 'DISK_ACL_INTENT_STORAGE_UNAVAILABLE'
    ) {
        http_response_code(503);
        DiskResponse::error(
            'DISK_ACL_INTENT_STORAGE_UNAVAILABLE',
            'Не применена миграция этапа 22 для контроллера прав. Изменения ACL отменены.'
        );
    }

    if (
        $e instanceof RuntimeException
        && str_starts_with(
            $e->getMessage(),
            'DISK_RIGHTS_EFFECTIVE_VERIFICATION_FAILED'
        )
    ) {
        http_response_code(500);
        DiskResponse::error(
            'DISK_RIGHTS_EFFECTIVE_VERIFICATION_FAILED',
            'Битрикс24.Диск не смог подтвердить итоговый запрет чтения. Изменения отменены.',
            ['diagnostic' => $e->getMessage()]
        );
    }

    if (
        $e instanceof RuntimeException
        && (
            str_starts_with($e->getMessage(), 'DISK_RIGHTS_SET_FAILED')
            || $e->getMessage() === 'DISK_RIGHTS_SET_API_UNAVAILABLE'
            || str_starts_with($e->getMessage(), 'DISK_RIGHTS_APPEND_FAILED')
            || $e->getMessage() === 'DISK_RIGHTS_APPEND_API_UNAVAILABLE'
            || str_starts_with($e->getMessage(), 'DISK_RIGHTS_REVOKE_FAILED')
            || $e->getMessage() === 'DISK_RIGHTS_REVOKE_API_UNAVAILABLE'
        )
    ) {
        http_response_code(500);
        DiskResponse::error(
            'DISK_RIGHTS_SET_FAILED',
            'Битрикс24.Диск не смог заменить или отозвать права папки. Исходные права восстановлены.',
            ['diagnostic' => $e->getMessage()]
        );
    }

    error_log(sprintf(
        'SiteBuilder Disk API error: %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    http_response_code(500);
    DiskResponse::error(
        'SERVER_ERROR',
        'Внутренняя ошибка сервера.'
    );
}

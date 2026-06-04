<?php

require_once __DIR__ . '/bootstrap.php';

$action = (string)($_GET['action'] ?? '');

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

        /*
         * Важно:
         * Раньше createdBy мог быть числом, из-за этого JS показывал ID.
         * Теперь createdBy отдаём уже как ФИО.
         */
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
 * Включаем перехват только для JSON-действий.
 * Для download нельзя включать, иначе можно сломать скачивание файла.
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

        case 'getPermissions':
            require __DIR__ . '/actions/get_permissions.php';
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
    DiskResponse::error('SERVER_ERROR', $e->getMessage());
}
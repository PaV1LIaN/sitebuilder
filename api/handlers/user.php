<?php

global $USER;

if (!function_exists('sb_user_search_require_owner')) {
    function sb_user_search_require_owner(int $siteId): void
    {
        global $USER;

        if ($USER && $USER->IsAdmin()) {
            return;
        }

        sb_require_owner($siteId);
    }
}

if (!function_exists('sb_user_search_normalize_text')) {
    function sb_user_search_normalize_text(string $value): string
    {
        $value = trim($value);
        $value = str_replace('ё', 'е', $value);
        $value = str_replace('Ё', 'Е', $value);

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}

if (!function_exists('sb_user_search_query_tokens')) {
    function sb_user_search_query_tokens(string $query): array
    {
        $query = sb_user_search_normalize_text($query);
        $parts = preg_split('/[\s,;]+/u', $query, -1, PREG_SPLIT_NO_EMPTY);

        $tokens = [];

        foreach ($parts as $part) {
            $part = trim((string)$part);

            if ($part === '') {
                continue;
            }

            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }
}

if (!function_exists('sb_user_search_normalize')) {
    function sb_user_search_normalize(array $row): array
    {
        $id = (int)($row['ID'] ?? 0);

        $fio = trim(
            (string)($row['LAST_NAME'] ?? '') . ' ' .
            (string)($row['NAME'] ?? '') . ' ' .
            (string)($row['SECOND_NAME'] ?? '')
        );

        $login = (string)($row['LOGIN'] ?? '');
        $email = (string)($row['EMAIL'] ?? '');

        $photoId = (int)($row['PERSONAL_PHOTO'] ?? 0);
        $avatarUrl = '';

        if ($photoId > 0 && class_exists('CFile')) {
            $avatarUrl = (string)CFile::GetPath($photoId);
        }

        $title = $fio !== '' ? $fio : ($login !== '' ? $login : ('Пользователь #' . $id));

        return [
            'id' => $id,
            'name' => $fio,
            'login' => $login,
            'email' => $email,
            'title' => $title,
            'avatarUrl' => $avatarUrl,
            'photoUrl' => $avatarUrl,
            'active' => (string)($row['ACTIVE'] ?? ''),
        ];
    }
}

if (!function_exists('sb_user_search_row_haystack')) {
    function sb_user_search_row_haystack(array $row): string
    {
        return sb_user_search_normalize_text(implode(' ', [
            (string)($row['ID'] ?? ''),
            (string)($row['LOGIN'] ?? ''),
            (string)($row['EMAIL'] ?? ''),
            (string)($row['NAME'] ?? ''),
            (string)($row['LAST_NAME'] ?? ''),
            (string)($row['SECOND_NAME'] ?? ''),
            trim(
                (string)($row['LAST_NAME'] ?? '') . ' ' .
                (string)($row['NAME'] ?? '') . ' ' .
                (string)($row['SECOND_NAME'] ?? '')
            ),
            trim(
                (string)($row['NAME'] ?? '') . ' ' .
                (string)($row['LAST_NAME'] ?? '')
            ),
        ]));
    }
}

if (!function_exists('sb_user_search_row_matches_tokens')) {
    function sb_user_search_row_matches_tokens(array $row, array $tokens): bool
    {
        if (empty($tokens)) {
            return false;
        }

        $haystack = sb_user_search_row_haystack($row);

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (strpos($haystack, $token) === false) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('sb_user_search_add_result')) {
    function sb_user_search_add_result(array &$results, array $row, array $tokens, int $limit): void
    {
        $id = (int)($row['ID'] ?? 0);

        if ($id <= 0) {
            return;
        }

        if (count($results) >= $limit) {
            return;
        }

        if (!empty($tokens) && !sb_user_search_row_matches_tokens($row, $tokens)) {
            return;
        }

        $results[$id] = sb_user_search_normalize($row);
    }
}

if (!function_exists('sb_user_search_by_filter')) {
    function sb_user_search_by_filter(array $filter, array &$results, array $tokens, int $limit): void
    {
        if (count($results) >= $limit) {
            return;
        }

        $by = 'last_name';
        $order = 'asc';

        $rs = CUser::GetList(
            $by,
            $order,
            $filter,
            [
                'FIELDS' => [
                    'ID',
                    'LOGIN',
                    'EMAIL',
                    'NAME',
                    'LAST_NAME',
                    'SECOND_NAME',
                    'PERSONAL_PHOTO',
                    'ACTIVE',
                ],
            ]
        );

        while ($row = $rs->Fetch()) {
            sb_user_search_add_result($results, $row, $tokens, $limit);

            if (count($results) >= $limit) {
                break;
            }
        }
    }
}

if (!function_exists('sb_user_search_collect_by_token')) {
    function sb_user_search_collect_by_token(string $token, array &$results, array $tokens, int $limit): void
    {
        if ($token === '' || count($results) >= $limit) {
            return;
        }

        $filters = [
            [
                'ACTIVE' => 'Y',
                '%LOGIN' => $token,
            ],
            [
                'ACTIVE' => 'Y',
                '%EMAIL' => $token,
            ],
            [
                'ACTIVE' => 'Y',
                '%NAME' => $token,
            ],
            [
                'ACTIVE' => 'Y',
                '%LAST_NAME' => $token,
            ],
            [
                'ACTIVE' => 'Y',
                '%SECOND_NAME' => $token,
            ],
        ];

        foreach ($filters as $filter) {
            sb_user_search_by_filter($filter, $results, $tokens, $limit);

            if (count($results) >= $limit) {
                break;
            }
        }
    }
}

if ($action === 'user.search') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $query = trim((string)($_POST['query'] ?? ''));
    $limit = (int)($_POST['limit'] ?? 10);

    $limit = max(1, min(20, $limit));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_user_search_require_owner($siteId);

    if ($query === '') {
        sb_json_ok([
            'users' => [],
            'handler' => 'user',
            'action' => 'user.search',
            'file' => __FILE__,
        ]);
    }

    if (!preg_match('/^\d+$/', $query) && mb_strlen($query, 'UTF-8') < 2) {
        sb_json_error('QUERY_TOO_SHORT', 422);
    }

    $tokens = sb_user_search_query_tokens($query);
    $results = [];

    /*
     * Поиск по ID.
     */
    if (preg_match('/^\d+$/', $query)) {
        $rs = CUser::GetByID((int)$query);
        $row = $rs ? $rs->Fetch() : null;

        if ($row && (string)($row['ACTIVE'] ?? '') === 'Y') {
            $results[(int)$row['ID']] = sb_user_search_normalize($row);
        }
    }

    /*
     * Поиск по каждому слову из ФИО.
     * Например: "Иванов Иван" ищем отдельно "Иванов" и "Иван",
     * а потом оставляем только тех, у кого в карточке есть оба слова.
     */
    foreach ($tokens as $token) {
        if (count($results) >= $limit) {
            break;
        }

        sb_user_search_collect_by_token($token, $results, $tokens, $limit);
    }

    /*
     * Дополнительная попытка через NAME_SEARCH, если версия Битрикса это поддерживает.
     */
    if (count($results) < $limit) {
        sb_user_search_by_filter([
            'ACTIVE' => 'Y',
            'NAME_SEARCH' => $query,
        ], $results, $tokens, $limit);
    }

    sb_json_ok([
        'users' => array_values($results),
        'handler' => 'user',
        'action' => 'user.search',
        'query' => $query,
        'tokens' => $tokens,
        'file' => __FILE__,
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'user',
    'action' => $action,
    'file' => __FILE__,
]);
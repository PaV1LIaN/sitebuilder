<?php

/** Bulk folder rules belong to SiteBuilder; they never grant site/page access. */
final class FolderAccessBulkService
{
    public const MAX_USERS = 200;
    private const CANDIDATE_LIMIT = 20;

    public static function normalize(string $value): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)), 'UTF-8'));
    }

    public static function parse(string $text): array
    {
        if (strlen($text) > 60000 || !mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('INVALID_BULK_USERS');
        }
        $lines = preg_split('/[\r\n;]+/u', $text);
        $lines = array_values(array_filter(array_map('trim', $lines), static fn($line) => $line !== ''));
        if (!$lines || count($lines) > self::MAX_USERS) {
            throw new InvalidArgumentException('INVALID_BULK_USERS');
        }
        foreach ($lines as $line) {
            if (mb_strlen($line, 'UTF-8') > 250) {
                throw new InvalidArgumentException('INVALID_BULK_USERS');
            }
        }
        return $lines;
    }

    public static function resolve(string $text): array
    {
        $rows = [];
        $cache = [];
        foreach (self::parse($text) as $query) {
            $key = self::normalize($query);
            if (!isset($cache[$key])) {
                $cache[$key] = self::findCandidates($query);
            }
            $rows[] = ['query' => $query] + $cache[$key];
        }
        return $rows;
    }

    private static function findCandidates(string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query));
        if (preg_match('/^(?:ID\s*:?\s*)?(\d+)$/i', $query, $matches)) {
            $filter = ['=ID' => (int)$matches[1]];
        } else {
            $tokens = preg_split('/\s+/u', $query);
            $nameFilter = ['LOGIC' => 'AND'];
            foreach ($tokens as $token) {
                $nameFilter[] = ['LOGIC' => 'OR', '%LAST_NAME' => $token, '%NAME' => $token, '%SECOND_NAME' => $token];
            }
            $filter = ['LOGIC' => 'OR', '=LOGIN' => $query, '=EMAIL' => $query, $nameFilter];
        }
        $result = \Bitrix\Main\UserTable::getList([
            'select' => ['ID', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
            'filter' => ['=ACTIVE' => 'Y', $filter],
            'order' => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
            'limit' => self::CANDIDATE_LIMIT + 1,
        ]);
        $users = [];
        while ($row = $result->fetch()) {
            $name = trim(implode(' ', array_filter([
                trim((string)($row['LAST_NAME'] ?? '')), trim((string)($row['NAME'] ?? '')),
                trim((string)($row['SECOND_NAME'] ?? '')),
            ], static fn($part) => $part !== '')));
            $users[] = ['id' => (int)$row['ID'], 'name' => $name ?: (string)$row['LOGIN'],
                'login' => (string)$row['LOGIN'], 'email' => (string)($row['EMAIL'] ?? '')];
        }
        $truncated = count($users) > self::CANDIDATE_LIMIT;
        $users = array_slice($users, 0, self::CANDIDATE_LIMIT);
        $exact = array_values(array_filter($users, static function ($user) use ($query): bool {
            $key = self::normalize($query);
            return $key === self::normalize($user['name']) || $key === self::normalize($user['login'])
                || $key === self::normalize($user['email']) || $key === (string)$user['id']
                || preg_match('/^id\s*:?\s*' . $user['id'] . '$/i', $key);
        }));
        return ['candidates' => $users, 'truncated' => $truncated,
            'selectedId' => !$truncated && count($exact) === 1 ? $exact[0]['id'] : null];
    }

    public static function validateUserIds(array $values): array
    {
        if (!$values || count($values) > self::MAX_USERS) {
            throw new InvalidArgumentException('INVALID_BULK_USERS');
        }
        $ids = [];
        foreach ($values as $value) {
            if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int)$value <= 0) {
                throw new InvalidArgumentException('INVALID_BULK_USERS');
            }
            $ids[(int)$value] = (int)$value;
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        $result = \Bitrix\Main\UserTable::getList([
            'select' => ['ID'], 'filter' => ['=ACTIVE' => 'Y', '@ID' => $ids],
        ]);
        $found = [];
        while ($row = $result->fetch()) {
            $found[] = (int)$row['ID'];
        }
        sort($found, SORT_NUMERIC);
        if ($found !== $ids) {
            throw new InvalidArgumentException('BULK_USER_NOT_ACTIVE');
        }
        return $ids;
    }

    public static function assertMode(array $settings): void
    {
        if (($settings['permissionMode'] ?? 'inherit_site') !== 'custom') {
            throw new RuntimeException('FOLDER_ACCESS_MODE_REQUIRED');
        }
    }
}

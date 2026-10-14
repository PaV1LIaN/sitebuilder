<?php

declare(strict_types=1);

/** Exact bulk matching is deliberately separate from suggestion search. */
final class AccessUserResolver
{
    public const MAX_LINES = 5000;
    public const BATCH_SIZE = 100;
    private const FIELDS = ['ID', 'ACTIVE', 'LOGIN', 'EMAIL', 'LAST_NAME', 'NAME', 'SECOND_NAME'];

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $value)), 'UTF-8');
    }

    public static function parse(string $text): array
    {
        if (strlen($text) > 1024 * 1024 || !mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('INVALID_USER_LIST');
        }
        $rows = [];
        foreach (preg_split('/\r\n|[\r\n;]/u', preg_replace('/^\x{FEFF}/u', '', $text)) as $index => $line) {
            $line = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $line));
            if ($line === '') {
                continue;
            }
            if (mb_strlen($line, 'UTF-8') > 250 || count($rows) >= self::MAX_LINES) {
                throw new InvalidArgumentException('USER_LIST_LIMIT');
            }
            $rows[] = ['line' => $index + 1, 'input' => $line];
        }
        return $rows;
    }

    public static function profile(array $row): array
    {
        $name = trim(implode(' ', array_filter([
            trim((string)($row['LAST_NAME'] ?? '')), trim((string)($row['NAME'] ?? '')),
            trim((string)($row['SECOND_NAME'] ?? '')),
        ], static fn(string $s): bool => $s !== '')));
        return [
            'id' => (int)$row['ID'], 'name' => $name ?: (string)($row['LOGIN'] ?? ('ID ' . $row['ID'])),
            'login' => (string)($row['LOGIN'] ?? ''), 'email' => (string)($row['EMAIL'] ?? ''),
            'active' => ($row['ACTIVE'] ?? '') === 'Y',
        ];
    }

    public static function profiles(array $ids): array
    {
        $profiles = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            if (!$chunk) {
                continue;
            }
            $result = \Bitrix\Main\UserTable::getList(['select' => self::FIELDS, 'filter' => ['@ID' => $chunk]]);
            while ($row = $result->fetch()) {
                $profiles[(int)$row['ID']] = self::profile($row);
            }
        }
        return $profiles;
    }

    /** Matching never treats a surname prefix as an exact user identity. */
    public static function matches(string $input, array $row): bool
    {
        $input = self::normalize($input);
        if (preg_match('/^(?:u|id\s*[:#]?\s*)?([1-9][0-9]*)$/u', $input, $match)) {
            return $match[1] === (string)(int)$row['ID'];
        }
        if ($input === self::normalize((string)($row['LOGIN'] ?? ''))
            || $input === self::normalize((string)($row['EMAIL'] ?? ''))) {
            return true;
        }
        $parts = array_values(array_filter(array_map([self::class, 'normalize'], [
            (string)($row['LAST_NAME'] ?? ''), (string)($row['NAME'] ?? ''), (string)($row['SECOND_NAME'] ?? ''),
        ]), static fn(string $part): bool => $part !== ''));
        if (count($parts) < 2) {
            return false;
        }
        $parts = explode(' ', implode(' ', $parts));
        $inputParts = explode(' ', $input);
        sort($parts, SORT_STRING);
        sort($inputParts, SORT_STRING);
        return $parts === $inputParts;
    }

    /** One bounded ORM query for each <=100 pasted rows. No per-person lookup. */
    public static function resolve(array $lines): array
    {
        if (!$lines || count($lines) > self::BATCH_SIZE) {
            throw new InvalidArgumentException('USER_BATCH_LIMIT');
        }
        $or = ['LOGIC' => 'OR'];
        $runtime = [];
        foreach (['LOGIN', 'EMAIL', 'LAST_NAME', 'NAME', 'SECOND_NAME'] as $field) {
            $runtime[] = new \Bitrix\Main\Entity\ExpressionField('SB_LOWER_' . $field, 'LOWER(TRIM(%s))', [$field]);
        }
        foreach ($lines as $line) {
            if (!is_array($line) || !is_string($line['input'] ?? null)
                || !is_int($line['line'] ?? null) || $line['line'] < 1
                || mb_strlen($line['input'], 'UTF-8') > 250) {
                throw new InvalidArgumentException('INVALID_USER_LIST');
            }
            $query = self::normalize($line['input']);
            if ($query === '') {
                throw new InvalidArgumentException('INVALID_USER_LIST');
            }
            if (preg_match('/^(?:u|id\s*[:#]?\s*)?([1-9][0-9]*)$/u', $query, $match)) {
                $or[] = ['=ID' => (int)$match[1]];
                continue;
            }
            $or[] = ['=SB_LOWER_LOGIN' => $query];
            $or[] = ['=SB_LOWER_EMAIL' => $query];
            $parts = explode(' ', $query);
            if (count($parts) >= 2 && count($parts) <= 6) {
                $name = ['LOGIC' => 'AND'];
                foreach ($parts as $part) {
                    $name[] = ['LOGIC' => 'OR', '%SB_LOWER_LAST_NAME' => $part, '%SB_LOWER_NAME' => $part, '%SB_LOWER_SECOND_NAME' => $part];
                }
                $or[] = $name;
            }
        }
        $guest = (int)(sitebuilder_auth_config()['guest_user_id'] ?? 0);
        $filter = ['=ACTIVE' => 'Y', $or];
        if ($guest > 0) {
            $filter['!=ID'] = $guest;
        }
        $result = \Bitrix\Main\UserTable::getList([
            'select' => self::FIELDS, 'filter' => $filter, 'runtime' => $runtime, 'order' => ['ID' => 'ASC'], 'limit' => 5001,
        ]);
        $candidates = [];
        while ($row = $result->fetch()) {
            $candidates[] = $row;
        }
        // Never auto-select when the complete candidate set is unavailable.
        if (count($candidates) > 5000) {
            throw new RuntimeException('TOO_MANY_USER_MATCHES');
        }
        $seen = [];
        $rows = [];
        foreach ($lines as $line) {
            $matches = [];
            foreach ($candidates as $candidate) {
                if (self::matches($line['input'], $candidate)) {
                    $matches[(int)$candidate['ID']] = self::profile($candidate);
                }
            }
            $status = count($matches) === 1 ? 'found' : (count($matches) ? 'ambiguous' : 'not_found');
            $user = count($matches) === 1 ? reset($matches) : null;
            if ($user !== null) {
                if (isset($seen[$user['id']])) {
                    $status = 'duplicate';
                }
                $seen[$user['id']] = true;
            }
            $rows[] = $line + ['status' => $status, 'user' => $user,
                'candidates' => count($matches) > 1 ? array_slice(array_values($matches), 0, 30) : [],
                'candidateCount' => count($matches)];
        }
        return $rows;
    }

    public static function search(string $query): array
    {
        $query = trim((string)preg_replace('/\s+/u', ' ', $query));
        if (mb_strlen($query, 'UTF-8') > 250) {
            throw new InvalidArgumentException('INVALID_USER_QUERY');
        }
        if ($query === '' || (!ctype_digit($query) && mb_strlen($query, 'UTF-8') < 2)) {
            return [];
        }
        $names = ['LOGIC' => 'AND'];
        foreach (explode(' ', $query) as $word) {
            $names[] = ['LOGIC' => 'OR', '%LAST_NAME' => $word, '%NAME' => $word, '%SECOND_NAME' => $word];
        }
        $filter = ['=ACTIVE' => 'Y', ctype_digit($query) ? ['=ID' => (int)$query]
            : ['LOGIC' => 'OR', '%LOGIN' => $query, '%EMAIL' => $query, $names]];
        $guest = (int)(sitebuilder_auth_config()['guest_user_id'] ?? 0);
        if ($guest > 0) {
            $filter['!=ID'] = $guest;
        }
        $result = \Bitrix\Main\UserTable::getList([
            'select' => self::FIELDS, 'filter' => $filter,
            'order' => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'], 'limit' => 30,
        ]);
        $users = [];
        while ($row = $result->fetch()) {
            $users[] = self::profile($row);
        }
        return $users;
    }
}

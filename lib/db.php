<?php

function sb_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/lib/pg_master.php';

    if (!function_exists('getPDO')) {
        throw new RuntimeException('FUNCTION_getPDO_NOT_FOUND');
    }

    $pdo = getPDO();

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('getPDO_DID_NOT_RETURN_PDO');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    try {
        $pdo->exec("SET search_path TO sitebuilder, public");
    } catch (Throwable $e) {
        // Если схема уже задана на уровне подключения — не критично.
    }

    return $pdo;
}

function sb_db_fetch_all(string $sql, array $params = []): array
{
    $stmt = sb_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function sb_db_fetch_one(string $sql, array $params = []): ?array
{
    $stmt = sb_db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

function sb_db_execute(string $sql, array $params = []): bool
{
    $stmt = sb_db()->prepare($sql);
    return $stmt->execute($params);
}

function sb_db_last_insert_id(?string $sequence = null): int
{
    return (int)sb_db()->lastInsertId($sequence);
}

function sb_json_decode_assoc($value): array
{
    if (is_array($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return [];
    }

    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}
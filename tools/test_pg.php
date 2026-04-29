<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/lib/pg_master.php';

echo '<pre>';

if (!function_exists('getPDO')) {
    echo "getPDO not found\n";
    exit;
}

$pdo = getPDO();

if (!$pdo instanceof PDO) {
    echo "getPDO did not return PDO\n";
    exit;
}

echo "PDO OK\n";

$stmt = $pdo->query("select current_database() as db, current_schema() as schema");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

print_r($row);

echo '</pre>';
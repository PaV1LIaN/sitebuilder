<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PublicRouteService.php';

global $USER;

header('Content-Type: text/plain; charset=UTF-8');

if (!is_object($USER) || !$USER->IsAdmin()) {
    http_response_code(403);
    exit("Доступ запрещён. Запустите установщик под администратором Битрикс24.\n");
}

$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname(__DIR__)), '/');

try {
    $status = PublicRouteService::install($basePath);
} catch (Throwable $e) {
    http_response_code(500);
    exit("Не удалось установить ЧПУ SiteBuilder: {$e->getMessage()}\n");
}

echo "ЧПУ SiteBuilder установлены и проверены.\n";
echo "Сайт Bitrix: " . (defined('SITE_ID') ? (string)SITE_ID : 's1') . "\n";

foreach ((array)($status['rules'] ?? []) as $rule) {
    echo (string)($rule['CONDITION'] ?? '')
        . ' -> '
        . (string)($rule['PATH'] ?? '')
        . ' (SORT=' . (int)($rule['SORT'] ?? 0) . ")\n";
}

echo "Пример: {$basePath}/s/site-slug/page-slug/\n";

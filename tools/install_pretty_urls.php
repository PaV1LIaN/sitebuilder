<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

global $USER;

header('Content-Type: text/plain; charset=UTF-8');

if (!is_object($USER) || !$USER->IsAdmin()) {
    http_response_code(403);
    exit("Доступ запрещён. Запустите установщик под администратором Битрикс24.\n");
}

$siteId = defined('SITE_ID') && (string)SITE_ID !== '' ? (string)SITE_ID : 's1';
$condition = '#^/local/sitebuilder/s/#';
$path = '/local/sitebuilder/router.php';

$arUrlRewrite = [];
$urlRewriteFile = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/urlrewrite.php';

if (is_file($urlRewriteFile)) {
    include $urlRewriteFile;
}

foreach ((array)$arUrlRewrite as $rule) {
    if (
        (string)($rule['CONDITION'] ?? '') === $condition
        && (string)($rule['PATH'] ?? '') === $path
    ) {
        echo "ЧПУ SiteBuilder уже установлены.\n";
        echo "Пример: /local/sitebuilder/s/site-slug/page-slug/\n";
        exit;
    }
}

$rule = [
    'CONDITION' => $condition,
    'RULE' => '',
    'ID' => '',
    'PATH' => $path,
    'SORT' => 100,
];

if (class_exists('\\Bitrix\\Main\\UrlRewriter')) {
    \Bitrix\Main\UrlRewriter::add($siteId, $rule);
} elseif (class_exists('CUrlRewriter')) {
    CUrlRewriter::Add([
        'SITE_ID' => $siteId,
        'CONDITION' => $rule['CONDITION'],
        'RULE' => $rule['RULE'],
        'ID' => $rule['ID'],
        'PATH' => $rule['PATH'],
    ]);
} else {
    http_response_code(500);
    exit("Не найден API Bitrix UrlRewriter. Правило не установлено.\n");
}

echo "ЧПУ SiteBuilder установлены для сайта {$siteId}.\n";
echo "Правило: {$condition} -> {$path}\n";
echo "Пример: /local/sitebuilder/s/site-slug/page-slug/\n";

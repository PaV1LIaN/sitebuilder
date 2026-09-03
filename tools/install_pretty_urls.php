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
$sort = 1;
$probeUrl = '/local/sitebuilder/s/site-slug/page-slug/';

$rule = [
    'CONDITION' => $condition,
    'RULE' => '',
    'ID' => '',
    'PATH' => $path,
    'SORT' => $sort,
];

try {
    if (class_exists('\\Bitrix\\Main\\UrlRewriter')) {
        // Удаляем старые варианты этого правила и записываем его заново с
        // приоритетом выше общих маршрутов портала.
        \Bitrix\Main\UrlRewriter::delete($siteId, [
            'PATH' => $path,
        ]);
        \Bitrix\Main\UrlRewriter::delete($siteId, [
            'CONDITION' => $condition,
        ]);
        \Bitrix\Main\UrlRewriter::add($siteId, $rule);

        $rules = \Bitrix\Main\UrlRewriter::getList(
            $siteId,
            [],
            ['SORT' => 'ASC']
        );
    } elseif (class_exists('CUrlRewriter')) {
        \CUrlRewriter::Delete([
            'SITE_ID' => $siteId,
            'PATH' => $path,
        ]);
        \CUrlRewriter::Delete([
            'SITE_ID' => $siteId,
            'CONDITION' => $condition,
        ]);
        \CUrlRewriter::Add([
            'SITE_ID' => $siteId,
            ...$rule,
        ]);

        $rules = \CUrlRewriter::GetList(
            ['SITE_ID' => $siteId],
            ['SORT' => 'ASC']
        );
    } else {
        throw new RuntimeException('Не найден API Bitrix UrlRewriter.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit("Не удалось установить ЧПУ SiteBuilder: {$e->getMessage()}\n");
}

$installed = false;
$firstMatchingRule = null;

foreach ((array)$rules as $candidate) {
    if (!is_array($candidate)) {
        continue;
    }

    if (
        (string)($candidate['CONDITION'] ?? '') === $condition
        && (string)($candidate['PATH'] ?? '') === $path
    ) {
        $installed = true;
    }

    $candidateCondition = (string)($candidate['CONDITION'] ?? '');

    if (
        $firstMatchingRule === null
        && $candidateCondition !== ''
        && @preg_match($candidateCondition, $probeUrl) === 1
    ) {
        $firstMatchingRule = $candidate;
    }
}

if (!$installed) {
    http_response_code(500);
    exit("Битрикс24 не подтвердил запись правила в urlrewrite.php.\n");
}

$firstMatchingPath = is_array($firstMatchingRule)
    ? (string)($firstMatchingRule['PATH'] ?? '')
    : '';

if ($firstMatchingPath !== $path) {
    http_response_code(500);
    $shownPath = $firstMatchingPath !== '' ? $firstMatchingPath : '(путь не определён)';
    exit(
        "Правило SiteBuilder записано, но URL раньше перехватывает другое правило: {$shownPath}\n"
        . "Проверьте порядок правил в корневом /urlrewrite.php.\n"
    );
}

echo "ЧПУ SiteBuilder установлены и проверены.\n";
echo "Сайт Bitrix: {$siteId}\n";
echo "Приоритет: {$sort}\n";
echo "Правило: {$condition} -> {$path}\n";
echo "Пример: {$probeUrl}\n";

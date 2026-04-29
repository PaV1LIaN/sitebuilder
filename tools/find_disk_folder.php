<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Disk\Internals\ObjectTable;

$name = trim((string)($_GET['name'] ?? ''));

echo '<pre>';

if ($name === '') {
    echo "Передай ?name=SiteBuilder" . PHP_EOL;
    echo '</pre>';
    return;
}

$rows = ObjectTable::getList([
    'filter' => [
        '=NAME' => $name,
        '=TYPE' => 2, // папка
        '=DELETED_TYPE' => 0,
    ],
    'select' => ['ID', 'NAME', 'PARENT_ID', 'REAL_OBJECT_ID']
])->fetchAll();

if (!$rows) {
    echo "Ничего не найдено" . PHP_EOL;
    echo '</pre>';
    return;
}

foreach ($rows as $row) {
    echo 'ID=' . $row['ID']
        . ' | NAME=' . $row['NAME']
        . ' | PARENT_ID=' . $row['PARENT_ID']
        . ' | REAL_OBJECT_ID=' . $row['REAL_OBJECT_ID']
        . PHP_EOL;
}

echo '</pre>';

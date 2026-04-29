<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Disk\Folder;

$folderId = (int)($_GET['id'] ?? 0);

echo '<pre>';

if ($folderId > 0) {
    $folder = Folder::loadById($folderId);

    if ($folder instanceof Folder) {
        echo "ID: " . $folder->getId() . PHP_EOL;
        echo "NAME: " . $folder->getName() . PHP_EOL;
        echo "PARENT_ID: " . $folder->getParentId() . PHP_EOL;
    } else {
        echo "Folder not found" . PHP_EOL;
    }

    echo '</pre>';
    return;
}

echo "Передай ?id=..." . PHP_EOL;
echo '</pre>';
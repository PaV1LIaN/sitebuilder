<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

sitebuilder_require_bitrix_admin();

use Bitrix\Disk\Driver;
use Bitrix\Disk\Storage;
use Bitrix\Disk\Folder;

global $USER;

echo '<pre>';

$userId = (int)$USER->GetID();
echo "USER_ID: " . $userId . PHP_EOL . PHP_EOL;

$driver = Driver::getInstance();
$storage = $driver->getStorageByUserId($userId);

if (!$storage instanceof Storage) {
    echo "Storage not found" . PHP_EOL;
    echo '</pre>';
    return;
}

$root = $storage->getRootObject();

if (!$root instanceof Folder) {
    echo "Root folder not found" . PHP_EOL;
    echo '</pre>';
    return;
}

echo "ROOT_ID: " . $root->getId() . PHP_EOL;
echo "ROOT_NAME: " . $root->getName() . PHP_EOL;
echo PHP_EOL;
echo "CHILD FOLDERS:" . PHP_EOL;

$children = $root->getChildren(\Bitrix\Disk\Driver::getInstance()->getFakeSecurityContext($userId));

foreach ($children as $child) {
    if ($child instanceof Folder) {
        echo 'ID=' . $child->getId() . ' | NAME=' . $child->getName() . PHP_EOL;
    }
}

echo '</pre>';
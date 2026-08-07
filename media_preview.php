<?php

declare(strict_types=1);

/*
 * Лёгкий совместимый маршрут для старых ссылок SiteBuilder.
 *
 * Старые блоки могли хранить URL вида:
 * /local/sitebuilder/media_preview.php?siteId=13&fileId=554
 *
 * Здесь не загружается ядро Битрикса и не читается физический файл.
 * Файл передаётся штатному обработчику Bitrix.Disk, который сам
 * проверяет авторизацию и права доступа к объекту Диска.
 */

$fileId = (int)($_GET['fileId'] ?? 0);

if ($fileId <= 0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo 'MEDIA_NOT_AVAILABLE';
    exit;
}

$target = '/bitrix/tools/disk/downloadFile.php?objectId=' . $fileId;

header('Cache-Control: private, max-age=300, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Location: ' . $target, true, 302);
exit;

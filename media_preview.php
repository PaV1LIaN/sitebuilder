<?php

declare(strict_types=1);

/*
 * Legacy endpoint.
 *
 * Актуальные URL изображений SiteBuilder получает через
 * Bitrix\Disk\UrlManager в API file.list/block.list.
 * Этот маршрут оставлен только чтобы старый кеш не приводил
 * к 502 или к неверному downloadFile.php.
 */

http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

echo 'MEDIA_PREVIEW_LEGACY_DISABLED';
exit;
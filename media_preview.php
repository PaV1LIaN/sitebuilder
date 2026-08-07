<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT']
    . '/bitrix/modules/main/include/prolog_before.php';

require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/auth.php';

require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/lib.php';

require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/access.php';

require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/disk.php';

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;

sitebuilder_require_auth();

try {
    $siteId = (int)($_GET['siteId'] ?? 0);
    $fileId = (int)($_GET['fileId'] ?? 0);

    if ($siteId <= 0 || $fileId <= 0) {
        throw new RuntimeException(
            'INVALID_MEDIA_REQUEST'
        );
    }

    /*
     * Сохраняем проверку прав SiteBuilder.
     */
    sb_require_viewer($siteId);

    /*
     * Нельзя запросить через preview файл
     * из чужой папки сайта.
     */
    if (
        !sb_disk_file_belongs_to_site(
            $siteId,
            $fileId
        )
    ) {
        throw new RuntimeException(
            'MEDIA_NOT_IN_SITE'
        );
    }

    $file = sb_disk_load_file_by_id($fileId);

    if (!$file instanceof File) {
        throw new RuntimeException(
            'MEDIA_NOT_FOUND'
        );
    }

    /*
     * В preview разрешаем только изображения.
     */
    $extension = strtolower(
        pathinfo(
            (string)$file->getName(),
            PATHINFO_EXTENSION
        )
    );

    $allowedExtensions = [
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'svg',
    ];

    if (
        !in_array(
            $extension,
            $allowedExtensions,
            true
        )
    ) {
        throw new RuntimeException(
            'MEDIA_TYPE_NOT_PREVIEWABLE'
        );
    }

    /*
     * ВАЖНО:
     * больше не читаем /upload/... через
     * is_file(), filesize() и readfile().
     *
     * Файл отдаёт штатный механизм
     * Битрикс.Диска.
     */
    $driver = Driver::getInstance();
    $urlManager = $driver->getUrlManager();

    $previewUrl = '';

    if (
        method_exists(
            $urlManager,
            'getUrlForShowFile'
        )
    ) {
        $previewUrl = (string)
            $urlManager->getUrlForShowFile(
                $file
            );
    }

    /*
     * Fallback для версий Битрикса,
     * где URL просмотра недоступен.
     */
    if (
        $previewUrl === ''
        && method_exists(
            $urlManager,
            'getUrlForDownloadFile'
        )
    ) {
        $previewUrl = (string)
            $urlManager->getUrlForDownloadFile(
                $file
            );
    }

    if (
        $previewUrl === ''
        || preg_match(
            '/[\r\n]/',
            $previewUrl
        )
    ) {
        throw new RuntimeException(
            'MEDIA_PREVIEW_URL_NOT_AVAILABLE'
        );
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header(
        'Cache-Control: private, '
        . 'max-age=300, must-revalidate'
    );

    header(
        'X-Content-Type-Options: nosniff'
    );

    header(
        'Location: ' . $previewUrl,
        true,
        302
    );

    exit;
} catch (Throwable $e) {
    error_log(
        'SiteBuilder media preview failed: '
        . $e->getMessage()
    );

    http_response_code(404);

    header(
        'Content-Type: '
        . 'text/plain; charset=UTF-8'
    );

    header(
        'X-Content-Type-Options: nosniff'
    );

    echo 'MEDIA_NOT_AVAILABLE';
    exit;
}

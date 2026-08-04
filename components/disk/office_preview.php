<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

sitebuilder_require_auth();

require_once __DIR__ . '/bootstrap.php';

use Bitrix\Disk\File;

try {
    $siteId = (int)($_GET['siteId'] ?? 0);
    $pageId = (int)($_GET['pageId'] ?? 0);
    $blockId = (int)($_GET['blockId'] ?? 0);
    $fileId = (int)($_GET['fileId'] ?? 0);

    $currentUserId = DiskCurrentUser::requireId();

    $context = DiskContextFactory::fromArray([
        'siteId' => $siteId,
        'pageId' => $pageId,
        'blockId' => $blockId,
        'currentUserId' => $currentUserId,
    ]);

    DiskValidator::assertContext($context);

    $settings = DiskSettingsRepository::ensureExistsForBlock(
        $context->blockId,
        $context->siteId,
        $context->pageId,
        $context->currentUserId
    );

    $rootInfo = DiskRootResolver::resolveWithSource($context, $settings, false);
    DiskValidator::assertFileInsideRoot(
        $fileId,
        $rootInfo['rootFolderId'],
        $context
    );
    DiskValidator::assertCanForItemParent(
        $context,
        $settings,
        'file',
        $fileId,
        (int)$rootInfo['rootFolderId'],
        'canView'
    );

    $file = File::loadById($fileId);
    if (!$file instanceof File) {
        throw new RuntimeException('DISK_FILE_NOT_FOUND');
    }

    // Универсальный безопасный вариант:
    // открываем стандартную страницу Disk, а Bitrix сам решит,
    // показать preview, Office Online или скачать файл.
    $url = '/company/personal/user/' . $currentUserId . '/disk/path/'
        . rawurlencode($file->getName())
        . '?objectId=' . (int)$file->getId()
        . '&IFRAME=Y';

    LocalRedirect($url);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
    exit;
}

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
    $mode = (string)($_GET['mode'] ?? 'view');

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
        $mode === 'edit' ? 'canUpload' : 'canView'
    );

    $file = File::loadById($fileId);
    if (!$file instanceof File) {
        throw new RuntimeException('DISK_FILE_NOT_FOUND');
    }

    $fileName = (string)$file->getName();

    if ($mode === 'edit') {
        $url = '/disk/path/' . rawurlencode($fileName) . '?objectId=' . (int)$fileId . '&action=edit';
    } else {
        $url = '/disk/path/' . rawurlencode($fileName) . '?objectId=' . (int)$fileId;
    }

    LocalRedirect($url);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
    exit;
}

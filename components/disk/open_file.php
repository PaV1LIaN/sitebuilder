<?php

require_once __DIR__ . '/bootstrap.php';
sitebuilder_require_auth();

header('Cache-Control: private, no-store');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

try {
    $context = DiskContextFactory::fromArray([
        'siteId' => (int)($_GET['siteId'] ?? 0),
        'pageId' => (int)($_GET['pageId'] ?? 0),
        'blockId' => (int)($_GET['blockId'] ?? 0),
        'currentUserId' => DiskCurrentUser::requireId(),
    ]);
    $fileId = (int)($_GET['fileId'] ?? 0);

    DiskValidator::assertContext($context);
    if (!PageAccessService::canViewPage($context->siteId, $context->pageId, $context->currentUserId)) {
        throw new RuntimeException('ACCESS_DENIED');
    }

    $settings = DiskSettingsRepository::getByBlockId($context->blockId);
    $rootFolderId = DiskRootResolver::resolve($context, $settings, false);
    DiskValidator::assertFileInsideRoot($fileId, $rootFolderId, $context);
    DiskValidator::assertCanForItemParent($context, $settings, 'file', $fileId, (int)$rootFolderId, 'canView');

    $adapter = new DiskBitrixStorageAdapter($context->currentUserId);
    $view = $adapter->getDirectFileView($fileId);

    if (!$view['office']) {
        LocalRedirect($view['url']);
        exit;
    }
} catch (Throwable $e) {
    error_log('SiteBuilder shared file: ' . $e->getMessage());
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Файл недоступен. Проверьте права доступа или обратитесь к владельцу сайта.';
    exit;
}

$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $escape($view['name']) ?></title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f8fafc; color: #172033; font: 15px/1.5 Arial, sans-serif; }
        main { width: min(480px, calc(100% - 48px)); padding: 24px; text-align: center; overflow-wrap: anywhere; }
        h1 { font-size: 20px; }
        a { color: #2563eb; }
    </style>
</head>
<body>
    <main id="sb-disk-file-open"
          data-url="<?= $escape($view['url']) ?>"
          data-sessid="<?= $escape(bitrix_sessid()) ?>"
          data-site-id="<?= $escape(defined('SITE_ID') ? SITE_ID : '') ?>">
        <h1><?= $escape($view['name']) ?></h1>
        <p data-open-status role="status">Открываю файл…</p>
        <a data-open-auth hidden>Авторизоваться в сервисе документов</a>
        <noscript>Для открытия документа включите JavaScript в браузере.</noscript>
    </main>
    <script src="/local/sitebuilder/components/disk/open_file.js?v=1"></script>
</body>
</html>

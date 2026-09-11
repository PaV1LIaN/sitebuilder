<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/DiskSharedFolderService.php';
sitebuilder_require_auth();

header('Cache-Control: private, no-store');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=UTF-8');

$view = null;
try {
    $context = DiskContextFactory::fromArray([
        'siteId' => (int)($_GET['siteId'] ?? 0),
        'pageId' => (int)($_GET['pageId'] ?? 0),
        'blockId' => (int)($_GET['blockId'] ?? 0),
        'currentUserId' => DiskCurrentUser::requireId(),
    ]);
    $folderId = (int)($_GET['folderId'] ?? 0);
    $currentFolderId = (int)($_GET['currentFolderId'] ?? $folderId);
    $view = DiskSharedFolderService::buildView($context, $folderId, $currentFolderId);
} catch (Throwable $e) {
    error_log('SiteBuilder shared folder: ' . $e->getMessage());
    http_response_code(403);
}

$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$title = $view ? (string)$view['folder']['name'] : 'Папка недоступна';
if ($view) {
    $contextQuery = ['siteId' => $context->siteId, 'pageId' => $context->pageId, 'blockId' => $context->blockId];
    $folderUrl = static fn(int $id): string => 'open_folder.php?' . http_build_query($contextQuery + [
        'folderId' => $folderId,
        'currentFolderId' => $id,
    ]);
    $fileUrl = static fn(int $id): string => 'open_file.php?' . http_build_query($contextQuery + ['fileId' => $id]);
    $formatSize = static function (int $bytes): string {
        $value = max(0, $bytes);
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return number_format($value, $unit > 0 ? 1 : 0, ',', ' ') . ' ' . $units[$unit];
    };
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $escape($title) ?></title>
    <link rel="stylesheet" href="open_folder.css?v=1">
</head>
<body>
<main class="sb-shared-folder">
    <?php if ($view): ?>
        <nav class="sb-shared-folder__breadcrumbs" aria-label="Путь к папке">
            <ol>
                <?php foreach ($view['breadcrumbs'] as $crumb): ?>
                    <li>
                        <?php if ($crumb['id'] === $currentFolderId): ?>
                            <span aria-current="page"><?= $escape($crumb['name']) ?></span>
                        <?php else: ?>
                            <a href="<?= $escape($folderUrl($crumb['id'])) ?>"><?= $escape($crumb['name']) ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>
    <header class="sb-shared-folder__header">
        <p class="sb-shared-folder__label">Папка</p>
        <h1><?= $escape($title) ?></h1>
        <?php if ($view): ?><p>Выберите файл для просмотра или откройте вложенную папку.</p><?php endif; ?>
    </header>
    <?php if (!$view): ?>
        <p class="sb-shared-folder__empty" role="alert">Проверьте права доступа или обратитесь к владельцу сайта. Возможно, папка удалена или перемещена.</p>
    <?php elseif (!$view['items']): ?>
        <p class="sb-shared-folder__empty">В этой папке нет доступных файлов или вложенных папок.</p>
    <?php else: ?>
        <div class="sb-shared-folder__list">
            <table>
                <caption class="sb-visually-hidden">Содержимое папки <?= $escape($title) ?></caption>
                <thead><tr><th scope="col">Название</th><th scope="col">Размер</th><th scope="col" class="sb-shared-folder__date">Изменено</th></tr></thead>
                <tbody>
                <?php foreach ($view['items'] as $item): ?>
                    <?php $isFolder = $item['entityType'] === 'folder'; ?>
                    <tr>
                        <td>
                            <a class="sb-shared-folder__item" href="<?= $escape($isFolder ? $folderUrl((int)$item['id']) : $fileUrl((int)$item['id'])) ?>">
                                <span class="sb-shared-folder__icon" aria-hidden="true"><?= $isFolder ? '📁' : '📄' ?></span>
                                <span><span class="sb-visually-hidden"><?= $isFolder ? 'Папка: ' : 'Файл: ' ?></span><?= $escape($item['name']) ?></span>
                            </a>
                        </td>
                        <td class="sb-shared-folder__size"><?= $isFolder ? '—' : $escape($formatSize((int)$item['size'])) ?></td>
                        <td class="sb-shared-folder__date"><?= $escape($item['updatedAt'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
</body>
</html>

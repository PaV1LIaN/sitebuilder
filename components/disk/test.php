<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/components/disk/class.php';

$currentUserId = DiskCurrentUser::requireId();

$siteId = 1;
$pageId = 1;
$blockId = 1;

echo '<pre>';
echo 'USER_ID=' . $currentUserId . PHP_EOL;
echo 'ROLE=';
var_dump(SiteAccessRepository::getUserRole($siteId, $currentUserId));
echo 'BLOCK=';
var_dump(BlockRepository::getDiskBlockByContext($siteId, $pageId, $blockId));
echo '</pre>';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Тест компонента Disk</title>
    <link rel="stylesheet" href="/local/sitebuilder/components/disk/styles.css">
</head>
<body style="margin:0; padding:24px; background:#f5f7fb;">
    <div style="max-width:1200px; margin:0 auto;">
        <?php
        $component = new SitebuilderDiskComponent([
            'SITE_ID' => $siteId,
            'PAGE_ID' => $pageId,
            'BLOCK_ID' => $blockId,
            'CURRENT_USER_ID' => $currentUserId,
        ]);
        $component->execute();
        ?>
    </div>

    <script src="/bitrix/js/main/core/core.js"></script>
    <script src="/local/sitebuilder/components/disk/script.js"></script>
</body>
</html>
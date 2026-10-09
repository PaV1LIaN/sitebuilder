<?php
$listContext = [
    'siteId' => (int)($context['siteId'] ?? 0),
    'pageId' => (int)($block['pageId'] ?? $context['pageId'] ?? 0),
    'blockId' => (int)($block['id'] ?? 0),
    'sessid' => bitrix_sessid(),
    'basePath' => (string)($context['basePath'] ?? '/local/sitebuilder'),
    'view' => $content,
];
?>
<section class="sb-data-list" data-list-context="<?= sb_public_h(json_encode($listContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" aria-label="Список">
    <p role="status">Загрузка списка…</p>
</section>

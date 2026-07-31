<?php
$columns = is_array($content['columns'] ?? null) ? array_slice($content['columns'], 0, 6) : [];
$style = sb_public_safe_choice($props['style'] ?? 'dark', ['dark','light','accent'], 'dark');
?>
<footer class="sb-footer-block sb-footer-block--<?= sb_public_h($style) ?>">
    <div class="sb-footer-block__about"><strong><?= sb_public_h(mb_substr((string)($content['brand']??''),0,160)) ?></strong><?php if (!empty($content['text'])): ?><p><?= sb_public_h(mb_substr((string)$content['text'],0,1000)) ?></p><?php endif; ?></div>
    <?php foreach ($columns as $column): ?><div class="sb-footer-block__column"><h3><?= sb_public_h(mb_substr((string)($column['title']??''),0,120)) ?></h3><?php foreach (array_slice(is_array($column['links']??null)?$column['links']:[],0,20) as $link): $href=sb_public_safe_url($link['href']??''); ?><a href="<?= sb_public_h($href ?: '#') ?>"><?= sb_public_h(mb_substr((string)($link['label']??'Ссылка'),0,120)) ?></a><?php endforeach; ?></div><?php endforeach; ?>
    <?php if (!empty($content['copyright'])): ?><div class="sb-footer-block__copyright"><?= sb_public_h(mb_substr((string)$content['copyright'],0,500)) ?></div><?php endif; ?>
</footer>

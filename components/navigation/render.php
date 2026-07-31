<?php
$brand = trim((string)($content['brand'] ?? ''));
$links = is_array($content['links'] ?? null) ? array_slice($content['links'], 0, 20) : [];
$style = sb_public_safe_choice($props['style'] ?? 'light', ['light','dark','transparent'], 'light');
?>
<nav class="sb-nav-block sb-nav-block--<?= sb_public_h($style) ?>">
    <?php if ($brand !== ''): ?><strong class="sb-nav-block__brand"><?= sb_public_h($brand) ?></strong><?php endif; ?>
    <div class="sb-nav-block__links"><?php foreach ($links as $link): $href=sb_public_safe_url($link['href']??''); ?><a href="<?= sb_public_h($href ?: '#') ?>"><?= sb_public_h(mb_substr((string)($link['label']??'Ссылка'),0,120)) ?></a><?php endforeach; ?></div>
    <?php if (!empty($content['ctaLabel'])): $href=sb_public_safe_url($content['ctaHref']??''); ?><a class="sb-btn-public" href="<?= sb_public_h($href ?: '#') ?>"><?= sb_public_h(mb_substr((string)$content['ctaLabel'],0,100)) ?></a><?php endif; ?>
</nav>

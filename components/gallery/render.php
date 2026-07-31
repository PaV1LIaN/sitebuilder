<?php
$items = is_array($content['items'] ?? null) ? array_slice($content['items'], 0, 50) : [];
$columns = sb_public_clamp_int($props['columns'] ?? $content['columns'] ?? 3, 1, 6);
$gap = sb_public_clamp_int($props['gap'] ?? $content['gap'] ?? 16, 0, 64);
$ratio = sb_public_safe_choice($props['ratio'] ?? '4:3', ['auto','16:9','4:3','1:1','3:4'], '4:3');
?>
<div class="sb-gallery sb-gallery--<?= sb_public_h(str_replace(':','-', $ratio)) ?>" style="--sb-gallery-columns:<?= $columns ?>;--sb-gallery-gap:<?= $gap ?>px">
<?php foreach ($items as $item):
    $src = sb_public_safe_url($item['src'] ?? $item['imageSrc'] ?? '', true);
    if ($src === '') continue;
    $alt = mb_substr((string)($item['alt'] ?? $item['caption'] ?? ''),0,300);
    $caption = mb_substr(trim((string)($item['caption'] ?? '')),0,500);
?>
    <figure class="sb-gallery__item"><img src="<?= sb_public_h($src) ?>" alt="<?= sb_public_h($alt) ?>" loading="lazy"><?php if ($caption !== ''): ?><figcaption><?= sb_public_h($caption) ?></figcaption><?php endif; ?></figure>
<?php endforeach; ?>
</div>

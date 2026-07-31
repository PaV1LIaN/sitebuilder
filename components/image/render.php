<?php

$src = sb_public_safe_url($content['src'] ?? '', true);
$alt = (string)($content['alt'] ?? '');
$caption = trim((string)($content['caption'] ?? ''));
$href = sb_public_safe_url($content['href'] ?? '');
$fit = sb_public_safe_choice($props['fit'] ?? 'cover', ['cover', 'contain', 'fill', 'none'], 'cover');
$ratio = sb_public_safe_choice($props['ratio'] ?? 'auto', ['auto', '16:9', '4:3', '1:1', '3:2'], 'auto');
$align = sb_public_safe_choice($props['align'] ?? 'center', ['left', 'center', 'right'], 'center');
$radius = sb_public_clamp_int($props['radius'] ?? 18, 0, 80);
$width = sb_public_clamp_int($props['width'] ?? 100, 10, 100);
$shadow = !empty($props['shadow']);

$ratioClass = str_replace(':', '-', $ratio);
$classes = [
    'sb-media',
    'sb-media--align-' . $align,
    'sb-media--ratio-' . $ratioClass,
];

if ($shadow) {
    $classes[] = 'sb-media--shadow';
}
?>
<figure class="sb-block sb-block--image <?= sb_public_h(implode(' ', $classes)) ?>" style="--sb-media-radius:<?= $radius ?>px;--sb-media-width:<?= $width ?>%;--sb-media-fit:<?= sb_public_h($fit) ?>">
    <?php if ($src !== ''): ?>
        <?php if ($href !== ''): ?>
            <a class="sb-media__link" href="<?= sb_public_h($href) ?>">
        <?php endif; ?>

        <img class="sb-media__image" src="<?= sb_public_h($src) ?>" alt="<?= sb_public_h($alt) ?>" loading="lazy">

        <?php if ($href !== ''): ?>
            </a>
        <?php endif; ?>
    <?php else: ?>
        <div class="sb-media__placeholder">Изображение не задано</div>
    <?php endif; ?>

    <?php if ($caption !== ''): ?>
        <figcaption class="sb-media__caption"><?= sb_public_h($caption) ?></figcaption>
    <?php endif; ?>
</figure>

<?php

$eyebrow = trim((string)($content['eyebrow'] ?? ''));
$title = trim((string)($content['title'] ?? ''));
$text = trim((string)($content['text'] ?? ''));
$primaryLabel = trim((string)($content['primaryLabel'] ?? ''));
$primaryHref = sb_public_safe_url($content['primaryHref'] ?? '#');
$secondaryLabel = trim((string)($content['secondaryLabel'] ?? ''));
$secondaryHref = sb_public_safe_url($content['secondaryHref'] ?? '#');
$imageSrc = sb_public_safe_url($content['imageSrc'] ?? '', true);
$imageAlt = (string)($content['imageAlt'] ?? '');

$theme = sb_public_safe_choice($props['theme'] ?? 'light', ['light', 'dark', 'accent', 'soft'], 'light');
$align = sb_public_safe_choice($props['align'] ?? 'left', ['left', 'center'], 'left');
$imagePosition = sb_public_safe_choice($props['imagePosition'] ?? 'right', ['right', 'left', 'background', 'none'], 'right');
$minHeight = sb_public_clamp_int($props['minHeight'] ?? 380, 220, 900);
$radius = sb_public_clamp_int($props['radius'] ?? 28, 0, 80);
$backgroundColor = sb_public_safe_color($props['backgroundColor'] ?? '', '');
$textColor = sb_public_safe_color($props['textColor'] ?? '', '');

$styles = [
    '--sb-hero-min-height:' . $minHeight . 'px',
    '--sb-hero-radius:' . $radius . 'px',
];

if ($backgroundColor !== '') {
    $styles[] = '--sb-hero-background:' . $backgroundColor;
}

if ($textColor !== '') {
    $styles[] = '--sb-hero-color:' . $textColor;
}

if ($imagePosition === 'background' && $imageSrc !== '') {
    $styles[] = 'background-image:linear-gradient(rgba(15,23,42,.64),rgba(15,23,42,.64)),url("' . $imageSrc . '")';
}

$classes = [
    'sb-block',
    'sb-block--hero',
    'sb-hero',
    'sb-hero--' . $theme,
    'sb-hero--align-' . $align,
    'sb-hero--image-' . $imagePosition,
];
?>
<section class="<?= sb_public_h(implode(' ', $classes)) ?>" style="<?= sb_public_h(implode(';', $styles)) ?>">
    <div class="sb-hero__content">
        <?php if ($eyebrow !== ''): ?>
            <div class="sb-hero__eyebrow"><?= sb_public_h($eyebrow) ?></div>
        <?php endif; ?>

        <?php if ($title !== ''): ?>
            <h2 class="sb-hero__title"><?= sb_public_h($title) ?></h2>
        <?php endif; ?>

        <?php if ($text !== ''): ?>
            <div class="sb-hero__text"><?= nl2br(sb_public_h($text)) ?></div>
        <?php endif; ?>

        <?php if ($primaryLabel !== '' || $secondaryLabel !== ''): ?>
            <div class="sb-hero__actions">
                <?php if ($primaryLabel !== ''): ?>
                    <a class="sb-button sb-button--primary sb-button--large" href="<?= sb_public_h($primaryHref !== '' ? $primaryHref : '#') ?>">
                        <?= sb_public_h($primaryLabel) ?>
                    </a>
                <?php endif; ?>

                <?php if ($secondaryLabel !== ''): ?>
                    <a class="sb-button sb-button--outline sb-button--large" href="<?= sb_public_h($secondaryHref !== '' ? $secondaryHref : '#') ?>">
                        <?= sb_public_h($secondaryLabel) ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (in_array($imagePosition, ['right', 'left'], true) && $imageSrc !== ''): ?>
        <div class="sb-hero__media">
            <img src="<?= sb_public_h($imageSrc) ?>" alt="<?= sb_public_h($imageAlt) ?>" loading="lazy">
        </div>
    <?php endif; ?>
</section>

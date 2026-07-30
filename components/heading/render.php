<?php

$level = strtolower((string)($props['level'] ?? $content['level'] ?? 'h2'));

if (!in_array($level, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
    $level = 'h2';
}

$text = sb_public_h((string)($content['text'] ?? ''));
$align = sb_public_safe_choice($props['align'] ?? $content['align'] ?? 'left', ['left', 'center', 'right'], 'left');
$color = sb_public_safe_color($props['color'] ?? $content['color'] ?? '', '');
$size = sb_public_clamp_int($props['size'] ?? $content['size'] ?? 0, 0, 120);
$maxWidth = sb_public_clamp_int($props['maxWidth'] ?? $content['maxWidth'] ?? 0, 0, 1800);
$weight = sb_public_clamp_int($props['weight'] ?? $content['weight'] ?? 700, 300, 900);

$style = [
    'text-align:' . $align,
    'font-weight:' . $weight,
];

if ($color !== '') {
    $style[] = 'color:' . $color;
}

if ($size > 0) {
    $style[] = 'font-size:' . $size . 'px';
}

if ($maxWidth > 0) {
    $style[] = 'max-width:' . $maxWidth . 'px';
    $style[] = $align === 'center' ? 'margin-left:auto;margin-right:auto' : '';
}
?>
<section class="sb-block sb-block--heading">
    <<?= $level ?> class="sb-heading sb-heading--<?= sb_public_h($level) ?>" style="<?= sb_public_h(implode(';', array_filter($style))) ?>">
        <?= $text ?>
    </<?= $level ?>>
</section>

<?php

$raw = $content['text'] ?? $content['html'] ?? $content['content'] ?? '';

if (is_array($raw)) {
    $raw = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$text = (string)$raw;
$align = sb_public_safe_choice($props['align'] ?? $content['align'] ?? 'left', ['left', 'center', 'right', 'justify'], 'left');
$size = sb_public_clamp_int($props['size'] ?? $content['size'] ?? 16, 12, 72);
$lineHeightRaw = (float)($props['lineHeight'] ?? $content['lineHeight'] ?? 1.65);
$lineHeight = max(1.0, min(2.4, $lineHeightRaw));
$color = sb_public_safe_color($props['color'] ?? $content['color'] ?? '', '');
$maxWidth = sb_public_clamp_int($props['maxWidth'] ?? $content['maxWidth'] ?? 0, 0, 1800);

$safeText = sb_public_sanitize_rich_html($text);

$style = [
    'text-align:' . $align,
    'font-size:' . $size . 'px',
    'line-height:' . $lineHeight,
];

if ($color !== '') {
    $style[] = 'color:' . $color;
}

if ($maxWidth > 0) {
    $style[] = 'max-width:' . $maxWidth . 'px';
    if ($align === 'center') {
        $style[] = 'margin-left:auto';
        $style[] = 'margin-right:auto';
    }
}
?>
<div class="sb-block sb-block--text">
    <div class="sb-text sb-text-block" style="<?= sb_public_h(implode(';', $style)) ?>">
        <?= $safeText ?>
    </div>
</div>

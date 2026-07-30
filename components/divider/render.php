<?php
$label = trim((string)($content['label'] ?? ''));
$style = sb_public_safe_choice($props['style'] ?? 'solid', ['solid', 'dashed', 'gradient', 'dots'], 'solid');
$color = sb_public_safe_color($props['color'] ?? '', '');
$thickness = sb_public_clamp_int($props['thickness'] ?? 1, 1, 8);
$width = sb_public_clamp_int($props['width'] ?? 100, 10, 100);
$margin = sb_public_clamp_int($props['margin'] ?? 24, 0, 160);
$css = '--sb-divider-width:' . $width . '%;--sb-divider-thickness:' . $thickness . 'px;--sb-divider-margin:' . $margin . 'px';
if ($color !== '') {
    $css .= ';--sb-divider-color:' . $color;
}
?>
<div class="sb-block sb-block--divider sb-divider sb-divider--<?= sb_public_h($style) ?>" style="<?= sb_public_h($css) ?>">
    <span class="sb-divider__line"></span>
    <?php if ($label !== ''): ?><span class="sb-divider__label"><?= sb_public_h($label) ?></span><?php endif; ?>
    <?php if ($label !== ''): ?><span class="sb-divider__line"></span><?php endif; ?>
</div>

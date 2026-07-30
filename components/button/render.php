<?php

$textValue = (string)($content['text'] ?? $content['label'] ?? 'Кнопка');
$text = sb_public_h($textValue !== '' ? $textValue : 'Кнопка');
$hrefRaw = sb_public_safe_url($content['href'] ?? '#');
$href = sb_public_h($hrefRaw !== '' ? $hrefRaw : '#');
$target = sb_public_safe_target($content['target'] ?? '_self');
$align = sb_public_safe_choice($props['align'] ?? $content['align'] ?? 'left', ['left', 'center', 'right'], 'left');
$style = sb_public_safe_choice($props['style'] ?? $content['style'] ?? 'primary', ['primary', 'secondary', 'outline', 'ghost'], 'primary');
$size = sb_public_safe_choice($props['size'] ?? 'medium', ['small', 'medium', 'large'], 'medium');
$fullWidth = !empty($props['fullWidth']);
?>
<section class="sb-block sb-block--button">
    <div class="sb-button-wrap sb-button-wrap--<?= sb_public_h($align) ?>">
        <a
            class="sb-button sb-button--<?= sb_public_h($style) ?> sb-button--<?= sb_public_h($size) ?><?= $fullWidth ? ' sb-button--full' : '' ?>"
            href="<?= $href ?>"
            target="<?= sb_public_h($target) ?>"
            <?= $target === '_blank' ? 'rel="noopener noreferrer"' : '' ?>
        >
            <?= $text ?>
        </a>
    </div>
</section>

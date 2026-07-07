<?php

$level = strtolower((string)($content['level'] ?? 'h2'));

if (!in_array($level, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
    $level = 'h2';
}

$text = sb_public_h((string)($content['text'] ?? ''));

$align = (string)($content['align'] ?? 'left');

if (!in_array($align, ['left', 'center', 'right'], true)) {
    $align = 'left';
}
?>

<section class="sb-block sb-block--heading">
    <<?= $level ?> class="sb-heading sb-heading--<?= sb_public_h($level) ?>" style="text-align:<?= sb_public_h($align) ?>;">
        <?= $text ?>
    </<?= $level ?>>
</section>
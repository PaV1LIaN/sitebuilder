<?php

$text = sb_public_h((string)($content['text'] ?? 'Кнопка'));
$href = sb_public_h((string)($content['href'] ?? '#'));
$target = sb_public_h((string)($content['target'] ?? '_self'));

$align = (string)($content['align'] ?? 'left');

if (!in_array($align, ['left', 'center', 'right'], true)) {
    $align = 'left';
}
?>

<section class="sb-block sb-block--button">
    <div class="sb-button-wrap" style="text-align:<?= sb_public_h($align) ?>;">
        <a class="sb-button" href="<?= $href ?>" target="<?= $target ?>">
            <?= $text ?>
        </a>
    </div>
</section>
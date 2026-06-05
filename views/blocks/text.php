<?php
$text = (string)($content['text'] ?? '');

if ($text === '') {
    return;
}
?>

<div class="sb-public-block sb-public-block--text">
    <div class="sb-public-text">
        <?= nl2br(sb_public_h($text)) ?>
    </div>
</div>
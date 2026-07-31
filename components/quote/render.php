<?php
$text = trim((string)($content['text'] ?? ''));
$author = trim((string)($content['author'] ?? ''));
$role = trim((string)($content['role'] ?? ''));
$style = sb_public_safe_choice($props['style'] ?? 'accent', ['accent', 'soft', 'minimal', 'dark'], 'accent');
$align = sb_public_safe_choice($props['align'] ?? 'left', ['left', 'center'], 'left');
$accent = sb_public_safe_color($props['accentColor'] ?? '', '');
?>
<figure class="sb-block sb-block--quote sb-quote sb-quote--<?= sb_public_h($style) ?> sb-quote--align-<?= sb_public_h($align) ?>"<?= $accent !== '' ? ' style="--sb-quote-accent:' . sb_public_h($accent) . '"' : '' ?>>
    <blockquote class="sb-quote__text"><?= nl2br(sb_public_h($text)) ?></blockquote>
    <?php if ($author !== '' || $role !== ''): ?>
        <figcaption class="sb-quote__author">
            <?php if ($author !== ''): ?><strong><?= sb_public_h($author) ?></strong><?php endif; ?>
            <?php if ($role !== ''): ?><span><?= sb_public_h($role) ?></span><?php endif; ?>
        </figcaption>
    <?php endif; ?>
</figure>

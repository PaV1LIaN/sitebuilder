<?php
$title = trim((string)($content['title'] ?? ''));
$items = is_array($content['items'] ?? null) ? $content['items'] : [];
$columns = sb_public_clamp_int($props['columns'] ?? 3, 1, 4);
$style = sb_public_safe_choice($props['style'] ?? 'cards', ['cards', 'line', 'plain', 'accent'], 'cards');
?>
<section class="sb-block sb-block--stats sb-stats sb-stats--<?= sb_public_h($style) ?>" style="--sb-stats-columns:<?= $columns ?>">
    <?php if ($title !== ''): ?>
        <h2 class="sb-stats__title"><?= sb_public_h($title) ?></h2>
    <?php endif; ?>

    <div class="sb-stats__grid">
        <?php foreach (array_slice($items, 0, 16) as $item): ?>
            <?php $item = is_array($item) ? $item : []; ?>
            <div class="sb-stat">
                <div class="sb-stat__value"><?= sb_public_h((string)($item['value'] ?? '')) ?></div>
                <div class="sb-stat__label"><?= sb_public_h((string)($item['label'] ?? '')) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

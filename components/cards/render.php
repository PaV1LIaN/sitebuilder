<?php

$title = trim((string)($content['title'] ?? ''));
$items = is_array($content['items'] ?? null) ? $content['items'] : [];
$columns = sb_public_clamp_int($props['columns'] ?? 3, 1, 4);
$style = sb_public_safe_choice($props['style'] ?? 'elevated', ['elevated', 'outlined', 'soft', 'minimal'], 'elevated');
$imageRatio = sb_public_safe_choice($props['imageRatio'] ?? '16:9', ['16:9', '4:3', '1:1', 'auto'], '16:9');
$align = sb_public_safe_choice($props['align'] ?? 'left', ['left', 'center'], 'left');
?>
<section class="sb-block sb-block--cards sb-cards sb-cards--<?= sb_public_h($style) ?> sb-cards--align-<?= sb_public_h($align) ?>" style="--sb-cards-columns:<?= $columns ?>">
    <?php if ($title !== ''): ?>
        <h2 class="sb-cards__title"><?= sb_public_h($title) ?></h2>
    <?php endif; ?>

    <div class="sb-cards__grid">
        <?php foreach (array_slice($items, 0, 24) as $item): ?>
            <?php
            $item = is_array($item) ? $item : [];
            $itemTitle = trim((string)($item['title'] ?? ''));
            $itemText = trim((string)($item['text'] ?? ''));
            $imageSrc = sb_public_safe_url($item['imageSrc'] ?? '', true);
            $href = sb_public_safe_url($item['href'] ?? '');
            $buttonText = trim((string)($item['buttonText'] ?? ''));
            ?>
            <article class="sb-card">
                <?php if ($imageSrc !== ''): ?>
                    <div class="sb-card__media sb-card__media--<?= sb_public_h(str_replace(':', '-', $imageRatio)) ?>">
                        <img src="<?= sb_public_h($imageSrc) ?>" alt="" loading="lazy">
                    </div>
                <?php endif; ?>

                <div class="sb-card__body">
                    <?php if ($itemTitle !== ''): ?>
                        <h3 class="sb-card__title"><?= sb_public_h($itemTitle) ?></h3>
                    <?php endif; ?>

                    <?php if ($itemText !== ''): ?>
                        <div class="sb-card__text"><?= nl2br(sb_public_h($itemText)) ?></div>
                    <?php endif; ?>

                    <?php if ($href !== '' && $buttonText !== ''): ?>
                        <a class="sb-card__link" href="<?= sb_public_h($href) ?>"><?= sb_public_h($buttonText) ?> <span aria-hidden="true">→</span></a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

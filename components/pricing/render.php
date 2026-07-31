<?php
$title = trim((string)($content['title'] ?? ''));
$plans = is_array($content['plans'] ?? null) ? array_slice($content['plans'], 0, 8) : [];
$columns = sb_public_clamp_int($props['columns'] ?? min(3, max(1, count($plans))), 1, 4);
$style = sb_public_safe_choice($props['style'] ?? 'cards', ['cards','outlined','soft'], 'cards');
?>
<section class="sb-pricing sb-pricing--<?= sb_public_h($style) ?>" style="--sb-pricing-columns:<?= $columns ?>">
    <?php if ($title !== ''): ?><h2 class="sb-pricing__title"><?= sb_public_h($title) ?></h2><?php endif; ?>
    <div class="sb-pricing__grid">
    <?php foreach ($plans as $plan):
        $features = is_array($plan['features'] ?? null) ? array_slice($plan['features'], 0, 30) : [];
        $href = sb_public_safe_url($plan['buttonHref'] ?? '');
    ?>
        <article class="sb-pricing-card <?= !empty($plan['featured']) ? 'is-featured' : '' ?>">
            <?php if (!empty($plan['badge'])): ?><span class="sb-pricing-card__badge"><?= sb_public_h(mb_substr((string)$plan['badge'],0,80)) ?></span><?php endif; ?>
            <h3><?= sb_public_h(mb_substr((string)($plan['name'] ?? 'Вариант'),0,160)) ?></h3>
            <div class="sb-pricing-card__price"><?= sb_public_h(mb_substr((string)($plan['price'] ?? ''),0,100)) ?></div>
            <?php if (!empty($plan['description'])): ?><p><?= sb_public_h(mb_substr((string)$plan['description'],0,500)) ?></p><?php endif; ?>
            <ul><?php foreach ($features as $feature): ?><li><?= sb_public_h(mb_substr((string)$feature,0,300)) ?></li><?php endforeach; ?></ul>
            <?php if (!empty($plan['buttonText'])): ?><a class="sb-btn-public" href="<?= sb_public_h($href ?: '#') ?>"><?= sb_public_h(mb_substr((string)$plan['buttonText'],0,100)) ?></a><?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
</section>

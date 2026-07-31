<?php
$items = is_array($content['items'] ?? null) ? array_slice($content['items'], 0, 30) : [];
$title = trim((string)($content['title'] ?? ''));
$style = sb_public_safe_choice($props['style'] ?? 'cards', ['cards','minimal','accent'], 'cards');
?>
<section class="sb-faq sb-faq--<?= sb_public_h($style) ?>">
    <?php if ($title !== ''): ?><h2 class="sb-faq__title"><?= sb_public_h($title) ?></h2><?php endif; ?>
    <div class="sb-faq__items">
        <?php foreach ($items as $index => $item):
            $question = mb_substr(trim((string)($item['question'] ?? '')), 0, 300);
            $answer = mb_substr(trim((string)($item['answer'] ?? '')), 0, 5000);
            if ($question === '') continue;
        ?>
            <details class="sb-faq__item" <?= !empty($props['openFirst']) && $index === 0 ? 'open' : '' ?>>
                <summary><?= sb_public_h($question) ?><span aria-hidden="true">＋</span></summary>
                <div class="sb-faq__answer"><?= sb_public_sanitize_rich_html($answer) ?></div>
            </details>
        <?php endforeach; ?>
    </div>
</section>

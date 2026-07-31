<?php
$url = trim((string)($content['url'] ?? ''));
$title = trim((string)($content['title'] ?? 'Видео'));
$caption = trim((string)($content['caption'] ?? ''));
$poster = sb_public_safe_url($content['poster'] ?? '', true);
$ratio = sb_public_safe_choice($props['ratio'] ?? '16:9', ['16:9','4:3','1:1','9:16'], '16:9');
$autoplay = !empty($props['autoplay']);
$embedUrl = '';
$directUrl = '';

if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,20})#i', $url, $m)) {
    $embedUrl = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($m[1]) . '?rel=0' . ($autoplay ? '&autoplay=1&mute=1' : '');
} elseif (preg_match('#vimeo\.com/(?:video/)?([0-9]{5,15})#i', $url, $m)) {
    $embedUrl = 'https://player.vimeo.com/video/' . rawurlencode($m[1]) . ($autoplay ? '?autoplay=1&muted=1' : '');
} else {
    $safe = sb_public_safe_url($url, true);
    if ($safe !== '' && preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', $safe)) $directUrl = $safe;
}
?>
<figure class="sb-video sb-video--<?= sb_public_h(str_replace(':','-', $ratio)) ?>">
    <?php if ($embedUrl !== ''): ?>
        <iframe src="<?= sb_public_h($embedUrl) ?>" title="<?= sb_public_h($title) ?>" loading="lazy" allow="accelerometer; autoplay; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
    <?php elseif ($directUrl !== ''): ?>
        <video controls playsinline <?= $autoplay ? 'autoplay muted loop' : '' ?> <?= $poster !== '' ? 'poster="' . sb_public_h($poster) . '"' : '' ?>><source src="<?= sb_public_h($directUrl) ?>"></video>
    <?php else: ?>
        <div class="sb-video__empty"><strong><?= sb_public_h($title) ?></strong><span>Укажите ссылку YouTube, Vimeo или прямой URL видео.</span></div>
    <?php endif; ?>
    <?php if ($caption !== ''): ?><figcaption><?= sb_public_h($caption) ?></figcaption><?php endif; ?>
</figure>

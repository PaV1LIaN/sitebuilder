<?php
$desktopHeight = sb_public_clamp_int(
    $content['height'] ?? $props['height'] ?? 40,
    0,
    400
);
$tabletHeight = sb_public_clamp_int(
    $content['tabletHeight'] ?? $props['tabletHeight'] ?? min($desktopHeight, 32),
    0,
    400
);
$mobileHeight = sb_public_clamp_int(
    $content['mobileHeight'] ?? $props['mobileHeight'] ?? min($tabletHeight, 24),
    0,
    400
);
?>
<div
    class="sb-block sb-block--spacer"
    style="--sb-spacer-desktop:<?= $desktopHeight ?>px;--sb-spacer-tablet:<?= $tabletHeight ?>px;--sb-spacer-mobile:<?= $mobileHeight ?>px"
    aria-hidden="true"
></div>

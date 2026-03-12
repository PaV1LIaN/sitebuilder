<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: text/html; charset=UTF-8');

function h($s): string { return htmlspecialcharsbx((string)$s); }

function sb_data_path(string $file): string {
    return $_SERVER['DOCUMENT_ROOT'] . '/upload/sitebuilder/' . $file;
}
function sb_read_json_file(string $file): array {
    $path = sb_data_path($file);
    if (!file_exists($path)) return [];
    $raw = file_get_contents($path);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function sb_read_sites(): array { return sb_read_json_file('sites.json'); }
function sb_read_pages(): array { return sb_read_json_file('pages.json'); }
function sb_read_blocks(): array { return sb_read_json_file('blocks.json'); }
function sb_read_menus(): array { return sb_read_json_file('menus.json'); }
function sb_read_layouts(): array { return sb_read_json_file('layouts.json'); }

function sb_find_layout_record(int $siteId): ?array {
    foreach (sb_read_layouts() as $r) {
        if ((int)($r['siteId'] ?? 0) === $siteId) return $r;
    }
    return null;
}

function sb_layout_zone_blocks(int $siteId, string $zone): array {
    $rec = sb_find_layout_record($siteId);
    if (!$rec) return [];

    $zones = is_array($rec['zones'] ?? null) ? $rec['zones'] : [];
    $blocks = is_array($zones[$zone] ?? null) ? $zones[$zone] : [];

    usort($blocks, fn($a, $b) => (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500));
    return $blocks;
}

function find_site(int $siteId): ?array {
    foreach (sb_read_sites() as $s) {
        if ((int)($s['id'] ?? 0) === $siteId) return $s;
    }
    return null;
}

function find_site_by_slug(string $slug): ?array {
    $slug = trim(mb_strtolower($slug));
    if ($slug === '') return null;

    foreach (sb_read_sites() as $s) {
        if (mb_strtolower((string)($s['slug'] ?? '')) === $slug) return $s;
    }
    return null;
}

function find_page(int $pageId): ?array {
    foreach (sb_read_pages() as $p) {
        if ((int)($p['id'] ?? 0) === $pageId) return $p;
    }
    return null;
}

function is_page_published(array $page): bool {
    $status = strtolower((string)($page['status'] ?? 'published'));
    return $status === 'published';
}

function find_page_by_slug(int $siteId, string $slug): ?array {
    foreach (sb_read_pages() as $p) {
        if ((int)($p['siteId'] ?? 0) !== $siteId) continue;
        if ((string)($p['slug'] ?? '') !== $slug) continue;
        if (!is_page_published($p)) continue;
        return $p;
    }
    return null;
}

function sb_menu_get_site_record(array $all, int $siteId): ?array {
    foreach ($all as $r) {
        if ((int)($r['siteId'] ?? 0) === $siteId) return $r;
    }
    return null;
}

function sb_menu_pick_main(array $siteRecord, array $site): ?array {
    $menus = $siteRecord['menus'] ?? [];
    if (!is_array($menus) || !count($menus)) return null;

    $topMenuId = (int)($site['topMenuId'] ?? 0);
    if ($topMenuId > 0) {
        foreach ($menus as $m) {
            if ((int)($m['id'] ?? 0) === $topMenuId) return $m;
        }
    }

    return $menus[0];
}

function public_page_url(array $site, ?array $page): string {
    $siteId = (int)($site['id'] ?? 0);
    $siteSlug = trim((string)($site['slug'] ?? ''));
    $pageSlug = $page ? trim((string)($page['slug'] ?? '')) : '';

    if ($siteSlug !== '') {
        $url = '/local/sitebuilder/public.php?site=' . urlencode($siteSlug);
        if ($pageSlug !== '') {
            $url .= '&page=' . urlencode($pageSlug);
        }
        return $url;
    }

    $url = '/local/sitebuilder/public.php?siteId=' . $siteId;
    if ($pageSlug !== '') {
        $url .= '&p=' . urlencode($pageSlug);
    }
    return $url;
}

function file_url(int $siteId, int $fileId): string {
    return '/local/sitebuilder/download.php?siteId=' . $siteId . '&fileId=' . $fileId;
}

function public_not_found(string $title = 'Страница не найдена', string $text = 'Запрошенная страница недоступна или не опубликована.'): void {
    http_response_code(404);
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
      <meta charset="utf-8" />
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <title><?= htmlspecialcharsbx($title) ?></title>
      <style>
        body{
          margin:0;
          font-family:Arial,sans-serif;
          background:#f8fafc;
          color:#111827;
          display:flex;
          align-items:center;
          justify-content:center;
          min-height:100vh;
          padding:24px;
          box-sizing:border-box;
        }
        .box{
          max-width:560px;
          width:100%;
          background:#fff;
          border:1px solid #e5e7eb;
          border-radius:20px;
          padding:28px;
          box-shadow:0 10px 30px rgba(0,0,0,.05);
        }
        h1{ margin:0 0 10px; font-size:28px; line-height:1.2; }
        p{ margin:0; color:#6b7280; line-height:1.7; }
        .actions{ margin-top:18px; display:flex; gap:10px; flex-wrap:wrap; }
        a{
          display:inline-flex;
          align-items:center;
          justify-content:center;
          padding:10px 14px;
          border-radius:12px;
          border:1px solid #e5e7eb;
          text-decoration:none;
          color:#111827;
          background:#fff;
        }
        a.primary{ background:#2563eb; border-color:#2563eb; color:#fff; }

        .breadcrumbs{
        display:flex;
        flex-wrap:wrap;
        align-items:center;
        gap:6px;
        margin-bottom:10px;
        font-size:13px;
        color:var(--muted);
        }

        .crumb{
        color:var(--muted);
        text-decoration:none;
        }

        .crumb:hover{
        color:var(--sb-accent);
        text-decoration:none;
        }

        .crumb.current{
        color:var(--text);
        font-weight:600;
        }

        .crumbSep{
        color:#c0c7d1;
        margin:0 2px;
        }
      </style>
    </head>
    <body>
      <div class="box">
        <h1><?= htmlspecialcharsbx($title) ?></h1>
        <p><?= htmlspecialcharsbx($text) ?></p>
        <div class="actions">
          <a href="javascript:history.back()">Назад</a>
          <a class="primary" href="/">На главную</a>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

function sb_render_block(array $b, int $siteId): string {
    ob_start();

    $type = (string)($b['type'] ?? '');
    $c = is_array($b['content'] ?? null) ? $b['content'] : [];
    ?>
    <div class="block">

      <?php if ($type === 'text'): ?>
        <div class="textBlock"><?=h($c['text'] ?? '')?></div>
      <?php endif; ?>

      <?php if ($type === 'heading'): ?>
        <?php
          $lvl = in_array(($c['level'] ?? 'h2'), ['h1','h2','h3'], true) ? $c['level'] : 'h2';
          $al = in_array(($c['align'] ?? 'left'), ['left','center','right'], true) ? $c['align'] : 'left';
        ?>
        <<?=h($lvl)?> style="margin:0; text-align:<?=h($al)?>;">
          <?=h($c['text'] ?? '')?>
        </<?=h($lvl)?>>
      <?php endif; ?>

      <?php if ($type === 'image'): ?>
        <?php $fid = (int)($c['fileId'] ?? 0); ?>
        <?php if ($fid > 0): ?>
          <img src="<?=h(file_url($siteId, $fid))?>" alt="<?=h($c['alt'] ?? '')?>">
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($type === 'button'): ?>
        <?php
          $url = (string)($c['url'] ?? '');
          $txt = (string)($c['text'] ?? 'Open');
          $v = (string)($c['variant'] ?? 'primary');
          $cls = ($v === 'secondary') ? 'secondary' : 'primary';
        ?>
        <a class="btn <?=h($cls)?>"
           href="<?=h($url)?>"
           <?=preg_match('~^https?://~i', $url) ? 'target="_blank" rel="noopener noreferrer"' : ''?>>
          <?=h($txt)?>
        </a>
      <?php endif; ?>

      <?php if ($type === 'columns2'): ?>
        <?php
          $ratio = (string)($c['ratio'] ?? '50-50');
          $tpl = '1fr 1fr';
          if ($ratio === '33-67') $tpl = '1fr 2fr';
          if ($ratio === '67-33') $tpl = '2fr 1fr';
        ?>
        <div class="cols2" style="grid-template-columns:<?=h($tpl)?>;">
          <div><?=h($c['left'] ?? '')?></div>
          <div><?=h($c['right'] ?? '')?></div>
        </div>
      <?php endif; ?>

      <?php if ($type === 'gallery'): ?>
        <?php
          $cols = (int)($c['columns'] ?? 3);
          if (!in_array($cols, [2,3,4], true)) $cols = 3;
          $tpl = ($cols === 2) ? '1fr 1fr' : (($cols === 4) ? '1fr 1fr 1fr 1fr' : '1fr 1fr 1fr');
          $imgs = is_array($c['images'] ?? null) ? $c['images'] : [];
        ?>
        <div class="galleryGrid" style="grid-template-columns:<?=h($tpl)?>;">
          <?php foreach ($imgs as $it): ?>
            <?php
              $fid = (int)($it['fileId'] ?? 0);
              if ($fid <= 0) continue;
            ?>
            <img src="<?=h(file_url($siteId, $fid))?>" alt="<?=h($it['alt'] ?? '')?>">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($type === 'spacer'): ?>
        <?php
          $hgt = (int)($c['height'] ?? 40);
          if ($hgt < 10) $hgt = 10;
          if ($hgt > 200) $hgt = 200;
          $line = !empty($c['line']);
        ?>
        <div style="height:<?= (int)$hgt ?>px; position:relative;">
          <?php if ($line): ?>
            <div style="position:absolute;left:0;right:0;top:50%;height:1px;background:#e5e7ea;"></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($type === 'card'): ?>
        <div class="cardItem">
          <div class="cardTitle"><?=h($c['title'] ?? '')?></div>

          <?php if ((string)($c['text'] ?? '') !== ''): ?>
            <div class="cardText"><?=h($c['text'] ?? '')?></div>
          <?php endif; ?>

          <?php $fid = (int)($c['imageFileId'] ?? 0); ?>
          <?php if ($fid > 0): ?>
            <div style="margin-top:10px;">
              <img src="<?=h(file_url($siteId, $fid))?>" alt="">
            </div>
          <?php endif; ?>

          <?php $url = (string)($c['buttonUrl'] ?? ''); ?>
          <?php if ($url !== ''): ?>
            <a class="btn secondary"
               style="margin-top:10px;"
               href="<?=h($url)?>"
               <?=preg_match('~^https?://~i', $url) ? 'target="_blank" rel="noopener noreferrer"' : ''?>>
              <?=h(($c['buttonText'] ?? 'Открыть'))?>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($type === 'cards'): ?>
        <?php
          $cols = (int)($c['columns'] ?? 3);
          if (!in_array($cols, [2,3,4], true)) $cols = 3;
          $tpl = ($cols === 2) ? '1fr 1fr' : (($cols === 4) ? '1fr 1fr 1fr 1fr' : '1fr 1fr 1fr');
          $items = is_array($c['items'] ?? null) ? $c['items'] : [];
        ?>
        <div class="cardsGrid" style="grid-template-columns:<?=h($tpl)?>;">
          <?php foreach ($items as $it): ?>
            <?php
              if (!is_array($it)) continue;
              $t = trim((string)($it['title'] ?? ''));
              if ($t === '') continue;
            ?>
            <div class="cardItem">
              <div class="cardTitle" style="font-size:16px;"><?=h($t)?></div>

              <?php $tx = (string)($it['text'] ?? ''); ?>
              <?php if ($tx !== ''): ?>
                <div class="cardText"><?=h($tx)?></div>
              <?php endif; ?>

              <?php $fid = (int)($it['imageFileId'] ?? 0); ?>
              <?php if ($fid > 0): ?>
                <div style="margin-top:10px;">
                  <img src="<?=h(file_url($siteId, $fid))?>" alt="">
                </div>
              <?php endif; ?>

              <?php $url = (string)($it['buttonUrl'] ?? ''); ?>
              <?php if ($url !== ''): ?>
                <a class="btn secondary"
                   style="margin-top:10px;"
                   href="<?=h($url)?>"
                   <?=preg_match('~^https?://~i', $url) ? 'target="_blank" rel="noopener noreferrer"' : ''?>>
                  <?=h(($it['buttonText'] ?? 'Открыть'))?>
                </a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
    <?php

    return (string)ob_get_clean();
}

function sb_render_blocks(array $blocks, int $siteId): string {
    $html = '';
    foreach ($blocks as $b) {
        $html .= sb_render_block($b, $siteId);
    }
    return $html;
}

function sb_site_published_pages(int $siteId): array {
    $pages = array_values(array_filter(sb_read_pages(), function($p) use ($siteId) {
        return (int)($p['siteId'] ?? 0) === $siteId && is_page_published($p);
    }));

    usort($pages, function($a, $b) {
        return ((int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500))
            ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
    });

    return $pages;
}

function sb_pages_build_tree(array $pages): array {
    $byParent = [];
    foreach ($pages as $p) {
        $parentId = (int)($p['parentId'] ?? 0);
        $byParent[$parentId][] = $p;
    }

    $build = function(int $parentId) use (&$build, $byParent): array {
        $items = $byParent[$parentId] ?? [];
        $out = [];
        foreach ($items as $p) {
            $node = $p;
            $node['children'] = $build((int)($p['id'] ?? 0));
            $out[] = $node;
        }
        return $out;
    };

    return $build(0);
}

function sb_page_ancestor_ids(array $pages, int $pageId): array {
    $byId = [];
    foreach ($pages as $p) {
        $byId[(int)($p['id'] ?? 0)] = $p;
    }

    $ids = [];
    $current = $pageId;
    $guard = 0;

    while ($current > 0 && isset($byId[$current]) && $guard < 100) {
        $ids[] = $current;
        $current = (int)($byId[$current]['parentId'] ?? 0);
        $guard++;
    }

    return $ids;
}

function sb_page_breadcrumbs(array $pages, int $pageId): array {
    $byId = [];
    foreach ($pages as $p) {
        $byId[(int)($p['id'] ?? 0)] = $p;
    }

    $chain = [];
    $current = $pageId;
    $guard = 0;

    while ($current > 0 && isset($byId[$current]) && $guard < 100) {
        array_unshift($chain, $byId[$current]);
        $current = (int)($byId[$current]['parentId'] ?? 0);
        $guard++;
    }

    return $chain;
}

function sb_render_breadcrumbs(array $items, array $site): string {
    if (!$items) return '';

    $parts = [];
    $lastIndex = count($items) - 1;

    foreach ($items as $i => $p) {
        $title = (string)($p['title'] ?? 'Page');

        if ($i === $lastIndex) {
            $parts[] = '<span class="crumb current">' . h($title) . '</span>';
        } else {
            $parts[] = '<a class="crumb" href="' . h(public_page_url($site, $p)) . '">' . h($title) . '</a>';
        }
    }

    return '<nav class="breadcrumbs">' . implode('<span class="crumbSep">›</span>', $parts) . '</nav>';
}

function sb_render_left_menu_tree(array $nodes, array $site, int $currentPageId, array $activePathIds, int $level = 0): string {
    if (!$nodes) return '';

    $html = '<div class="sideTree level-' . $level . '">';
    foreach ($nodes as $node) {
        $id = (int)($node['id'] ?? 0);
        $title = (string)($node['title'] ?? 'Page');
        $isActive = ($id === $currentPageId);
        $isInPath = in_array($id, $activePathIds, true);
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $hasChildren = count($children) > 0;

        $classes = ['sideMenuLink'];
        if ($isActive) $classes[] = 'active';
        if ($hasChildren) $classes[] = 'hasChildren';
        if ($isInPath) $classes[] = 'open';

        $url = public_page_url($site, $node);

        $html .= '<div class="sideTreeNode level-' . $level . '" data-node-id="' . $id . '" data-open-default="' . ($isInPath ? '1' : '0') . '">';

        if ($hasChildren) {
            $html .= '<div class="sideMenuRow">';
            $html .= '<button type="button" class="sideToggle" aria-expanded="' . ($isInPath ? 'true' : 'false') . '" aria-label="Переключить ветку">';
            $html .= '<span class="sideToggleIcon">' . ($isInPath ? '▾' : '▸') . '</span>';
            $html .= '</button>';
            $html .= '<a class="' . h(implode(' ', $classes)) . '" href="' . h($url) . '">';
            $html .= '<span class="sideMenuText">' . h($title) . '</span>';
            $html .= '</a>';
            $html .= '</div>';

            $html .= '<div class="sideTreeChildren" style="display:' . ($isInPath ? 'block' : 'none') . ';">';
            $html .= sb_render_left_menu_tree($children, $site, $currentPageId, $activePathIds, $level + 1);
            $html .= '</div>';
        } else {
            $html .= '<div class="sideMenuRow">';
            $html .= '<span class="sideToggleStub"></span>';
            $html .= '<a class="' . h(implode(' ', $classes)) . '" href="' . h($url) . '">';
            $html .= '<span class="sideMenuText">' . h($title) . '</span>';
            $html .= '</a>';
            $html .= '</div>';
        }

        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

// -------------------- input --------------------

$siteId = (int)($_GET['siteId'] ?? 0);
$siteSlug = trim((string)($_GET['site'] ?? ''));

$oldPageSlug = trim((string)($_GET['p'] ?? ''));
$newPageSlug = trim((string)($_GET['page'] ?? ''));

$pageSlug = $newPageSlug !== '' ? $newPageSlug : $oldPageSlug;

// -------------------- resolve site --------------------

$site = null;

if ($siteSlug !== '') {
    $site = find_site_by_slug($siteSlug);
} elseif ($siteId > 0) {
    $site = find_site($siteId);
}

if (!$site) {
    public_not_found('Сайт не найден', 'Сайт с таким адресом не существует.');
}

$siteId = (int)($site['id'] ?? 0);

$settings = (isset($site['settings']) && is_array($site['settings'])) ? $site['settings'] : [];

$containerWidth = (int)($settings['containerWidth'] ?? 1100);
if ($containerWidth < 900) $containerWidth = 900;
if ($containerWidth > 1600) $containerWidth = 1600;

$accent = (string)($settings['accent'] ?? '#2563eb');
if (!preg_match('~^#[0-9a-fA-F]{6}$~', $accent)) $accent = '#2563eb';

$logoFileId = (int)($settings['logoFileId'] ?? 0);

$layout = (isset($site['layout']) && is_array($site['layout'])) ? $site['layout'] : [
    'showHeader' => true,
    'showFooter' => true,
    'showLeft' => false,
    'showRight' => false,
    'leftWidth' => 260,
    'rightWidth' => 260,
    'leftMode' => 'blocks',
];

$leftMode = (string)($layout['leftMode'] ?? 'blocks');
if (!in_array($leftMode, ['blocks', 'menu'], true)) $leftMode = 'blocks';

$headerBlocks = !empty($layout['showHeader']) ? sb_layout_zone_blocks($siteId, 'header') : [];
$footerBlocks = !empty($layout['showFooter']) ? sb_layout_zone_blocks($siteId, 'footer') : [];
$leftBlocks   = (!empty($layout['showLeft']) && $leftMode === 'blocks') ? sb_layout_zone_blocks($siteId, 'left') : [];
$rightBlocks  = !empty($layout['showRight']) ? sb_layout_zone_blocks($siteId, 'right') : [];

$leftWidth = (int)($layout['leftWidth'] ?? 260);
$rightWidth = (int)($layout['rightWidth'] ?? 260);

if ($leftWidth < 160) $leftWidth = 160;
if ($leftWidth > 500) $leftWidth = 500;
if ($rightWidth < 160) $rightWidth = 160;
if ($rightWidth > 500) $rightWidth = 500;

// -------------------- current page --------------------

$page = null;

if ($pageSlug !== '') {
    $page = find_page_by_slug($siteId, $pageSlug);
    if (!$page) {
        public_not_found('Страница не найдена', 'Страница с таким адресом отсутствует или ещё не опубликована.');
    }
}

if (!$page) {
    $homeId = (int)($site['homePageId'] ?? 0);
    if ($homeId > 0) {
        $candidate = find_page($homeId);
        if ($candidate && (int)($candidate['siteId'] ?? 0) === $siteId && is_page_published($candidate)) {
            $page = $candidate;
        }
    }
}

if (!$page) {
    $publishedPages = sb_site_published_pages($siteId);
    if ($publishedPages) {
        $page = $publishedPages[0];
    }
}

if (!$page) {
    public_not_found('Страница не найдена', 'У сайта нет опубликованных страниц.');
}

$pageId = (int)($page['id'] ?? 0);

$canonicalUrl = public_page_url($site, $page);
$currentUsesOldSiteId = ($siteSlug === '' && $siteId > 0);
$currentUsesOldPageParam = ($newPageSlug === '' && $oldPageSlug !== '');

if ($pageSlug === '' || $currentUsesOldSiteId || $currentUsesOldPageParam) {
    header('Location: ' . $canonicalUrl, true, 302);
    exit;
}

// -------------------- blocks --------------------

$blocks = array_values(array_filter(sb_read_blocks(), fn($b) => (int)($b['pageId'] ?? 0) === $pageId));
usort($blocks, fn($a, $b) => (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500));

// -------------------- top menu --------------------

$menuItems = [];
$allMenus = sb_read_menus();
$siteRec = sb_menu_get_site_record($allMenus, $siteId);
$mainMenu = $siteRec ? sb_menu_pick_main($siteRec, $site) : null;

if ($mainMenu && is_array($mainMenu['items'] ?? null)) {
    $items = $mainMenu['items'];
    usort($items, fn($a, $b) => (int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0));

    foreach ($items as $it) {
        if (!is_array($it)) continue;

        $type = (string)($it['type'] ?? '');
        $title = trim((string)($it['title'] ?? ''));
        if ($title === '') $title = 'Link';

        $href = '#';
        $isActive = false;

        if ($type === 'page') {
            $pid = (int)($it['pageId'] ?? 0);
            $p = $pid > 0 ? find_page($pid) : null;

            if ($p && (int)($p['siteId'] ?? 0) === $siteId && is_page_published($p)) {
                $href = public_page_url($site, $p);
                $isActive = ((int)($p['id'] ?? 0) === $pageId);
                if ($title === 'Link') $title = (string)($p['title'] ?? 'Page');
            } else {
                continue;
            }
        } elseif ($type === 'url') {
            $url = trim((string)($it['url'] ?? ''));
            if ($url === '') continue;
            $href = $url;
        } else {
            continue;
        }

        $menuItems[] = [
            'title' => $title,
            'href' => $href,
            'active' => $isActive,
        ];
    }
}

// -------------------- left nested menu --------------------

$leftMenuHtml = '';
if (!empty($layout['showLeft']) && $leftMode === 'menu') {
    $publishedPages = sb_site_published_pages($siteId);
    $tree = sb_pages_build_tree($publishedPages);
    $activePathIds = sb_page_ancestor_ids($publishedPages, $pageId);
    $leftMenuHtml = sb_render_left_menu_tree($tree, $site, $pageId, $activePathIds);
}

$publishedPagesForNav = sb_site_published_pages($siteId);
$breadcrumbsItems = sb_page_breadcrumbs($publishedPagesForNav, $pageId);
$breadcrumbsHtml = sb_render_breadcrumbs($breadcrumbsItems, $site);

// -------------------- final html parts --------------------

$pageHtml = sb_render_blocks($blocks, $siteId);
$headerHtml = sb_render_blocks($headerBlocks, $siteId);
$footerHtml = sb_render_blocks($footerBlocks, $siteId);
$leftHtml = ($leftMode === 'menu') ? $leftMenuHtml : sb_render_blocks($leftBlocks, $siteId);
$rightHtml = sb_render_blocks($rightBlocks, $siteId);

$leftCol = ($leftHtml !== '') ? $leftWidth . 'px' : '0px';
$rightCol = ($rightHtml !== '') ? $rightWidth . 'px' : '0px';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?=h($site['name'] ?? 'Site')?> — <?=h($page['title'] ?? '')?></title>
  <style>
    :root{
      --sb-accent: <?= h($accent) ?>;
      --sb-container: <?= (int)$containerWidth ?>px;
      --bg: #ffffff;
      --text: #111827;
      --muted: #6b7280;
      --line: #e5e7eb;
      --soft: #f3f4f6;
      --soft2: #f9fafb;
      --radius: 16px;
      --shadow: 0 1px 2px rgba(0,0,0,.04);
    }

    * { box-sizing: border-box; }

    body{
      font-family: Arial, sans-serif;
      margin:0;
      background:var(--bg);
      color:var(--text);
      overflow-x:hidden;
    }

    a{
      color:var(--sb-accent);
      text-decoration:none;
    }
    a:hover{
      text-decoration:underline;
    }

    .header{
      position:sticky;
      top:0;
      z-index:20;
      background:rgba(255,255,255,.92);
      backdrop-filter: blur(10px);
      border-bottom:1px solid var(--line);
    }

    .header .in{
      width:min(var(--sb-container), calc(100% - 32px));
      margin:0 auto;
      padding:12px 0;
      display:flex;
      gap:14px;
      align-items:center;
      justify-content:space-between;
      flex-wrap:wrap;
    }

    .brandWrap{
      display:flex;
      align-items:center;
      gap:12px;
      min-width:220px;
    }

    .logoBox{
      width:40px;
      height:40px;
      border-radius:12px;
      border:1px solid var(--line);
      background:var(--soft2);
      display:flex;
      align-items:center;
      justify-content:center;
      overflow:hidden;
      flex:0 0 auto;
      font-weight:800;
      color:var(--sb-accent);
    }
    .logoBox img{
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
    }

    .brandText{
      display:flex;
      flex-direction:column;
      gap:2px;
      line-height:1.2;
    }
    .brand{
      font-weight:800;
      font-size:16px;
      color:var(--text);
    }
    .pageLabel{
      font-size:12px;
      color:var(--muted);
    }

    .nav{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:center;
    }
    .nav a{
      color:var(--text);
      text-decoration:none;
      padding:8px 12px;
      border-radius:999px;
      border:1px solid var(--line);
      background:#fff;
      box-shadow: var(--shadow);
      font-size:13px;
    }
    .nav a:hover{
      text-decoration:none;
      border-color:#d1d5db;
    }
    .nav a.active{
      background: color-mix(in srgb, var(--sb-accent) 12%, #fff);
      border-color: color-mix(in srgb, var(--sb-accent) 28%, #e5e7eb);
      color: var(--sb-accent);
      font-weight:700;
    }

    .centerWrap{
      width:min(var(--sb-container), calc(100% - 32px));
      margin:0 auto;
    }

    .hero{
      margin-bottom:18px;
    }
    .hero h1{
      margin:0;
      font-size:34px;
      line-height:1.15;
      letter-spacing:-0.02em;
    }
    .hero .muted{
      margin-top:8px;
      color:var(--muted);
      font-size:14px;
    }

    .muted{ color:var(--muted); }

    .block{ margin-top:14px; }

    .textBlock{
      white-space:pre-wrap;
      line-height:1.75;
      font-size:16px;
    }

    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 14px;
      border-radius:12px;
      border:1px solid var(--line);
      text-decoration:none;
    }
    .btn.primary{
      background:var(--sb-accent);
      color:#fff;
      border-color:var(--sb-accent);
    }
    .btn.secondary{
      background:#fff;
      color:var(--text);
    }

    img{
      max-width:100%;
      height:auto;
      border-radius:14px;
      border:1px solid #eee;
      background:#fafafa;
      display:block;
    }

    .cols2{
      display:grid;
      gap:14px;
    }
    .cols2 > div{
      background:#fff;
      border:1px solid #eee;
      border-radius:16px;
      padding:14px;
      white-space:pre-wrap;
      line-height:1.7;
    }
    @media (max-width: 820px){
      .cols2{ grid-template-columns:1fr !important; }
    }

    .galleryGrid{
      display:grid;
      gap:14px;
    }
    @media (max-width: 768px){
      .galleryGrid{ grid-template-columns:1fr !important; }
    }

    .cardItem{
      background:#fff;
      border:1px solid #eee;
      border-radius:16px;
      padding:14px;
    }

    .cardsGrid{
      display:grid;
      gap:14px;
    }
    @media (max-width: 768px){
      .cardsGrid{ grid-template-columns:1fr !important; }
    }

    .cardTitle{
      font-weight:700;
      font-size:18px;
      line-height:1.3;
    }

    .cardText{
      margin-top:8px;
      color:#374151;
      white-space:pre-wrap;
      line-height:1.7;
    }

    .layoutHeader{ margin-top:18px; margin-bottom:8px; }
    .layoutFooter{ margin-top:24px; }

    .pageShell{
      display:grid;
      grid-template-columns: var(--left-col) minmax(0,1fr) minmax(0,var(--sb-container)) minmax(0,1fr) var(--right-col);
      align-items:start;
      gap:24px 16px;
      width:100%;
      padding:24px 16px 40px;
    }

    .pageLeft{ grid-column:1; min-width:0; }
    .pageCenter{ grid-column:3; min-width:0; }
    .pageRight{ grid-column:5; min-width:0; }

    .layoutSidebarBox{
    background:#fff;
    border:1px solid #eef2f6;
    border-radius:14px;
    padding:12px;
    box-shadow: 0 1px 2px rgba(0,0,0,.03);
    position: sticky;
    top: 88px;
    }

    .layoutSidebarBox .block:first-child{
      margin-top:0;
    }

    .sideTree{
  display:flex;
  flex-direction:column;
  gap:2px;
}

.sideTreeNode{
  min-width:0;
}

.sideTreeChildren{
  margin-top:2px;
  margin-left:6px;
  padding-left:6px;
  border-left:1px solid #f1f5f9;
}

.sideMenuRow{
  display:flex;
  align-items:flex-start;
  gap:4px;
  min-width:0;
}

.sideToggle{
  width:12px;
  height:18px;
  flex:0 0 12px;
  margin-top:6px;
  border:0;
  background:transparent;
  color:#94a3b8;
  cursor:pointer;
  padding:0;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:6px;
}

.sideToggle:hover{
  background:#f1f5f9;
  color:#64748b;
}

.sideToggleIcon{
  font-size:10px;
  line-height:1;
}

.sideToggleStub{
    width:12px;
  height:18px;
  flex:0 0 12px;
  margin-top:6px;
}

.sideMenuLink{
  display:flex;
  align-items:flex-start;
  gap:8px;
  width:100%;
  padding:6px 6px;
  border-radius:8px;
  border:1px solid transparent;
  background:transparent;
  color:var(--text);
  text-decoration:none;
  line-height:1.3;
  font-size:13px;
  transition:background .15s ease, color .15s ease, border-color .15s ease;
}

.sideMenuLink:hover{
  text-decoration:none;
  background:#f8fafc;
  color:var(--text);
}

.sideMenuLink.open{
  background:transparent;
}

.sideMenuLink.active{
  background:#f5f9ff;
  border-color:#dbeafe;
  color:#1d4ed8;
  font-weight:600;
}

.sideMenuCaret{
  width:12px;
  flex:0 0 12px;
  color:#94a3b8;
  text-align:center;
  line-height:1.2;
  font-size:10px;
  margin-top:2px;
}

.sideMenuCaretEmpty{
  visibility:hidden;
}

.sideMenuText{
  min-width:0;
  flex:1 1 auto;
  word-break:normal;
  overflow-wrap:anywhere;
}



.sideTree.level-1 > .sideTreeNode > .sideMenuLink,
.sideTree.level-2 > .sideTreeNode > .sideMenuLink,
.sideTree.level-3 > .sideTreeNode > .sideMenuLink,
.sideTree.level-4 > .sideTreeNode > .sideMenuLink{
  font-weight:400;
  font-size:12px;
}

    .meta{
      margin-top:28px;
      padding-top:14px;
      border-top:1px dashed var(--line);
      font-size:12px;
      color:var(--muted);
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      justify-content:space-between;
    }

    code{
      background:var(--soft);
      padding:2px 6px;
      border-radius:8px;
      border:1px solid #eef0f2;
    }

    @media (max-width: 1200px){
      .pageShell{
        grid-template-columns:1fr;
        padding:24px 16px 40px;
      }
      .pageLeft, .pageCenter, .pageRight{
        grid-column:1;
      }
      .layoutSidebarBox{
        position:static;
      }
    }

    .sideTree{
  display:flex;
  flex-direction:column;
  gap:2px;
}

.sideTreeChildren{
  margin-top:2px;
  margin-left:2px;
  padding-left:6px;
  border-left:1px solid #f1f5f9;
}

.sideTreeNode{
  min-width:0;
}

.sideMenuLink{
  display:flex;
  align-items:center;
  gap:8px;
  width:100%;
  padding:8px 10px;
  border-radius:10px;
  border:1px solid transparent;
  background:transparent;
  color:var(--text);
  text-decoration:none;
  line-height:1.3;
  transition:background .15s ease, border-color .15s ease, color .15s ease;
}

.sideMenuLink:hover{
  text-decoration:none;
  background:#f8fafc;
}

.sideMenuLink.active{
  background: color-mix(in srgb, var(--sb-accent) 10%, #fff);
  border-color: color-mix(in srgb, var(--sb-accent) 18%, #e5e7eb);
  color: var(--sb-accent);
  font-weight:700;
}

.sideMenuLink.open{
  background:transparent;
}

.sideMenuCaret{
  width:14px;
  flex:0 0 14px;
  color:#64748b;
  text-align:center;
  line-height:1;
  font-size:11px;
}

.sideMenuCaretEmpty{
  visibility:hidden;
}

.sideMenuText{
  min-width:0;
  flex:1 1 auto;
  word-break:break-word;
}

.sideTree.level-0 > .sideTreeNode > .sideMenuLink{
  font-weight:600;
  font-size:13px;
}

.sideTree.level-1 > .sideTreeNode > .sideMenuLink,
.sideTree.level-2 > .sideTreeNode > .sideMenuLink,
.sideTree.level-3 > .sideTreeNode > .sideMenuLink{
  font-weight:400;
  font-size:14px;
}

.breadcrumbs{
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:6px;
  margin-bottom:8px;
  font-size:12px;
  color:#94a3b8;
}

.crumb{
  color:var(--muted);
  text-decoration:none;
}

.crumb:hover{
  color:var(--sb-accent);
  text-decoration:none;
}

.crumb.current{
  color:#64748b;
  font-weight:500;
}

.crumbSep{
  color:#c0c7d1;
  margin:0 2px;
}
  </style>
</head>
<body
  data-site-key="<?=h((string)($site['slug'] ?? ('site-'.$siteId)))?>"
  style="--left-col: <?=h($leftCol)?>; --right-col: <?=h($rightCol)?>;"
>

<div class="header">
  <div class="in">
    <div class="brandWrap">
      <div class="logoBox">
        <?php if ($logoFileId > 0): ?>
          <img src="<?=h(file_url($siteId, $logoFileId))?>" alt="<?=h($site['name'] ?? 'Logo')?>">
        <?php else: ?>
          SB
        <?php endif; ?>
      </div>

      <div class="brandText">
        <div class="brand"><?=h($site['name'] ?? 'Site')?></div>
        <div class="pageLabel"><?=h($page['title'] ?? '')?></div>
      </div>
    </div>

    <?php if (count($menuItems)): ?>
      <nav class="nav">
        <?php foreach ($menuItems as $mi): ?>
          <a class="<?= !empty($mi['active']) ? 'active' : '' ?>"
             href="<?=h($mi['href'])?>"
             <?=preg_match('~^https?://~i', $mi['href']) ? 'target="_blank" rel="noopener noreferrer"' : ''?>>
            <?=h($mi['title'])?>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  </div>
</div>

<div class="pageShell">
  <?php if ($leftHtml !== ''): ?>
    <aside class="pageLeft">
      <div class="layoutSidebarBox">
        <?= $leftHtml ?>
      </div>
    </aside>
  <?php endif; ?>

  <div class="pageCenter">
    <div class="centerWrap">
        <div class="hero">
        <?php if ($breadcrumbsHtml !== ''): ?>
            <?= $breadcrumbsHtml ?>
        <?php endif; ?>

        <h1><?=h($page['title'] ?? '')?></h1>
        <div class="muted"><?=h($site['name'] ?? 'Site')?></div>
        </div>

      <?php if ($headerHtml !== ''): ?>
        <div class="layoutHeader">
          <?= $headerHtml ?>
        </div>
      <?php endif; ?>

      <?= $pageHtml ?>

      <?php if ($footerHtml !== ''): ?>
        <div class="layoutFooter">
          <?= $footerHtml ?>
        </div>
      <?php endif; ?>

      <div class="meta">
        <div>site: <code><?=h((string)($site['slug'] ?? ''))?></code></div>
        <div>page: <code><?=h((string)($page['slug'] ?? ''))?></code></div>
      </div>
    </div>
  </div>

  <?php if ($rightHtml !== ''): ?>
    <aside class="pageRight">
      <div class="layoutSidebarBox">
        <?= $rightHtml ?>
      </div>
    </aside>
  <?php endif; ?>
</div>

<script>
(function(){
  const siteKey = document.body.getAttribute('data-site-key') || location.pathname;
  const storageKey = 'sb-left-tree:' + siteKey;

  function readState() {
    try {
      const raw = localStorage.getItem(storageKey);
      const parsed = raw ? JSON.parse(raw) : {};
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function writeState(state) {
    try {
      localStorage.setItem(storageKey, JSON.stringify(state));
    } catch (e) {}
  }

  const state = readState();

  document.querySelectorAll('.sideTreeNode[data-node-id]').forEach(node => {
    const id = String(node.getAttribute('data-node-id') || '');
    const defaultOpen = node.getAttribute('data-open-default') === '1';

    const btn = node.querySelector(':scope > .sideMenuRow .sideToggle');
    const icon = node.querySelector(':scope > .sideMenuRow .sideToggle .sideToggleIcon');
    const children = node.querySelector(':scope > .sideTreeChildren');

    if (!id || !btn || !icon || !children) return;

    let isOpen;

    if (Object.prototype.hasOwnProperty.call(state, id)) {
      isOpen = !!state[id];
    } else if (defaultOpen) {
      isOpen = true;
      state[id] = true;
      writeState(state);
    } else {
      isOpen = false;
    }

    function render() {
      children.style.display = isOpen ? 'block' : 'none';
      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      icon.textContent = isOpen ? '▾' : '▸';
    }

    btn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();

      isOpen = !isOpen;
      state[id] = isOpen;
      writeState(state);
      render();
    });

    render();
  });
})();
</script>

</body>
</html>
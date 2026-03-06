<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

global $USER;

if (!$USER->IsAuthorized()) {
    LocalRedirect('/auth/');
}

header('Content-Type: text/html; charset=UTF-8');

$siteId = (int)($_GET['siteId'] ?? 0);
$pageId = (int)($_GET['pageId'] ?? 0);

function sb_data_path(string $file): string {
    return $_SERVER['DOCUMENT_ROOT'] . '/upload/sitebuilder/' . $file;
}
function sb_read_json(string $file): array {
    $path = sb_data_path($file);
    if (!file_exists($path)) return [];

    $fp = fopen($path, 'rb');
    if (!$fp) return [];

    $raw = '';
    if (flock($fp, LOCK_SH)) {
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } else {
        $raw = stream_get_contents($fp);
    }
    fclose($fp);

    // remove UTF-8 BOM (just in case)
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }

    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}
function h($s): string { return htmlspecialcharsbx((string)$s); }

function downloadUrl(int $siteId, int $fileId): string {
    return '/local/sitebuilder/download.php?siteId=' . $siteId . '&fileId=' . $fileId;
}
function viewUrl(int $siteId, int $pageId): string {
    return '/local/sitebuilder/view.php?siteId=' . $siteId . '&pageId=' . $pageId;
}

function sb_index_pages_by_id(array $pages, int $siteId): array {
    $byId = [];
    foreach ($pages as $p) {
        if ((int)($p['siteId'] ?? 0) !== $siteId) continue;
        $id = (int)($p['id'] ?? 0);
        if ($id > 0) $byId[$id] = $p;
    }
    return $byId;
}

function sb_build_breadcrumbs(array $pagesById, int $currentPageId): array {
    $chain = [];
    $guard = 0;
    $curId = $currentPageId;

    while ($curId > 0 && isset($pagesById[$curId])) {
        $chain[] = $pagesById[$curId];
        $parentId = (int)($pagesById[$curId]['parentId'] ?? 0);
        if ($parentId <= 0) break;
        $curId = $parentId;

        $guard++;
        if ($guard > 50) break;
    }

    return array_reverse($chain);
}

function sb_get_children_pages(array $pages, int $siteId, int $parentId): array {
    $kids = array_values(array_filter($pages, function($p) use ($siteId, $parentId){
        return (int)($p['siteId'] ?? 0) === $siteId && (int)($p['parentId'] ?? 0) === $parentId;
    }));
    usort($kids, function($a, $b){
        $sa = (int)($a['sort'] ?? 500);
        $sb = (int)($b['sort'] ?? 500);
        if ($sa === $sb) return ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
        return $sa <=> $sb;
    });
    return $kids;
}

// load data
$sites = sb_read_json('sites.json');
$pages = sb_read_json('pages.json');
$blocksAll = sb_read_json('blocks.json');
$menusAll = sb_read_json('menus.json');

// find site/page
$site = null;
foreach ($sites as $s) {
    if ((int)($s['id'] ?? 0) === $siteId) { $site = $s; break; }
}
$page = null;
foreach ($pages as $p) {
    if ((int)($p['id'] ?? 0) === $pageId && (int)($p['siteId'] ?? 0) === $siteId) { $page = $p; break; }
}

if (!$site || !$page) {
    http_response_code(404);
    ?>
    <!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Не найдено</title></head>
    <body style="font-family:Arial;padding:24px;background:#f6f7f8;">
      <div style="background:#fff;border:1px solid #e5e7ea;border-radius:12px;padding:16px;">
        <h2 style="margin-top:0;">Страница не найдена</h2>
        <div>siteId=<?= (int)$siteId ?>, pageId=<?= (int)$pageId ?></div>
        <div style="margin-top:12px;"><a href="/local/sitebuilder/index.php">← Назад</a></div>
      </div>
    </body></html>
    <?php
    exit;
}

$topMenuId = (int)($site['topMenuId'] ?? 0);

$settings = (isset($site['settings']) && is_array($site['settings'])) ? $site['settings'] : [];

$containerWidth = (int)($settings['containerWidth'] ?? 1100);
if ($containerWidth < 900) $containerWidth = 900;
if ($containerWidth > 1600) $containerWidth = 1600;

$accent = (string)($settings['accent'] ?? '#2563eb');
if (!preg_match('~^#[0-9a-fA-F]{6}$~', $accent)) $accent = '#2563eb';

$logoFileId = (int)($settings['logoFileId'] ?? 0);

// site pages (fallback nav)
$sitePages = array_values(array_filter($pages, fn($p) => (int)($p['siteId'] ?? 0) === $siteId));
usort($sitePages, fn($a, $b) => (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500));

$pagesById = sb_index_pages_by_id($pages, $siteId);
$breadcrumbs = sb_build_breadcrumbs($pagesById, $pageId);
$children = sb_get_children_pages($pages, $siteId, $pageId);
$parentId = (int)($page['parentId'] ?? 0);
$parentChildren = $parentId > 0 ? sb_get_children_pages($pages, $siteId, $parentId) : [];

// blocks for current page
$blocks = array_values(array_filter($blocksAll, fn($b) => (int)($b['pageId'] ?? 0) === $pageId));
usort($blocks, fn($a, $b) => (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500));

// menu: take topMenuId for this site (if set), otherwise fallback to first menu
$menuItems = [];
foreach ($menusAll as $rec) {
    if ((int)($rec['siteId'] ?? 0) !== $siteId) continue;

    $menus = $rec['menus'] ?? [];
    if (!is_array($menus) || !count($menus)) break;

    $picked = null;

    if ($topMenuId > 0) {
        foreach ($menus as $m) {
            if ((int)($m['id'] ?? 0) === $topMenuId) { $picked = $m; break; }
        }
    }

    if (!$picked) $picked = $menus[0];

    $menuItems = $picked['items'] ?? [];
    if (!is_array($menuItems)) $menuItems = [];
    usort($menuItems, fn($a, $b) => (int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0));
    break;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?=h($page['title'])?> — <?=h($site['name'])?></title>
  <style>
    :root{
      --bg:#f6f7f8;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#667085;
      --line:#e5e7ea;
      --line2:#eef0f2;
      --accentSoft:#eef2ff;
      --radius:16px;
      --shadow: 0 1px 2px rgba(16,24,40,.06);

      --sb-accent: <?= h($accent) ?>;
      --sb-container: <?= (int)$containerWidth ?>px;

      --accent: var(--sb-accent);
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: Arial, sans-serif;
      background:var(--bg);
      color:var(--text);
    }
    a{ color:var(--accent); text-decoration:none; }
    a:hover{ text-decoration:underline; }

    .container{
      max-width: var(--sb-container);
      margin: 0 auto;
      padding: 0 16px;
    }

    /* Top bar */
    .topWrap{
      position: sticky;
      top: 0;
      z-index: 20;
      background: rgba(246,247,248,.92);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--line);
    }

    .top{
      padding: 12px 0;
    }

    .topRow{
      display:flex;
      gap:12px;
      align-items:center;
      justify-content:space-between;
      flex-wrap:wrap;
    }

    .brand{
      display:flex;
      gap:12px;
      align-items:center;
      min-width: 240px;
    }

    .brandMark{
      width: 34px;
      height: 34px;
      border-radius: 12px;
      background: var(--accentSoft);
      border:1px solid #c7d2fe;
      display:flex;
      align-items:center;
      justify-content:center;
      color: var(--accent);
      font-weight: 800;
      flex:0 0 auto;
      overflow:hidden; /* важно для img */
    }
  .brandMark img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }
    .brandTxt{
      display:flex;
      flex-direction:column;
      line-height:1.2;
    }
    .siteName{
      font-weight:800;
      font-size:14px;
    }
    .pageName{
      font-size:12px;
      color:var(--muted);
    }

    .actions{
      display:flex;
      gap:8px;
      align-items:center;
      flex-wrap:wrap;
    }
    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      padding: 9px 12px;
      border-radius: 12px;
      border:1px solid var(--line);
      background:#fff;
      color: #111;
      text-decoration:none;
      box-shadow: var(--shadow);
      cursor:pointer;
      font-size: 13px;
      line-height: 1;
    }
    .btn:hover{ text-decoration:none; border-color:#d0d7de; }
    .btnPrimary{
      background: var(--accent);
      border-color: var(--accent);
      color:#fff;
    }
    .btnPrimary:hover{ filter: brightness(.98); }

    .crumbs{
      margin-top: 10px;
      display:flex;
      gap:6px;
      flex-wrap:wrap;
      align-items:center;
      color:var(--muted);
      font-size:12px;
    }
    .crumbs a{ color:var(--accent); }
    .sep{ color:#98a2b3; }

    /* Menu */
    .menu{
      margin-top: 10px;
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }
    .menu a{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding: 8px 12px;
      border-radius: 999px;
      border:1px solid var(--line);
      background:#fff;
      color:#111;
      font-size:13px;
      text-decoration:none;
      box-shadow: var(--shadow);
    }
    .menu a:hover{ border-color:#d0d7de; }
    .menu a.active{
      background: color-mix(in srgb, var(--accent) 12%, #fff);
      border-color: color-mix(in srgb, var(--accent) 30%, #e5e7ea);
      color: var(--accent);
      font-weight: 700;
    }

    /* Main */
    .content { padding: 18px 0; }
    .card{
      background:var(--card);
      border:1px solid var(--line);
      border-radius: var(--radius);
      padding: 18px;
      box-shadow: var(--shadow);
    }
    .title{
      margin:0;
      font-size: 26px;
      line-height: 1.2;
      letter-spacing: -0.2px;
    }
    .subtitle{
      margin-top: 8px;
      color: var(--muted);
      font-size: 13px;
    }

    /* Section nav */
    .sectionNav{
      margin-top:14px;
      border:1px solid var(--line);
      border-radius: var(--radius);
      padding: 14px;
      background: #fff;
      box-shadow: var(--shadow);
    }
    .sectionNavTitle{
      font-weight:800;
      font-size:13px;
    }
    .sectionNavHint{
      margin-top:8px;
      font-size:12px;
      color:var(--muted);
    }
    .sectionGrid{
      margin-top: 12px;
      display:grid;
      gap:10px;
    }
    @media (min-width: 760px){
      .sectionGrid{ grid-template-columns: 1fr 1fr; }
    }
    .sectionCard{
      border:1px solid var(--line2);
      border-radius: 14px;
      background:#fff;
      padding: 12px;
      display:flex;
      gap:10px;
      align-items:flex-start;
      text-decoration:none;
      color:#111;
    }
    .sectionCard:hover{ border-color:#d0d7de; text-decoration:none; }
    .sectionCard.active{
      background: color-mix(in srgb, var(--accent) 10%, #fff);
      border-color: color-mix(in srgb, var(--accent) 30%, #e5e7ea);
    }
    .sectionCard .dot{
      width: 10px; height:10px; border-radius:999px;
      background:#e5e7ea; flex:0 0 auto; margin-top: 4px;
    }
    .sectionCard.active .dot{ background: var(--accent); }
    .sectionCard .t{
      font-weight:700;
      font-size:13px;
      line-height:1.3;
    }
    .sectionCard .m{
      margin-top:4px;
      font-size:12px;
      color:var(--muted);
    }

    /* Blocks */
    .block{ margin-top: 14px; }
    .blockText{
      line-height:1.75;
      font-size:16px;
      color:#0b1220;
      white-space:pre-wrap;
    }

    .blockImg img{
      width:100%;
      height:auto;
      display:block;
      border-radius: 16px;
      border:1px solid var(--line2);
      background:#fafafa;
    }

    /* columns2 */
    .cols2{
      display:grid;
      gap:14px;
      margin-top: 14px;
    }
    .cols2 .col{
      border:1px solid var(--line2);
      border-radius: 16px;
      padding: 14px;
      background:#fff;
      line-height:1.7;
    }
    @media (max-width: 768px){
      .cols2{ grid-template-columns: 1fr !important; }
    }

    /* gallery */
    .gallery{
      margin-top: 14px;
      display:grid;
      gap: 12px;
    }
    .gallery img{
      width:100%;
      height:auto;
      display:block;
      border-radius: 16px;
      border:1px solid var(--line2);
      background:#fafafa;
    }
    @media (max-width: 768px){
      .gallery{ grid-template-columns: 1fr !important; }
    }

    /* spacer */
    .spacer{ width:100%; }
    .spacerLine{ height:1px; background:var(--line); }

    /* card block */
    .cardBlock{
      border:1px solid var(--line2);
      border-radius: 18px;
      padding: 14px;
      background:#fff;
    }
    .cardTitle{ font-weight:800; font-size:18px; }
    .cardText{ margin-top:8px; color:#1f2937; line-height:1.7; white-space:pre-wrap; }
    .cardBlock img{
      width:100%;
      height:auto;
      display:block;
      border-radius: 16px;
      border:1px solid var(--line2);
      margin-top: 10px;
      background:#fafafa;
    }
    .cardBtn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      margin-top: 12px;
      padding: 10px 14px;
      border-radius: 12px;
      border:1px solid var(--line);
      background:#fff;
      color:#111;
      text-decoration:none;
    }
    .cardBtn:hover{ text-decoration:none; border-color:#d0d7de; }

    /* cards grid */
    .cardsGrid{
      margin-top: 14px;
      display:grid;
      gap: 14px;
    }
    .cardsGrid .cardItem{
      border:1px solid var(--line2);
      border-radius: 18px;
      padding: 14px;
      background:#fff;
    }
    .cardsGrid .t{ font-weight:800; font-size:16px; }
    .cardsGrid .d{ margin-top:8px; color:#1f2937; line-height:1.7; white-space:pre-wrap; }
    .cardsGrid .cardItem img{
      width:100%;
      height:auto;
      display:block;
      border-radius: 16px;
      border:1px solid var(--line2);
      background:#fafafa;
      margin-top: 10px;
    }
    .cardsGrid .a{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      margin-top: 12px;
      padding: 10px 14px;
      border-radius: 12px;
      border:1px solid var(--line);
      background:#fff;
      color:#111;
      text-decoration:none;
    }
    .cardsGrid .a:hover{ text-decoration:none; border-color:#d0d7de; }

    @media (max-width: 768px){
      .cardsGrid{ grid-template-columns: 1fr !important; }
    }

    /* Footer meta */
    .meta{
      margin-top: 18px;
      padding-top: 14px;
      border-top: 1px dashed var(--line);
      color: var(--muted);
      font-size: 12px;
      display:flex;
      gap: 12px;
      flex-wrap:wrap;
      align-items:center;
      justify-content:space-between;
    }
    code{
      background:#f3f4f6;
      padding:2px 6px;
      border-radius: 8px;
      border:1px solid #eef0f2;
      color:#374151;
    }
  </style>
</head>
<body>

<div class="topWrap">
  <div class="container top">
    <div class="topRow">
      <div class="brand">
        <div class="brandMark">
          <?php if ($logoFileId > 0): ?>
            <img src="<?= h(downloadUrl($siteId, $logoFileId)) ?>" alt="logo">
          <?php else: ?>
            SB
          <?php endif; ?>
        </div>
        <div class="brandTxt">
          <div class="siteName"><?=h($site['name'])?></div>
          <div class="pageName"><?=h($page['title'])?></div>
        </div>
      </div>

      <div class="actions">
        <a class="btn" href="/local/sitebuilder/index.php">← К списку</a>
        <a class="btn" href="/local/sitebuilder/settings.php?siteId=<?= (int)$siteId ?>" target="_blank">Настройки</a>
        <a class="btn" href="/local/sitebuilder/menu.php?siteId=<?= (int)$siteId ?>" target="_blank">Меню</a>
        <a class="btn btnPrimary" href="/local/sitebuilder/editor.php?siteId=<?= (int)$siteId ?>&pageId=<?= (int)$pageId ?>" target="_blank">Редактор</a>
      </div>
    </div>

    <?php if ($breadcrumbs && count($breadcrumbs) > 1): ?>
      <div class="crumbs">
        <?php foreach ($breadcrumbs as $i => $bc): ?>
          <?php
            $isLast = ($i === count($breadcrumbs)-1);
            $bcId = (int)($bc['id'] ?? 0);
            $bcTitle = (string)($bc['title'] ?? ('page#'.$bcId));
          ?>
          <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
          <?php if ($isLast): ?>
            <span><?= h($bcTitle) ?></span>
          <?php else: ?>
            <a href="<?= h(viewUrl($siteId, $bcId)) ?>"><?= h($bcTitle) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($menuItems): ?>
      <nav class="menu" aria-label="Site menu">
        <?php foreach ($menuItems as $it): ?>
          <?php
            $type = (string)($it['type'] ?? '');
            $title = (string)($it['title'] ?? '');
            $isActive = false;
            $href = '#';

            if ($type === 'page') {
              $pid = (int)($it['pageId'] ?? 0);
              $href = viewUrl($siteId, $pid);
              $isActive = ($pid === $pageId);
              if ($title === '') $title = 'page#'.$pid;
            } elseif ($type === 'url') {
              $u = (string)($it['url'] ?? '');
              $href = $u !== '' ? $u : '#';
              if ($title === '') $title = $href;
            } else {
              $href = '#';
              if ($title === '') $title = '(unknown)';
            }
          ?>
          <a class="<?= $isActive ? 'active' : '' ?>"
             href="<?= h($href) ?>"
             <?= ($type === 'url' && preg_match('~^https?://~i', $href)) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
            <?= h($title) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php else: ?>
      <nav class="menu" aria-label="Fallback site menu">
        <?php foreach ($sitePages as $sp): ?>
          <?php $isActive = ((int)($sp['id'] ?? 0) === (int)$pageId); ?>
          <a class="<?= $isActive ? 'active' : '' ?>" href="<?= h(viewUrl($siteId, (int)($sp['id'] ?? 0))) ?>">
            <?= h($sp['title'] ?? '') ?>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

  </div>
</div>

<div class="content">
  <div class="container">
    <div class="card">
      <h1 class="title"><?=h($page['title'])?></h1>
      <div class="subtitle">
        <?=h($site['name'])?> • pageId: <code><?= (int)$pageId ?></code> • siteId: <code><?= (int)$siteId ?></code>
      </div>

      <?php if ($children && count($children) > 0): ?>
        <div class="sectionNav">
          <div class="sectionNavTitle">Страницы раздела</div>
          <div class="sectionGrid">
            <?php foreach ($children as $ch): ?>
              <?php $chId = (int)($ch['id'] ?? 0); ?>
              <a class="sectionCard" href="<?= h(viewUrl($siteId, $chId)) ?>">
                <span class="dot"></span>
                <span>
                  <div class="t"><?= h((string)($ch['title'] ?? ('page#'.$chId))) ?></div>
                  <div class="m">slug: <code><?= h((string)($ch['slug'] ?? '')) ?></code></div>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
          <div class="sectionNavHint">Раздел — это страница, у которой есть дочерние страницы (parentId = <?= (int)$pageId ?>).</div>
        </div>
      <?php elseif ($parentId > 0 && $parentChildren && count($parentChildren) > 0): ?>
        <div class="sectionNav">
          <div class="sectionNavTitle">В этом разделе</div>
          <div class="sectionGrid">
            <?php foreach ($parentChildren as $ch): ?>
              <?php $chId = (int)($ch['id'] ?? 0); $active = ($chId === $pageId); ?>
              <a class="sectionCard <?= $active ? 'active' : '' ?>" href="<?= h(viewUrl($siteId, $chId)) ?>">
                <span class="dot"></span>
                <span>
                  <div class="t"><?= h((string)($ch['title'] ?? ('page#'.$chId))) ?></div>
                  <div class="m">slug: <code><?= h((string)($ch['slug'] ?? '')) ?></code></div>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
          <div class="sectionNavHint">Ты внутри раздела (parentId = <?= (int)$parentId ?>). Тут показаны “соседи”.</div>
        </div>
      <?php endif; ?>

      <?php if (!$blocks): ?>
        <div class="block muted" style="margin-top:14px;">Блоков пока нет. Добавь их в редакторе.</div>
      <?php else: ?>
        <?php foreach ($blocks as $b): ?>
          <?php $type = (string)($b['type'] ?? ''); ?>

          <?php if ($type === 'text'): ?>
            <div class="block blockText"><?= nl2br(h((string)($b['content']['text'] ?? ''))) ?></div>
          <?php endif; ?>

          <?php if ($type === 'button'): ?>
            <?php
              $text = (string)($b['content']['text'] ?? '');
              $url = (string)($b['content']['url'] ?? '#');
              $variant = (string)($b['content']['variant'] ?? 'primary');
              $cls = ($variant === 'secondary') ? 'btn' : 'btn btnPrimary';
            ?>
            <div class="block">
              <a class="<?= h($cls) ?>" href="<?= h($url) ?>" <?= preg_match('~^https?://~i', $url) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                <?= h($text) ?>
              </a>
            </div>
          <?php endif; ?>

          <?php if ($type === 'image'): ?>
            <?php $fileId = (int)($b['content']['fileId'] ?? 0); ?>
            <?php $alt = (string)($b['content']['alt'] ?? ''); ?>
            <?php if ($fileId > 0): ?>
              <div class="block blockImg">
                <img src="<?= h(downloadUrl($siteId, $fileId)) ?>" alt="<?= h($alt) ?>">
              </div>
            <?php else: ?>
              <div class="block muted">(image) файл не выбран</div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($type === 'heading'): ?>
            <?php
              $text = (string)($b['content']['text'] ?? '');
              $level = (string)($b['content']['level'] ?? 'h2');
              $align = (string)($b['content']['align'] ?? 'left');
              if (!in_array($level, ['h1','h2','h3'], true)) $level = 'h2';
              if (!in_array($align, ['left','center','right'], true)) $align = 'left';
            ?>
            <<?=h($level)?> class="block" style="margin-top:16px; text-align:<?=h($align)?>;">
              <?=h($text)?>
            </<?=h($level)?>>
          <?php endif; ?>

          <?php if ($type === 'columns2'): ?>
            <?php
              $left  = (string)($b['content']['left'] ?? '');
              $right = (string)($b['content']['right'] ?? '');
              $ratio = (string)($b['content']['ratio'] ?? '50-50');
              if (!in_array($ratio, ['50-50','33-67','67-33'], true)) $ratio = '50-50';
              $tpl = '1fr 1fr';
              if ($ratio === '33-67') $tpl = '1fr 2fr';
              if ($ratio === '67-33') $tpl = '2fr 1fr';
            ?>
            <div class="block cols2" style="grid-template-columns: <?=h($tpl)?>;">
              <div class="col"><?= nl2br(h($left)) ?></div>
              <div class="col"><?= nl2br(h($right)) ?></div>
            </div>
          <?php endif; ?>

          <?php if ($type === 'gallery'): ?>
            <?php
              $cols = (int)($b['content']['columns'] ?? 3);
              if (!in_array($cols, [2,3,4], true)) $cols = 3;
              $tpl = '1fr 1fr 1fr';
              if ($cols === 2) $tpl = '1fr 1fr';
              if ($cols === 4) $tpl = '1fr 1fr 1fr 1fr';

              $imgs = $b['content']['images'] ?? [];
              if (!is_array($imgs)) $imgs = [];
            ?>
            <div class="block gallery" style="grid-template-columns: <?=h($tpl)?>;">
              <?php foreach ($imgs as $it): ?>
                <?php
                  if (!is_array($it)) continue;
                  $fid = (int)($it['fileId'] ?? 0);
                  $alt = (string)($it['alt'] ?? '');
                  if ($fid <= 0) continue;
                ?>
                <img src="<?= h(downloadUrl($siteId, $fid)) ?>" alt="<?= h($alt) ?>">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($type === 'spacer'): ?>
            <?php
              $h = (int)($b['content']['height'] ?? 40);
              if ($h < 10) $h = 10;
              if ($h > 200) $h = 200;
              $line = (bool)($b['content']['line'] ?? false);
            ?>
            <div class="block spacer" style="height: <?= (int)$h ?>px; position:relative;">
              <?php if ($line): ?>
                <div class="spacerLine" style="position:absolute; left:0; right:0; top:50%;"></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($type === 'card'): ?>
            <?php
              $title = (string)($b['content']['title'] ?? '');
              $text  = (string)($b['content']['text'] ?? '');
              $imgId = (int)($b['content']['imageFileId'] ?? 0);
              $btnText = trim((string)($b['content']['buttonText'] ?? ''));
              $btnUrl  = trim((string)($b['content']['buttonUrl'] ?? ''));
            ?>
            <div class="block cardBlock">
              <div class="cardTitle"><?=h($title)?></div>
              <?php if ($text !== ''): ?><div class="cardText"><?=nl2br(h($text))?></div><?php endif; ?>
              <?php if ($imgId > 0): ?><img src="<?=h(downloadUrl($siteId, $imgId))?>" alt=""><?php endif; ?>
              <?php if ($btnUrl !== ''): ?>
                <a class="cardBtn" href="<?=h($btnUrl)?>" <?= preg_match('~^https?://~i', $btnUrl) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                  <?=h($btnText !== '' ? $btnText : 'Открыть')?>
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($type === 'cards'): ?>
            <?php
              $cols = (int)($b['content']['columns'] ?? 3);
              if (!in_array($cols, [2,3,4], true)) $cols = 3;
              $tpl = '1fr 1fr 1fr';
              if ($cols === 2) $tpl = '1fr 1fr';
              if ($cols === 4) $tpl = '1fr 1fr 1fr 1fr';

              $items = $b['content']['items'] ?? [];
              if (!is_array($items)) $items = [];
            ?>
            <div class="block cardsGrid" style="grid-template-columns: <?=h($tpl)?>;">
              <?php foreach ($items as $it): ?>
                <?php
                  if (!is_array($it)) continue;
                  $title = (string)($it['title'] ?? '');
                  if ($title === '') continue;
                  $text = (string)($it['text'] ?? '');
                  $imgId = (int)($it['imageFileId'] ?? 0);
                  $btnText = trim((string)($it['buttonText'] ?? ''));
                  $btnUrl  = trim((string)($it['buttonUrl'] ?? ''));
                ?>
                <div class="cardItem">
                  <div class="t"><?=h($title)?></div>
                  <?php if ($text !== ''): ?><div class="d"><?=nl2br(h($text))?></div><?php endif; ?>
                  <?php if ($imgId > 0): ?><img src="<?=h(downloadUrl($siteId, $imgId))?>" alt=""><?php endif; ?>
                  <?php if ($btnUrl !== ''): ?>
                    <a class="a" href="<?=h($btnUrl)?>" <?= preg_match('~^https?://~i', $btnUrl) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                      <?=h($btnText !== '' ? $btnText : 'Открыть')?>
                    </a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        <?php endforeach; ?>
      <?php endif; ?>

      <div class="meta">
        <div>slug: <code><?=h((string)($page['slug'] ?? ''))?></code></div>
        <div>pageId: <code><?= (int)($page['id'] ?? 0) ?></code> • siteId: <code><?= (int)($site['id'] ?? 0) ?></code></div>
      </div>

    </div>
  </div>
</div>

</body>
</html>
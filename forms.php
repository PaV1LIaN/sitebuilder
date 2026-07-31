<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/FormSubmissionService.php';

global $USER, $APPLICATION;
sitebuilder_require_auth();
$siteId = (int)($_GET['siteId'] ?? $_POST['siteId'] ?? 0);
if ($siteId <= 0) { http_response_code(400); exit('siteId required'); }
if (!$USER->IsAdmin()) sb_require_content_manager($siteId);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['formAction'] ?? '');
    if ($action === 'status') {
        FormSubmissionService::updateStatus($siteId, $id, (string)($_POST['status'] ?? 'new'), (int)$USER->GetID());
        $message = 'Статус обновлён.';
    } elseif ($action === 'delete') {
        FormSubmissionService::delete($siteId, $id);
        $message = 'Заявка удалена.';
    }
}
$status = trim((string)($_GET['status'] ?? ''));
$items = FormSubmissionService::list($siteId, ['status' => $status], 300);
$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
function sb_forms_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Заявки форм</title><?php $APPLICATION->ShowHead(); ?><link rel="stylesheet" href="<?= sb_forms_h($basePath) ?>/assets/admin/admin.css"><style>.submission{margin:14px 0;padding:18px;border:1px solid #e2e8f0;border-radius:14px;background:#fff}.submission__head{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap}.submission dl{display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:8px 16px}.submission dt{font-weight:700}.submission dd{margin:0;white-space:pre-wrap}.status-new{color:#b45309}.status-done{color:#15803d}.status-spam{color:#b91c1c}</style></head>
<body class="sb-admin-body"><div class="sb-page"><div class="sb-topbar"><div><a class="sb-back-link" href="<?= sb_forms_h($basePath) ?>/editor.php?siteId=<?= $siteId ?>">← В редактор</a><h1 class="sb-title">Заявки форм</h1><p class="sb-subtitle">Сайт #<?= $siteId ?> · <?= count($items) ?> записей</p></div></div>
<?php if ($message): ?><div class="sb-panel"><?= sb_forms_h($message) ?></div><?php endif; ?>
<div class="sb-panel"><form method="get"><input type="hidden" name="siteId" value="<?= $siteId ?>"><label class="sb-field"><span>Статус</span><select class="sb-select" name="status" onchange="this.form.submit()"><option value="">Все</option><?php foreach(['new'=>'Новые','in_progress'=>'В работе','done'=>'Готово','spam'=>'Спам'] as $value=>$label): ?><option value="<?= $value ?>" <?= $status===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label></form></div>
<?php if (!$items): ?><div class="sb-panel sb-empty">Заявок пока нет.</div><?php endif; ?>
<?php foreach ($items as $item): ?><article class="submission"><div class="submission__head"><div><strong>#<?= (int)$item['id'] ?></strong> · страница #<?= (int)$item['pageId'] ?> · форма #<?= (int)$item['blockId'] ?><br><small><?= sb_forms_h($item['createdAt']) ?></small></div><strong class="status-<?= sb_forms_h($item['status']) ?>"><?= sb_forms_h($item['status']) ?></strong></div><dl><?php foreach ($item['payload'] as $field): ?><dt><?= sb_forms_h($field['label'] ?? '') ?></dt><dd><?= sb_forms_h($field['value'] ?? '') ?></dd><?php endforeach; ?></dl><form method="post" class="sb-toolbar"><?= bitrix_sessid_post() ?><input type="hidden" name="siteId" value="<?= $siteId ?>"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="formAction" value="status"><select class="sb-select" name="status"><?php foreach(['new','in_progress','done','spam'] as $value): ?><option value="<?= $value ?>" <?= $item['status']===$value?'selected':'' ?>><?= $value ?></option><?php endforeach; ?></select><button class="sb-btn sb-btn-primary" type="submit">Сохранить статус</button><button class="sb-btn sb-btn-danger" type="submit" name="formAction" value="delete" onclick="return confirm('Удалить заявку?')">Удалить</button></form></article><?php endforeach; ?>
</div></body></html>

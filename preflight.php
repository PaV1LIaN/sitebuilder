<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/MigrationService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PreflightService.php';

sitebuilder_require_bitrix_admin();

global $APPLICATION, $USER;
$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$result = null;
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!check_bitrix_sessid()) {
        $error = 'Сессия устарела. Обновите страницу.';
    } else {
        try {
            $result = PreflightService::run((int)$USER->GetID());
        } catch (Throwable $e) {
            error_log('SiteBuilder preflight page failed: ' . $e->getMessage());
            $error = 'Проверка не выполнена. Подробности записаны в журнал PHP.';
        }
    }
}

function sbPreflightEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SiteBuilder / Preflight</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= sbPreflightEscape($basePath) ?>/assets/admin/admin.css">
    <style>
        .preflight-summary{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px}.preflight-card{padding:16px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}.preflight-value{font-size:28px;font-weight:800;margin-top:6px}.check-list{display:grid;gap:10px}.check{padding:14px;border:1px solid #e5e7eb;border-left-width:5px;border-radius:10px;background:#fff}.check.ok{border-left-color:#16a34a}.check.warning{border-left-color:#d97706;background:#fffbeb}.check.error{border-left-color:#dc2626;background:#fef2f2}.check-title{font-weight:800}.check-message{margin-top:5px;color:#4b5563}.status{display:inline-flex;padding:5px 10px;border-radius:999px;font-weight:800}.status.ready{background:#dcfce7;color:#166534}.status.failed{background:#fee2e2;color:#991b1b}@media(max-width:760px){.preflight-summary{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div>
            <a class="sb-back-link" href="<?= sbPreflightEscape($basePath) ?>/deployment.php">← Развёртывание</a>
            <h1 class="sb-title">Preflight-проверка</h1>
            <p class="sb-subtitle">Окружение, PostgreSQL, миграции, Битрикс, файловая система и worker</p>
        </div>
        <form method="post"><?= bitrix_sessid_post() ?><button class="sb-btn sb-btn-primary" type="submit">Запустить проверку</button></form>
    </div>

    <?php if ($error !== ''): ?><section class="sb-panel" style="color:#991b1b"><?= sbPreflightEscape($error) ?></section><?php endif; ?>

    <?php if (is_array($result)): ?>
        <section class="preflight-summary">
            <div class="preflight-card">Готовность<div class="preflight-value"><span class="status <?= !empty($result['ready']) ? 'ready' : 'failed' ?>"><?= !empty($result['ready']) ? 'ГОТОВО' : 'СТОП' ?></span></div></div>
            <div class="preflight-card">Проверок<div class="preflight-value"><?= (int)$result['checksCount'] ?></div></div>
            <div class="preflight-card">Ошибок<div class="preflight-value"><?= (int)$result['errorsCount'] ?></div></div>
            <div class="preflight-card">Предупреждений<div class="preflight-value"><?= (int)$result['warningsCount'] ?></div></div>
        </section>

        <section class="sb-panel">
            <h2 class="sb-panel-title">Результаты</h2>
            <div class="check-list">
                <?php foreach ((array)$result['checks'] as $check): ?>
                    <div class="check <?= sbPreflightEscape((string)$check['level']) ?>">
                        <div class="check-title"><?= sbPreflightEscape((string)$check['title']) ?> <span class="sb-subtitle">[<?= sbPreflightEscape((string)$check['code']) ?>]</span></div>
                        <div class="check-message"><?= sbPreflightEscape((string)$check['message']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="sb-panel">Проверка ещё не запускалась.</section>
    <?php endif; ?>
</div>
</body>
</html>

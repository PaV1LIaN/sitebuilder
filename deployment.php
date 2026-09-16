<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/MigrationService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/BackupService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PreflightService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PublicRouteService.php';

sitebuilder_require_bitrix_admin();

global $APPLICATION, $USER;
$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$message = '';
$error = '';
$operationResult = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!check_bitrix_sessid()) {
        $error = 'Сессия устарела. Обновите страницу.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        try {
            if ($action === 'bootstrap') {
                $operationResult = MigrationService::bootstrap((int)$USER->GetID());
                $operationResult['backupStorageMigration'] = BackupService::migrateLegacyFiles();
                $message = 'Реестр миграций и приватное хранилище копий синхронизированы.';
            } elseif ($action === 'migrate') {
                $operationResult = MigrationService::applyPending((int)$USER->GetID());
                $operationResult['backupStorageMigration'] = BackupService::migrateLegacyFiles();
                $message = 'Ожидающие миграции применены, хранилище копий проверено.';
            } elseif ($action === 'preflight') {
                $operationResult = PreflightService::run((int)$USER->GetID());
                $message = !empty($operationResult['ready'])
                    ? 'Preflight завершён: окружение готово.'
                    : 'Preflight завершён с ошибками.';
            } elseif ($action === 'installPublicRoute') {
                $operationResult = PublicRouteService::install($basePath);
                $message = 'Публичный ЧПУ-маршрут установлен.';
            } else {
                $error = 'Неизвестная операция.';
            }
        } catch (SiteBuilderMigrationException $e) {
            error_log('SiteBuilder deployment operation failed: ' . $e->getMessage());
            $error = 'Операция не выполнена: ' . $e->getMessage() . '.';
        } catch (Throwable $e) {
            error_log('SiteBuilder deployment operation failed: ' . $e->getMessage());
            $error = 'Операция не выполнена. Подробности записаны в журнал PHP.';
        }
    }
}

try {
    $migrationStatus = MigrationService::status();
    $runs = MigrationService::recentRuns(20);
} catch (Throwable $e) {
    $migrationStatus = ['registryReady' => false, 'pendingCount' => 0, 'driftCount' => 0, 'invalidCount' => 0, 'ready' => false, 'items' => []];
    $runs = [];
}

try {
    $publicRouteStatus = PublicRouteService::status($basePath);
} catch (Throwable $e) {
    $publicRouteStatus = [
        'available' => false,
        'installed' => false,
        'message' => 'Не удалось проверить публичный маршрут.',
    ];
}

function sbDeploymentEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sbDeploymentStateClass(string $state): string
{
    return in_array($state, ['applied', 'pending', 'drift', 'missing'], true) ? $state : 'pending';
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SiteBuilder / Развёртывание</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= sbDeploymentEscape($basePath) ?>/assets/admin/admin.css">
    <style>
        .deploy-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}.deploy-card{padding:16px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}.deploy-value{font-size:28px;font-weight:800;margin-top:6px}.deploy-table{width:100%;border-collapse:collapse}.deploy-table th,.deploy-table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}.state{display:inline-flex;padding:4px 9px;border-radius:999px;font-weight:800;font-size:12px}.state.applied,.state.succeeded{background:#dcfce7;color:#166534}.state.pending,.state.partial,.state.running{background:#fef3c7;color:#92400e}.state.drift,.state.missing,.state.failed{background:#fee2e2;color:#991b1b}.deploy-message{padding:13px 15px;border-radius:10px;margin-bottom:14px}.deploy-message.ok{background:#f0fdf4;color:#166534}.deploy-message.error{background:#fef2f2;color:#991b1b}.deploy-actions{display:flex;gap:10px;flex-wrap:wrap}.checksum{font-family:monospace;font-size:11px;word-break:break-all;color:#6b7280}@media(max-width:800px){.deploy-summary{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div>
            <a class="sb-back-link" href="<?= sbDeploymentEscape($basePath) ?>/index.php">← К списку сайтов</a>
            <h1 class="sb-title">Развёртывание SiteBuilder</h1>
            <p class="sb-subtitle">Миграции, контроль SQL-файлов и готовность окружения</p>
        </div>
        <div class="deploy-actions">
            <a class="sb-btn sb-btn-light" href="<?= sbDeploymentEscape($basePath) ?>/preflight.php">Подробный preflight</a>
        </div>
    </div>

    <?php if ($message !== ''): ?><div class="deploy-message ok"><?= sbDeploymentEscape($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="deploy-message error"><?= sbDeploymentEscape($error) ?></div><?php endif; ?>

    <section class="deploy-summary">
        <div class="deploy-card">Реестр<div class="deploy-value"><?= !empty($migrationStatus['registryReady']) ? 'OK' : '—' ?></div></div>
        <div class="deploy-card">Ожидают<div class="deploy-value"><?= (int)($migrationStatus['pendingCount'] ?? 0) ?></div></div>
        <div class="deploy-card">Drift / файлы<div class="deploy-value"><?= (int)($migrationStatus['driftCount'] ?? 0) + (int)($migrationStatus['invalidCount'] ?? 0) ?></div></div>
        <div class="deploy-card">Схема<div class="deploy-value"><?= !empty($migrationStatus['ready']) ? 'Готова' : 'Не готова' ?></div></div>
        <div class="deploy-card">Публичный URL<div class="deploy-value"><?= !empty($publicRouteStatus['installed']) ? 'ЧПУ' : '—' ?></div></div>
    </section>

    <section class="sb-panel">
        <h2 class="sb-panel-title">Действия</h2>
        <div class="deploy-actions">
            <form method="post"><?= bitrix_sessid_post() ?><input type="hidden" name="action" value="bootstrap"><button class="sb-btn sb-btn-light" type="submit">Синхронизировать реестр</button></form>
            <form method="post"><?= bitrix_sessid_post() ?><input type="hidden" name="action" value="migrate"><button class="sb-btn sb-btn-primary" type="submit" <?= empty($migrationStatus['registryReady']) ? 'disabled' : '' ?>>Применить ожидающие миграции</button></form>
            <form method="post"><?= bitrix_sessid_post() ?><input type="hidden" name="action" value="preflight"><button class="sb-btn sb-btn-light" type="submit">Запустить preflight</button></form>
            <form method="post"><?= bitrix_sessid_post() ?><input type="hidden" name="action" value="installPublicRoute"><button class="sb-btn sb-btn-light" type="submit" <?= empty($publicRouteStatus['available']) ? 'disabled' : '' ?>>Установить публичные URL</button></form>
        </div>
        <p class="sb-subtitle" style="margin-top:12px"><?= sbDeploymentEscape((string)($publicRouteStatus['message'] ?? '')) ?></p>
        <?php if (is_array($operationResult)): ?>
            <pre class="sb-output" style="margin-top:14px;max-height:320px;overflow:auto"><?= sbDeploymentEscape((string)json_encode($operationResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php endif; ?>
    </section>

    <section class="sb-panel">
        <h2 class="sb-panel-title">Миграции</h2>
        <div style="overflow:auto">
            <table class="deploy-table">
                <thead><tr><th>Этап</th><th>Файл</th><th>Состояние</th><th>Источник</th><th>Checksum</th></tr></thead>
                <tbody>
                <?php foreach ((array)($migrationStatus['items'] ?? []) as $item): ?>
                    <?php $applied = is_array($item['applied'] ?? null) ? $item['applied'] : []; ?>
                    <tr>
                        <td><?= (int)$item['stage'] ?></td>
                        <td><strong><?= sbDeploymentEscape((string)$item['title']) ?></strong><br><span class="sb-subtitle"><?= sbDeploymentEscape((string)$item['filename']) ?></span></td>
                        <td><span class="state <?= sbDeploymentStateClass((string)$item['state']) ?>"><?= sbDeploymentEscape((string)$item['state']) ?></span></td>
                        <td><?= sbDeploymentEscape((string)($applied['source'] ?? '—')) ?><br><span class="sb-subtitle"><?= sbDeploymentEscape((string)($applied['applied_at'] ?? '')) ?></span></td>
                        <td><div class="checksum"><?= sbDeploymentEscape((string)$item['checksum']) ?></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($migrationStatus['items'])): ?><tr><td colspan="5">Реестр миграций ещё не установлен.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="sb-panel">
        <h2 class="sb-panel-title">Последние запуски</h2>
        <div style="overflow:auto">
            <table class="deploy-table">
                <thead><tr><th>ID</th><th>Режим</th><th>Статус</th><th>Начало</th><th>Длительность</th><th>Результат</th></tr></thead>
                <tbody>
                <?php foreach ($runs as $run): ?>
                    <tr>
                        <td>#<?= (int)$run['id'] ?></td>
                        <td><?= sbDeploymentEscape((string)$run['mode']) ?></td>
                        <td><span class="state <?= sbDeploymentEscape((string)$run['status']) ?>"><?= sbDeploymentEscape((string)$run['status']) ?></span></td>
                        <td><?= sbDeploymentEscape((string)$run['started_at']) ?></td>
                        <td><?= $run['duration_ms'] !== null ? (int)$run['duration_ms'] . ' мс' : '—' ?></td>
                        <td>выполнено <?= (int)$run['applied_count'] ?>, baseline <?= (int)$run['baseline_count'] ?>, пропущено <?= (int)$run['skipped_count'] ?><?= !empty($run['error_code']) ? '; ' . sbDeploymentEscape((string)$run['error_code']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$runs): ?><tr><td colspan="6">Запусков пока нет.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</body>
</html>

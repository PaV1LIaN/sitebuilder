<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/MaintenanceService.php';

sitebuilder_require_bitrix_admin();

global $USER;

$result = null;
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!check_bitrix_sessid()) {
        $error = 'Сессия устарела. Обновите страницу.';
    } else {
        try {
            $result = MaintenanceService::run(true, (int)$USER->GetID());
        } catch (Throwable $e) {
            error_log('SiteBuilder manual maintenance failed: ' . $e->getMessage());
            $error = $e->getMessage() === 'MAINTENANCE_ALREADY_RUNNING'
                ? 'Очистка уже выполняется другим процессом.'
                : 'Не удалось выполнить очистку. Подробности записаны в журнал PHP.';
        }
    }
}

function sbStage7MaintenanceEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Обслуживание SiteBuilder — этап 7</title>
    <style>body{margin:0;padding:32px;font-family:Arial,sans-serif;background:#f3f6fb;color:#1f2937}.card{max-width:760px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px}.notice{margin:16px 0;padding:14px;border-radius:10px}.ok{background:#f0fdf4;color:#166534}.error{background:#fef2f2;color:#991b1b}button{padding:11px 18px;border:0;border-radius:9px;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}pre{white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:16px;border-radius:10px}</style>
</head>
<body><div class="card">
    <h1>Очистка истории SiteBuilder</h1>
    <p>Удаляет старые ревизии, снимки корзины и записи аудита согласно <code>config/maintenance.php</code>. Последняя ревизия каждой сущности сохраняется.</p>
    <?php if ($error !== ''): ?><div class="notice error"><?= sbStage7MaintenanceEscape($error) ?></div><?php endif; ?>
    <?php if (is_array($result)): ?><div class="notice ok">Очистка завершена.</div><pre><?= sbStage7MaintenanceEscape(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre><?php endif; ?>
    <form method="post"><?= bitrix_sessid_post() ?><button type="submit">Запустить очистку</button></form>
</div></body></html>

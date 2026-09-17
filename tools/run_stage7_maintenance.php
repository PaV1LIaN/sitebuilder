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
    <link rel="stylesheet" href="../assets/tools/run-stage7-maintenance.css?v=20260917">
</head>
<body><div class="card">
    <h1>Очистка истории SiteBuilder</h1>
    <p>Удаляет старые ревизии, снимки корзины и записи аудита согласно <code>config/maintenance.php</code>. Последняя ревизия каждой сущности сохраняется.</p>
    <?php if ($error !== ''): ?><div class="notice error"><?= sbStage7MaintenanceEscape($error) ?></div><?php endif; ?>
    <?php if (is_array($result)): ?><div class="notice ok">Очистка завершена.</div><pre><?= sbStage7MaintenanceEscape(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre><?php endif; ?>
    <form method="post"><?= bitrix_sessid_post() ?><button type="submit">Запустить очистку</button></form>
</div></body></html>

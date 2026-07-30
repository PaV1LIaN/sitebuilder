<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/MigrationService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/BackupService.php';

sitebuilder_require_bitrix_admin();

global $USER;

$message = '';
$error = '';
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!check_bitrix_sessid()) {
        $error = 'Сессия устарела. Обновите страницу.';
    } else {
        try {
            $result = MigrationService::bootstrap((int)$USER->GetID());
            $result['backupStorageMigration'] = BackupService::migrateLegacyFiles();
            $message = 'Реестр миграций создан, состояние схемы и приватное хранилище копий синхронизированы.';
        } catch (SiteBuilderMigrationException $e) {
            error_log('SiteBuilder stage 13 migration failed: ' . $e->getMessage());
            $error = 'Не удалось выполнить развёртывание: ' . $e->getMessage() . '.';
        } catch (Throwable $e) {
            error_log('SiteBuilder stage 13 migration failed: ' . $e->getMessage());
            $error = 'Не удалось применить этап 13. Подробности записаны в журнал PHP.';
        }
    }
}

try {
    $status = MigrationService::status();
} catch (Throwable $e) {
    $status = [
        'registryReady' => false,
        'pendingCount' => 0,
        'driftCount' => 0,
        'items' => [],
    ];
}

function sbStage13Escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Миграция SiteBuilder — этап 13</title>
    <style>
        body{margin:0;padding:32px;font-family:Arial,sans-serif;background:#f3f6fb;color:#1f2937}
        .card{max-width:980px;margin:0 auto;padding:28px;background:#fff;border:1px solid #e5e7eb;border-radius:16px}
        .notice{margin:16px 0;padding:13px 15px;border-radius:10px}.ok{color:#166534;background:#f0fdf4;border:1px solid #bbf7d0}.error{color:#991b1b;background:#fef2f2;border:1px solid #fecaca}
        button{padding:11px 18px;border:0;border-radius:9px;color:#fff;background:#2563eb;cursor:pointer;font-weight:700}
        table{width:100%;border-collapse:collapse;margin-top:20px}th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:14px}
        .badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-weight:700;font-size:12px}.applied{background:#dcfce7;color:#166534}.pending{background:#fef3c7;color:#92400e}.drift,.missing{background:#fee2e2;color:#991b1b}
        code{background:#f3f4f6;padding:2px 5px;border-radius:5px}
    </style>
</head>
<body>
<div class="card">
    <h1>Миграция этапа 13</h1>
    <p>Создаёт единый реестр миграций, проверяет SHA-256 SQL-файлов, регистрирует уже установленную схему и выполняет только действительно отсутствующие миграции.</p>
    <p><strong>Перед запуском:</strong> резервная копия PostgreSQL, остановленный worker и установленные файлы этапа 13.</p>

    <?php if ($message !== ''): ?>
        <div class="notice ok"><?= sbStage13Escape($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="notice error"><?= sbStage13Escape($error) ?></div>
    <?php endif; ?>

    <?php if (is_array($result)): ?>
        <div class="notice ok">
            Выполнено: <?= (int)($result['appliedCount'] ?? 0) ?>,
            зарегистрировано по существующей схеме: <?= (int)($result['baselineCount'] ?? 0) ?>,
            пропущено: <?= (int)($result['skippedCount'] ?? 0) ?>.
        </div>
    <?php endif; ?>

    <form method="post">
        <?= bitrix_sessid_post() ?>
        <button type="submit">Установить реестр и синхронизировать миграции</button>
    </form>

    <table>
        <thead><tr><th>Этап</th><th>Миграция</th><th>Состояние</th><th>Fingerprint</th></tr></thead>
        <tbody>
        <?php foreach ((array)($status['items'] ?? []) as $item): ?>
            <tr>
                <td><?= (int)$item['stage'] ?></td>
                <td><code><?= sbStage13Escape((string)$item['filename']) ?></code><br><?= sbStage13Escape((string)$item['title']) ?></td>
                <td><span class="badge <?= sbStage13Escape((string)$item['state']) ?>"><?= sbStage13Escape((string)$item['state']) ?></span></td>
                <td><?= !empty($item['fingerprintPassed']) ? 'готов' : 'не найден' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($status['items'])): ?>
            <tr><td colspan="4">Реестр ещё не установлен.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>

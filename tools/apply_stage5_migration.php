<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';

global $USER;

sitebuilder_require_auth();

if (!$USER->IsAdmin()) {
    http_response_code(403);
    echo 'Доступ запрещён';
    exit;
}

$message = '';
$error = '';
$migrationFile = dirname(__DIR__) . '/migrations/20260729_001_entity_versions.sql';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!check_bitrix_sessid()) {
        $error = 'Сессия устарела. Обновите страницу.';
    } elseif (!is_file($migrationFile)) {
        $error = 'Файл миграции не найден.';
    } else {
        try {
            $sql = file_get_contents($migrationFile);
            if (!is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('EMPTY_MIGRATION');
            }

            /*
             * Файл также можно применять через psql, поэтому он содержит
             * BEGIN/COMMIT. Здесь транзакцией управляем через PDO, чтобы при
             * любой ошибке гарантированно выполнить rollback соединения.
             */
            $sql = preg_replace('/^\s*BEGIN\s*;\s*/i', '', $sql, 1);
            $sql = preg_replace('/\s*COMMIT\s*;\s*$/i', '', (string)$sql, 1);

            $pdo = sb_db();
            if ($pdo->inTransaction()) {
                throw new RuntimeException('MIGRATION_TRANSACTION_ALREADY_ACTIVE');
            }

            $pdo->beginTransaction();

            try {
                $pdo->exec((string)$sql);
                $pdo->commit();
            } catch (Throwable $migrationError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $migrationError;
            }

            $message = 'Миграция версий и истории успешно применена.';
        } catch (Throwable $e) {
            error_log('SiteBuilder stage 5 migration failed: ' . $e->getMessage());
            $error = 'Не удалось применить миграцию. Подробности записаны в журнал PHP.';
        }
    }
}

function sbStage5Escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Миграция SiteBuilder — этап 5</title>
    <style>
        body { margin:0; padding:32px; font-family:Arial,sans-serif; background:#f3f6fb; color:#1f2937; }
        .card { max-width:720px; margin:0 auto; padding:28px; background:#fff; border:1px solid #e5e7eb; border-radius:16px; }
        .notice { margin:16px 0; padding:12px 14px; border-radius:10px; }
        .ok { color:#166534; background:#f0fdf4; border:1px solid #bbf7d0; }
        .error { color:#991b1b; background:#fef2f2; border:1px solid #fecaca; }
        button { padding:11px 18px; border:0; border-radius:9px; color:#fff; background:#2563eb; cursor:pointer; font-weight:700; }
        code { background:#f3f4f6; padding:2px 5px; border-radius:5px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Миграция этапа 5</h1>
    <p>Добавляет колонки <code>version</code> страницам и блокам, создаёт таблицу <code>sitebuilder.entity_revision</code> и сохраняет исходные состояния существующих объектов.</p>

    <?php if ($message !== ''): ?>
        <div class="notice ok"><?= sbStage5Escape($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error"><?= sbStage5Escape($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <?= bitrix_sessid_post() ?>
        <button type="submit">Применить миграцию</button>
    </form>
</div>
</body>
</html>

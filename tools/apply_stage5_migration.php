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
    <link rel="stylesheet" href="../assets/tools/apply-stage5-migration.css?v=20260917">
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

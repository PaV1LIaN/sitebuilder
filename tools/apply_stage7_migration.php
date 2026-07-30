<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageSectionRepository.php';

global $USER;

sitebuilder_require_auth();

if (!$USER->IsAdmin()) {
    http_response_code(403);
    echo 'Доступ запрещён';
    exit;
}

$message = '';
$error = '';
$importResult = null;
$migrationFile = dirname(__DIR__) . '/migrations/20260729_003_page_sections_audit_retention.sql';

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

            $sql = preg_replace('/^\s*BEGIN\s*;\s*/i', '', $sql, 1);
            $sql = preg_replace('/\s*COMMIT\s*;\s*$/i', '', (string)$sql, 1);

            $pdo = sb_db();
            if ($pdo->inTransaction()) {
                throw new RuntimeException('MIGRATION_TRANSACTION_ALREADY_ACTIVE');
            }

            $pdo->beginTransaction();
            try {
                $pdo->exec((string)$sql);
                $importResult = PageSectionRepository::importLegacyJson();
                $pdo->commit();
            } catch (Throwable $migrationError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $migrationError;
            }

            $message = 'Миграция этапа 7 применена. Секции переведены в PostgreSQL, журнал и retention созданы.';
        } catch (Throwable $e) {
            error_log('SiteBuilder stage 7 migration failed: ' . $e->getMessage());
            $error = 'Не удалось применить миграцию. Подробности записаны в журнал PHP.';
        }
    }
}

function sbStage7Escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Миграция SiteBuilder — этап 7</title>
    <style>
        body{margin:0;padding:32px;font-family:Arial,sans-serif;background:#f3f6fb;color:#1f2937}.card{max-width:820px;margin:0 auto;padding:28px;background:#fff;border:1px solid #e5e7eb;border-radius:16px}.notice{margin:16px 0;padding:12px 14px;border-radius:10px}.ok{color:#166534;background:#f0fdf4;border:1px solid #bbf7d0}.error{color:#991b1b;background:#fef2f2;border:1px solid #fecaca}button{padding:11px 18px;border:0;border-radius:9px;color:#fff;background:#2563eb;cursor:pointer;font-weight:700}code{background:#f3f4f6;padding:2px 5px;border-radius:5px}.stats{margin:12px 0;padding:14px;background:#f8fafc;border-radius:10px;line-height:1.7}
    </style>
</head>
<body>
<div class="card">
    <h1>Миграция этапа 7</h1>
    <p>Создаёт <code>sitebuilder.page_section</code>, <code>sitebuilder.audit_log</code> и <code>sitebuilder.maintenance_state</code>. Затем безопасно импортирует существующий <code>/upload/sitebuilder/page_sections.json</code>.</p>
    <p><strong>Перед запуском:</strong> установи этап 6 и сделай резервную копию PostgreSQL и каталога <code>/upload/sitebuilder</code>.</p>

    <?php if ($message !== ''): ?>
        <div class="notice ok"><?= sbStage7Escape($message) ?></div>
    <?php endif; ?>

    <?php if (is_array($importResult)): ?>
        <div class="stats">
            JSON найден: <?= !empty($importResult['found']) ? 'да' : 'нет' ?><br>
            Всего записей: <?= (int)($importResult['total'] ?? 0) ?><br>
            Добавлено: <?= (int)($importResult['imported'] ?? 0) ?><br>
            Уже существовало и не перезаписано: <?= (int)($importResult['existing'] ?? $importResult['updated'] ?? 0) ?><br>
            Пропущено: <?= (int)($importResult['skipped'] ?? 0) ?><br>
            <?php if (!empty($importResult['backup'])): ?>
                Резервная копия JSON: <code><?= sbStage7Escape((string)$importResult['backup']) ?></code>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error"><?= sbStage7Escape($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <?= bitrix_sessid_post() ?>
        <button type="submit">Применить миграцию этапа 7</button>
    </form>
</div>
</body>
</html>

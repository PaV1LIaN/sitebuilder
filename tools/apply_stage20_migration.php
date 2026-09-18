<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/MigrationService.php';

global $USER;
sitebuilder_require_auth();

if (!$USER->IsAdmin()) {
    http_response_code(403);
    exit('Доступ запрещён');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_bitrix_sessid()) {
        $error = 'Сессия устарела. Обновите страницу.';
    } else {
        try {
            $result = MigrationService::applyPending((int)$USER->GetID(), 'migrate');
            $message = 'Миграции применены. Новых миграций: ' . (int)($result['appliedCount'] ?? 0) . '.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$status = MigrationService::status();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Миграция Stage 20</title>
    <link rel="stylesheet" href="../assets/tools/apply-stage20-migration.css?v=20260917">
</head>
<body><div class="box">
<h1>SiteBuilder Stage 20</h1>
<p>Добавляет SEO-настройки страниц и хранение заявок из блоков формы.</p>
<?php if ($message !== ''): ?><div class="ok"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
<form method="post"><?= bitrix_sessid_post() ?><button type="submit">Применить ожидающие миграции</button></form>
<table><thead><tr><th>Этап</th><th>Миграция</th><th>Состояние</th></tr></thead><tbody>
<?php foreach (($status['items'] ?? []) as $item): ?><tr><td><?= (int)($item['stage'] ?? 0) ?></td><td><?= htmlspecialchars((string)($item['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td><td class="<?= htmlspecialchars((string)($item['state'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($item['state'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?>
</tbody></table>
</div></body></html>

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
            $message = 'Миграции применены. Новых миграций: '
                . (int)($result['appliedCount'] ?? 0) . '.';
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
    <title>Миграция Stage 21</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f7fb;color:#172033;margin:0;padding:32px}.box{max-width:980px;margin:auto;background:#fff;border:1px solid #dfe6ef;border-radius:18px;padding:28px;box-shadow:0 18px 50px rgba(15,23,42,.08)}h1{margin-top:0}.ok,.err{padding:12px 14px;border-radius:10px;margin:14px 0}.ok{background:#ecfdf3;color:#166534}.err{background:#fef2f2;color:#991b1b}button{border:0;border-radius:10px;padding:12px 18px;background:#2563eb;color:white;font-weight:700;cursor:pointer}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{text-align:left;padding:10px;border-bottom:1px solid #e5e7eb}.pending{color:#b45309}.applied{color:#15803d}.drift,.missing{color:#b91c1c}
    </style>
</head>
<body><div class="box">
<h1>SiteBuilder Stage 21</h1>
<p>Добавляет индивидуальные наследуемые права на папки компонента «Диск».</p>
<?php if ($message !== ''): ?><div class="ok"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
<form method="post"><?= bitrix_sessid_post() ?><button type="submit">Применить ожидающие миграции</button></form>
<table><thead><tr><th>Этап</th><th>Миграция</th><th>Состояние</th></tr></thead><tbody>
<?php foreach (($status['items'] ?? []) as $item): ?><tr><td><?= (int)($item['stage'] ?? 0) ?></td><td><?= htmlspecialchars((string)($item['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td><td class="<?= htmlspecialchars((string)($item['state'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($item['state'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?>
</tbody></table>
</div></body></html>

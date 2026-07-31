<?php

declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/helpers.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/IdSequenceService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/RevisionService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/RequestLockService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessRepository.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/MigrationService.php';

sitebuilder_require_bitrix_admin();

global $USER;

function sbDiagEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sbDiagThrowable(Throwable $e): array
{
    $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $file = $e->getFile();

    if ($documentRoot !== '' && str_starts_with($file, $documentRoot)) {
        $file = substr($file, strlen($documentRoot));
    }

    return [
        'ok' => false,
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'sqlState' => $e instanceof PDOException
            ? sb_db_exception_sqlstate($e)
            : '',
        'file' => $file,
        'line' => $e->getLine(),
    ];
}

function sbDiagRun(string $name, callable $callback): array
{
    $startedAt = microtime(true);

    try {
        $value = $callback();

        return [
            'name' => $name,
            'ok' => true,
            'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
            'result' => $value,
        ];
    } catch (Throwable $e) {
        return array_merge([
            'name' => $name,
            'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
        ], sbDiagThrowable($e));
    }
}

function sbDiagRelationExists(string $relation): bool
{
    $stmt = sb_db()->prepare('SELECT to_regclass(:relation) IS NOT NULL');
    $stmt->execute([':relation' => $relation]);

    return (bool)$stmt->fetchColumn();
}

function sbDiagColumnExists(string $schema, string $table, string $column): bool
{
    $row = sb_db_fetch_one(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema=:schema
           AND table_name=:table
           AND column_name=:column',
        [
            ':schema' => $schema,
            ':table' => $table,
            ':column' => $column,
        ]
    );

    return $row !== null;
}

function sbDiagRollbackOpenTransaction(): void
{
    try {
        if (sb_db()->inTransaction()) {
            sb_db()->rollBack();
        }
    } catch (Throwable $ignored) {
    }

    $GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'] = false;
    $GLOBALS['SB_SCOPE_TRANSACTION_ACTIVE'] = false;
    $GLOBALS['SB_REQUEST_RESOURCE_LOCKS'] = [];
}

$sites = [];
$initialError = null;

try {
    $sites = sb_read_sites();
} catch (Throwable $e) {
    $initialError = sbDiagThrowable($e);
}

$requestedSiteId = (int)($_POST['siteId'] ?? $_GET['siteId'] ?? 0);
$siteId = $requestedSiteId;

if ($siteId <= 0 && !empty($sites)) {
    $siteId = (int)($sites[0]['id'] ?? 0);
}

$report = [
    'generatedAt' => date('c'),
    'phpVersion' => PHP_VERSION,
    'userId' => is_object($USER) ? (int)$USER->GetID() : 0,
    'siteId' => $siteId,
    'initialError' => $initialError,
    'checks' => [],
    'writeTests' => [],
    'notes' => [
        'Тесты записи выполняются внутри транзакции и откатываются.',
        'PostgreSQL sequence не откатывается, поэтому после теста возможен пропуск одного ID страницы. Это нормально.',
    ],
];

$report['checks'][] = sbDiagRun('database.connection', static function (): array {
    return sb_db_fetch_one('SELECT current_database() AS database, current_user AS db_user, version() AS version') ?? [];
});

$report['checks'][] = sbDiagRun('schema.objects', static function (): array {
    $relations = [
        'sitebuilder.site',
        'sitebuilder.page',
        'sitebuilder.block',
        'sitebuilder.entity_revision',
        'sitebuilder.audit_log',
        'sitebuilder.schema_migration',
        'sitebuilder.page_id_seq',
    ];

    $result = [];
    foreach ($relations as $relation) {
        $result[$relation] = sbDiagRelationExists($relation);
    }

    return $result;
});

$report['checks'][] = sbDiagRun('schema.columns', static function (): array {
    $columns = [
        ['page', 'version'],
        ['page', 'seo_json'],
        ['page', 'published_at'],
        ['entity_revision', 'restored_from_revision_id'],
        ['entity_revision', 'snapshot_json'],
    ];

    $result = [];
    foreach ($columns as [$table, $column]) {
        $result['sitebuilder.' . $table . '.' . $column] = sbDiagColumnExists(
            'sitebuilder',
            $table,
            $column
        );
    }

    return $result;
});

$report['checks'][] = sbDiagRun('schema.page_constraints', static function (): array {
    return sb_db_fetch_all(
        "SELECT conname, pg_get_constraintdef(oid) AS definition
         FROM pg_constraint
         WHERE conrelid='sitebuilder.page'::regclass
         ORDER BY conname"
    );
});

$report['checks'][] = sbDiagRun('schema.revision_constraints', static function (): array {
    return sb_db_fetch_all(
        "SELECT conname, pg_get_constraintdef(oid) AS definition
         FROM pg_constraint
         WHERE conrelid='sitebuilder.entity_revision'::regclass
         ORDER BY conname"
    );
});

$report['checks'][] = sbDiagRun('migration.status', static function (): array {
    $status = MigrationService::status();

    return [
        'registryReady' => (bool)($status['registryReady'] ?? false),
        'ready' => (bool)($status['ready'] ?? false),
        'pendingCount' => (int)($status['pendingCount'] ?? 0),
        'driftCount' => (int)($status['driftCount'] ?? 0),
        'invalidCount' => (int)($status['invalidCount'] ?? 0),
        'items' => array_map(static function (array $item): array {
            return [
                'key' => (string)($item['key'] ?? ''),
                'stage' => (int)($item['stage'] ?? 0),
                'state' => (string)($item['state'] ?? ''),
                'fingerprintPassed' => (bool)($item['fingerprintPassed'] ?? false),
            ];
        }, (array)($status['items'] ?? [])),
    ];
});

if ($siteId > 0) {
    $report['checks'][] = sbDiagRun('site.context', static function () use ($siteId, $USER): array {
        $site = sb_find_site($siteId);
        $userId = is_object($USER) ? (int)$USER->GetID() : 0;

        return [
            'site' => $site,
            'pageCount' => (int)(sb_db_fetch_one(
                'SELECT COUNT(*) AS count FROM sitebuilder.page WHERE site_id=:site_id',
                [':site_id' => $siteId]
            )['count'] ?? 0),
            'globalEdit' => PageAccessService::hasGlobalSiteAccess(
                $siteId,
                $userId,
                'edit'
            ),
        ];
    });
}

$runWriteTests = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (string)($_POST['action'] ?? '') === 'run_write_tests';

if ($runWriteTests) {
    if (!check_bitrix_sessid()) {
        $report['writeTests'][] = [
            'name' => 'session',
            'ok' => false,
            'message' => 'BAD_SESSID',
        ];
    } elseif ($siteId <= 0) {
        $report['writeTests'][] = [
            'name' => 'site',
            'ok' => false,
            'message' => 'SITE_ID_REQUIRED',
        ];
    } else {
        $report['writeTests'][] = sbDiagRun('write.page_create.full_path', static function () use ($siteId, $USER): array {
            sbDiagRollbackOpenTransaction();
            $pdo = sb_db();
            $pdo->beginTransaction();

            try {
                RequestLockService::lockMutation('page.create', [
                    'siteId' => $siteId,
                    'parentId' => 0,
                ]);

                $id = RevisionService::nextEntityId(RevisionService::ENTITY_PAGE);
                $userId = is_object($USER) ? (int)$USER->GetID() : 0;
                $slug = '__diagnostic-' . $id . '-' . date('YmdHis');

                $page = sb_normalize_page_record([
                    'id' => $id,
                    'siteId' => $siteId,
                    'title' => 'SiteBuilder diagnostic page',
                    'slug' => $slug,
                    'parentId' => 0,
                    'sort' => 2147483000,
                    'status' => 'draft',
                    'publishedAt' => null,
                    'seo' => [],
                    'createdBy' => $userId,
                    'createdAt' => date('c'),
                    'updatedBy' => $userId,
                    'updatedAt' => date('c'),
                    'version' => 1,
                ]);

                sb_write_pages([$page]);

                $saved = RevisionService::getPage($id);
                $revision = sb_db_fetch_one(
                    'SELECT id, entity_type, entity_id, entity_version, operation
                     FROM sitebuilder.entity_revision
                     WHERE entity_type=:type AND entity_id=:id
                     ORDER BY id DESC
                     LIMIT 1',
                    [
                        ':type' => RevisionService::ENTITY_PAGE,
                        ':id' => $id,
                    ]
                );

                return [
                    'allocatedPageId' => $id,
                    'pageInserted' => $saved !== null,
                    'revisionInserted' => $revision !== null,
                    'revision' => $revision,
                    'rolledBack' => true,
                ];
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        });

        $report['writeTests'][] = sbDiagRun('write.page_publish.full_path', static function () use ($siteId, $USER): array {
            sbDiagRollbackOpenTransaction();
            $pdo = sb_db();
            $page = sb_db_fetch_one(
                'SELECT id
                 FROM sitebuilder.page
                 WHERE site_id=:site_id
                 ORDER BY id ASC
                 LIMIT 1',
                [':site_id' => $siteId]
            );

            if (!$page) {
                return [
                    'skipped' => true,
                    'reason' => 'SITE_HAS_NO_PAGES',
                ];
            }

            $pageId = (int)$page['id'];
            $pdo->beginTransaction();

            try {
                RequestLockService::lockMutation('page.setStatus', [
                    'id' => $pageId,
                ]);

                $current = RevisionService::getPage($pageId, true);
                if (!$current) {
                    throw new RuntimeException('PAGE_NOT_FOUND');
                }

                $current['status'] = $current['status'] === 'published'
                    ? 'draft'
                    : 'published';
                $current['publishedAt'] = $current['status'] === 'published'
                    ? date('c')
                    : null;

                $saved = RevisionService::savePage(
                    $current,
                    (int)$current['version'],
                    is_object($USER) ? (int)$USER->GetID() : 0,
                    'diagnostic_status_change'
                );

                return [
                    'pageId' => $pageId,
                    'oldVersion' => (int)$current['version'],
                    'newVersion' => (int)($saved['version'] ?? 0),
                    'newStatus' => (string)($saved['status'] ?? ''),
                    'rolledBack' => true,
                ];
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        });
    }

    sbDiagRollbackOpenTransaction();
}

$reportJson = json_encode(
    $report,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_INVALID_UTF8_SUBSTITUTE
);

if (!is_string($reportJson)) {
    $reportJson = '{"ok":false,"error":"REPORT_ENCODING_FAILED"}';
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SiteBuilder — диагностика записи</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f4f6;color:#111827;font-family:Arial,sans-serif}.wrap{max-width:1180px;margin:0 auto;padding:24px}.top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;margin-bottom:16px}.title{margin:0 0 8px;font-size:28px}.muted{color:#6b7280;line-height:1.5}.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.btn{border:0;border-radius:10px;padding:11px 16px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}.primary{background:#2563eb;color:#fff}.secondary{background:#e5e7eb;color:#111827}.select{min-width:240px;padding:10px;border:1px solid #d1d5db;border-radius:9px;background:#fff}.output{white-space:pre-wrap;word-break:break-word;overflow:auto;max-height:70vh;background:#111827;color:#e5e7eb;border-radius:12px;padding:16px;font:13px/1.55 Consolas,monospace}.notice{padding:12px 14px;border-radius:10px;background:#eff6ff;color:#1e40af;margin-bottom:14px}@media(max-width:760px){.top{display:block}.actions{margin-top:14px}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1 class="title">Диагностика операций записи</h1>
            <div class="muted">Страница доступна только администратору Битрикс. Тестовые изменения откатываются транзакцией.</div>
        </div>
        <a class="btn secondary" href="<?= sbDiagEscape('/local/sitebuilder/index.php') ?>">← К SiteBuilder</a>
    </div>

    <div class="card">
        <div class="notice">Выберите сайт и нажмите «Проверить создание и публикацию». После выполнения скопируйте весь отчёт.</div>
        <form method="post" class="actions">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="run_write_tests">
            <select class="select" name="siteId">
                <?php foreach ($sites as $site): ?>
                    <?php $optionId = (int)($site['id'] ?? 0); ?>
                    <option value="<?= $optionId ?>" <?= $optionId === $siteId ? 'selected' : '' ?>>
                        #<?= $optionId ?> — <?= sbDiagEscape((string)($site['name'] ?? $site['slug'] ?? 'Сайт')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn primary" type="submit">Проверить создание и публикацию</button>
            <button class="btn secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('report').textContent)">Копировать отчёт</button>
        </form>
    </div>

    <div class="card">
        <pre id="report" class="output"><?= sbDiagEscape($reportJson) ?></pre>
    </div>
</div>
</body>
</html>

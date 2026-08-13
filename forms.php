<?php

declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT']
    . '/bitrix/modules/main/include/prolog_before.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__
    . '/lib/FormSubmissionService.php';

global $USER, $APPLICATION;

sitebuilder_require_auth();

$siteId =
    (int)(
        $_GET['siteId']
        ?? $_POST['siteId']
        ?? 0
    );

if ($siteId <= 0) {
    http_response_code(400);
    exit('siteId required');
}

if (!$USER->IsAdmin()) {
    sb_require_content_manager(
        $siteId
    );
}

$basePath =
    rtrim(
        str_replace(
            $_SERVER['DOCUMENT_ROOT'],
            '',
            __DIR__
        ),
        '/'
    );

function sb_forms_h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES
        | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function sb_forms_status_label(
    string $status
): string {
    return [
        'new' => 'Новая',
        'in_progress' => 'В работе',
        'done' => 'Готово',
        'spam' => 'Спам',
    ][$status] ?? $status;
}

function sb_forms_date(
    string $value
): string {
    if ($value === '') {
        return '—';
    }

    $timestamp =
        strtotime($value);

    return $timestamp
        ? date(
            'd.m.Y H:i',
            $timestamp
        )
        : $value;
}

function sb_forms_redirect(
    string $basePath,
    int $siteId,
    array $filters,
    string $notice
): void {
    $params = [
        'siteId' => $siteId,
    ];

    if (
        trim(
            (string)(
                $filters['status']
                ?? ''
            )
        ) !== ''
    ) {
        $params['status'] =
            (string)$filters['status'];
    }

    if (
        (int)(
            $filters['blockId']
            ?? 0
        ) > 0
    ) {
        $params['blockId'] =
            (int)$filters['blockId'];
    }

    if (
        trim(
            (string)(
                $filters['search']
                ?? ''
            )
        ) !== ''
    ) {
        $params['q'] =
            (string)$filters['search'];
    }

    if ($notice !== '') {
        $params['notice'] =
            $notice;
    }

    header(
        'Location: '
        . $basePath
        . '/forms.php?'
        . http_build_query($params)
    );

    exit;
}

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {
    if (!check_bitrix_sessid()) {
        http_response_code(403);
        exit('SESSION_EXPIRED');
    }

    $returnFilters = [
        'status' =>
            trim(
                (string)(
                    $_POST['returnStatus']
                    ?? ''
                )
            ),
        'blockId' =>
            (int)(
                $_POST['returnBlockId']
                ?? 0
            ),
        'search' =>
            trim(
                (string)(
                    $_POST['returnQ']
                    ?? ''
                )
            ),
    ];

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    $action =
        trim(
            (string)(
                $_POST['formAction']
                ?? ''
            )
        );

    if (
        $action === 'status'
        && $id > 0
    ) {
        FormSubmissionService::updateStatus(
            $siteId,
            $id,
            (string)(
                $_POST['status']
                ?? 'new'
            ),
            (int)$USER->GetID()
        );

        sb_forms_redirect(
            $basePath,
            $siteId,
            $returnFilters,
            'status'
        );
    }

    if (
        $action === 'delete'
        && $id > 0
    ) {
        FormSubmissionService::delete(
            $siteId,
            $id
        );

        sb_forms_redirect(
            $basePath,
            $siteId,
            $returnFilters,
            'delete'
        );
    }

    sb_forms_redirect(
        $basePath,
        $siteId,
        $returnFilters,
        ''
    );
}

$status =
    trim(
        (string)(
            $_GET['status']
            ?? ''
        )
    );

if (
    !in_array(
        $status,
        [
            '',
            'new',
            'in_progress',
            'done',
            'spam',
        ],
        true
    )
) {
    $status = '';
}

$blockId =
    max(
        0,
        (int)(
            $_GET['blockId']
            ?? 0
        )
    );

$search =
    mb_substr(
        trim(
            (string)(
                $_GET['q']
                ?? ''
            )
        ),
        0,
        200
    );

$filters = [
    'status' => $status,
    'blockId' => $blockId,
    'search' => $search,
];

$summaryFilters = [
    'blockId' => $blockId,
    'search' => $search,
];

$forms =
    FormSubmissionService::formsForSite(
        $siteId
    );

$summary =
    FormSubmissionService::summary(
        $siteId,
        $summaryFilters
    );

$items =
    FormSubmissionService::list(
        $siteId,
        $filters,
        500
    );

$formMap = [];

foreach ($forms as $form) {
    $formMap[
        (int)$form['id']
    ] = $form;
}

$currentMatchingCount =
    $status !== ''
        ? (int)(
            $summary[$status]
            ?? 0
        )
        : (int)(
            $summary['total']
            ?? 0
        );

$notice =
    trim(
        (string)(
            $_GET['notice']
            ?? ''
        )
    );

$noticeText = [
    'status' =>
        'Статус заявки обновлён.',
    'delete' =>
        'Заявка удалена.',
][$notice] ?? '';

$baseParams = [
    'siteId' => $siteId,
];

if ($blockId > 0) {
    $baseParams['blockId'] =
        $blockId;
}

if ($search !== '') {
    $baseParams['q'] =
        $search;
}

$buildStatusUrl =
    static function (
        string $nextStatus
    ) use ($basePath, $baseParams): string {
        $params =
            $baseParams;

        if ($nextStatus !== '') {
            $params['status'] =
                $nextStatus;
        }

        return
            $basePath
            . '/forms.php?'
            . http_build_query(
                $params
            );
    };

$exportParams = [
    'siteId' => $siteId,
];

if ($status !== '') {
    $exportParams['status'] =
        $status;
}

if ($blockId > 0) {
    $exportParams['blockId'] =
        $blockId;
}

if ($search !== '') {
    $exportParams['q'] =
        $search;
}

$csvUrl =
    $basePath
    . '/forms_export.php?'
    . http_build_query(
        $exportParams
        + [
            'format' => 'csv',
        ]
    );

$xlsxUrl =
    $basePath
    . '/forms_export.php?'
    . http_build_query(
        $exportParams
        + [
            'format' => 'xlsx',
        ]
    );
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >
    <title>Заявки форм</title>

    <?php $APPLICATION->ShowHead(); ?>

    <link
        rel="stylesheet"
        href="<?= sb_forms_h($basePath) ?>/assets/admin/admin.css"
    >
    <link
        rel="stylesheet"
        href="<?= sb_forms_h($basePath) ?>/assets/admin/forms2-admin.css?v=1"
    >
</head>

<body class="sb-admin-body">
<div class="sb-page sb-forms2-page">
    <div class="sb-forms2-topbar">
        <div>
            <a
                class="sb-back-link"
                href="<?= sb_forms_h($basePath) ?>/editor.php?siteId=<?= $siteId ?>"
            >← В редактор</a>

            <h1 class="sb-title">
                Заявки форм
            </h1>

            <p class="sb-subtitle">
                Сайт #<?= $siteId ?>
                · найдено <?= $currentMatchingCount ?>
                · показано <?= count($items) ?>
            </p>
        </div>

        <div class="sb-forms2-export">
            <a
                class="sb-btn sb-btn-light"
                href="<?= sb_forms_h($csvUrl) ?>"
            >CSV</a>

            <a
                class="sb-btn sb-btn-primary"
                href="<?= sb_forms_h($xlsxUrl) ?>"
            >XLSX</a>
        </div>
    </div>

    <?php if ($noticeText !== ''): ?>
        <div class="sb-forms2-notice">
            <?= sb_forms_h($noticeText) ?>
        </div>
    <?php endif; ?>

    <nav
        class="sb-forms2-stats"
        aria-label="Статусы заявок"
    >
        <?php
        $statusCards = [
            '' => [
                'Все',
                (int)$summary['total'],
            ],
            'new' => [
                'Новые',
                (int)$summary['new'],
            ],
            'in_progress' => [
                'В работе',
                (int)$summary['in_progress'],
            ],
            'done' => [
                'Готово',
                (int)$summary['done'],
            ],
            'spam' => [
                'Спам',
                (int)$summary['spam'],
            ],
        ];
        ?>

        <?php foreach ($statusCards as $value => [$label, $count]): ?>
            <a
                class="sb-forms2-stat <?= $status === $value ? 'is-active' : '' ?>"
                href="<?= sb_forms_h($buildStatusUrl($value)) ?>"
                data-status="<?= sb_forms_h($value !== '' ? $value : 'all') ?>"
            >
                <span><?= sb_forms_h($label) ?></span>
                <strong><?= $count ?></strong>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="sb-forms2-filter">
        <form
            method="get"
            class="sb-forms2-filter__form"
        >
            <input
                type="hidden"
                name="siteId"
                value="<?= $siteId ?>"
            >

            <?php if ($status !== ''): ?>
                <input
                    type="hidden"
                    name="status"
                    value="<?= sb_forms_h($status) ?>"
                >
            <?php endif; ?>

            <label class="sb-field sb-forms2-search">
                <span>Поиск</span>
                <input
                    class="sb-input"
                    type="search"
                    name="q"
                    value="<?= sb_forms_h($search) ?>"
                    placeholder="Имя, email, телефон, текст заявки..."
                >
            </label>

            <label class="sb-field">
                <span>Форма</span>
                <select
                    class="sb-select"
                    name="blockId"
                >
                    <option value="0">
                        Все формы
                    </option>

                    <?php foreach ($forms as $form): ?>
                        <option
                            value="<?= (int)$form['id'] ?>"
                            <?= $blockId === (int)$form['id'] ? 'selected' : '' ?>
                        >
                            <?= sb_forms_h($form['label']) ?>
                            <?= $form['pageStatus'] !== 'published' ? ' · черновик' : '' ?>
                        </option>
                    <?php endforeach; ?>

                    <?php if (
                        $blockId > 0
                        && !isset($formMap[$blockId])
                    ): ?>
                        <option
                            value="<?= $blockId ?>"
                            selected
                        >
                            Форма #<?= $blockId ?> · удалена или недоступна
                        </option>
                    <?php endif; ?>
                </select>
            </label>

            <div class="sb-forms2-filter__actions">
                <button
                    class="sb-btn sb-btn-primary"
                    type="submit"
                >
                    Найти
                </button>

                <a
                    class="sb-btn sb-btn-light"
                    href="<?= sb_forms_h($basePath) ?>/forms.php?siteId=<?= $siteId ?>"
                >
                    Сбросить
                </a>
            </div>
        </form>
    </section>

    <?php if (!$items): ?>
        <div class="sb-panel sb-forms2-empty">
            <strong>Заявок по выбранным условиям нет.</strong>
            <span>
                Измените статус, форму или поисковый запрос.
            </span>
        </div>
    <?php endif; ?>

    <section class="sb-forms2-list">
        <?php foreach ($items as $item): ?>
            <?php
            $meta =
                is_array(
                    $item['meta']
                    ?? null
                )
                    ? $item['meta']
                    : [];

            $formTitle =
                trim(
                    (string)(
                        $meta['formTitle']
                        ?? ''
                    )
                );

            if ($formTitle === '') {
                $formTitle =
                    $formMap[
                        (int)$item['blockId']
                    ]['title']
                    ?? (
                        'Форма #'
                        . (int)$item['blockId']
                    );
            }

            $pageTitle =
                trim(
                    (string)(
                        $meta['pageTitle']
                        ?? ''
                    )
                );

            if ($pageTitle === '') {
                $pageTitle =
                    $formMap[
                        (int)$item['blockId']
                    ]['pageTitle']
                    ?? (
                        'Страница #'
                        . (int)$item['pageId']
                    );
            }
            ?>

            <article
                class="sb-forms2-submission"
                data-status="<?= sb_forms_h($item['status']) ?>"
            >
                <header class="sb-forms2-submission__head">
                    <div class="sb-forms2-submission__identity">
                        <div class="sb-forms2-submission__id">
                            #<?= (int)$item['id'] ?>
                        </div>

                        <div>
                            <strong>
                                <?= sb_forms_h($formTitle) ?>
                            </strong>

                            <small>
                                <?= sb_forms_h($pageTitle) ?>
                                · форма #<?= (int)$item['blockId'] ?>
                                · <?= sb_forms_h(sb_forms_date($item['createdAt'])) ?>
                            </small>
                        </div>
                    </div>

                    <span
                        class="sb-forms2-status"
                        data-status="<?= sb_forms_h($item['status']) ?>"
                    >
                        <?= sb_forms_h(sb_forms_status_label($item['status'])) ?>
                    </span>
                </header>

                <dl class="sb-forms2-payload">
                    <?php foreach ($item['payload'] as $field): ?>
                        <div class="sb-forms2-payload__item">
                            <dt>
                                <?= sb_forms_h($field['label'] ?? '') ?>
                            </dt>
                            <dd>
                                <?= sb_forms_h($field['value'] ?? '') ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>

                <footer class="sb-forms2-submission__footer">
                    <form
                        method="post"
                        class="sb-forms2-status-form"
                    >
                        <?= bitrix_sessid_post() ?>

                        <input
                            type="hidden"
                            name="siteId"
                            value="<?= $siteId ?>"
                        >
                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int)$item['id'] ?>"
                        >
                        <input
                            type="hidden"
                            name="formAction"
                            value="status"
                        >
                        <input
                            type="hidden"
                            name="returnStatus"
                            value="<?= sb_forms_h($status) ?>"
                        >
                        <input
                            type="hidden"
                            name="returnBlockId"
                            value="<?= $blockId ?>"
                        >
                        <input
                            type="hidden"
                            name="returnQ"
                            value="<?= sb_forms_h($search) ?>"
                        >

                        <select
                            class="sb-select"
                            name="status"
                        >
                            <?php foreach (
                                [
                                    'new',
                                    'in_progress',
                                    'done',
                                    'spam',
                                ]
                                as $value
                            ): ?>
                                <option
                                    value="<?= sb_forms_h($value) ?>"
                                    <?= $item['status'] === $value ? 'selected' : '' ?>
                                >
                                    <?= sb_forms_h(sb_forms_status_label($value)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button
                            class="sb-btn sb-btn-primary"
                            type="submit"
                        >
                            Сохранить статус
                        </button>
                    </form>

                    <div class="sb-forms2-row-actions">
                        <a
                            class="sb-btn sb-btn-light"
                            href="<?= sb_forms_h(
                                $basePath
                                . '/forms.php?'
                                . http_build_query([
                                    'siteId' => $siteId,
                                    'blockId' => (int)$item['blockId'],
                                ])
                            ) ?>"
                        >
                            Все заявки формы
                        </a>

                        <form method="post">
                            <?= bitrix_sessid_post() ?>

                            <input
                                type="hidden"
                                name="siteId"
                                value="<?= $siteId ?>"
                            >
                            <input
                                type="hidden"
                                name="id"
                                value="<?= (int)$item['id'] ?>"
                            >
                            <input
                                type="hidden"
                                name="formAction"
                                value="delete"
                            >
                            <input
                                type="hidden"
                                name="returnStatus"
                                value="<?= sb_forms_h($status) ?>"
                            >
                            <input
                                type="hidden"
                                name="returnBlockId"
                                value="<?= $blockId ?>"
                            >
                            <input
                                type="hidden"
                                name="returnQ"
                                value="<?= sb_forms_h($search) ?>"
                            >

                            <button
                                class="sb-btn sb-btn-danger"
                                type="submit"
                                onclick="return confirm('Удалить заявку #<?= (int)$item['id'] ?>?')"
                            >
                                Удалить
                            </button>
                        </form>
                    </div>
                </footer>
            </article>
        <?php endforeach; ?>
    </section>

    <?php if ($currentMatchingCount > count($items)): ?>
        <div class="sb-forms2-limit-note">
            Показаны последние <?= count($items) ?>
            из <?= $currentMatchingCount ?> заявок.
            Экспорт содержит до 5000 записей.
        </div>
    <?php endif; ?>
</div>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage_db.php';
require_once __DIR__ . '/helpers.php';

final class SiteBuilderFormValidationException extends RuntimeException
{
    private array $fieldErrors;

    public function __construct(string $message, array $fieldErrors = [])
    {
        parent::__construct($message);
        $this->fieldErrors = $fieldErrors;
    }

    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}

final class FormSubmissionService
{
    private const FIELD_TYPES = [
        'text',
        'email',
        'phone',
        'number',
        'textarea',
        'select',
        'radio',
        'checkbox',
    ];

    private const STATUSES = [
        'new',
        'in_progress',
        'done',
        'spam',
    ];

    public static function normalizeFields($fields): array
    {
        $fields = is_array($fields) ? $fields : [];
        $result = [];
        $used = [];

        foreach (array_slice($fields, 0, 30) as $index => $field) {
            $field = is_array($field) ? $field : [];

            $key = strtolower(
                trim(
                    (string)(
                        $field['key']
                        ?? 'field_' . ($index + 1)
                    )
                )
            );

            $key = preg_replace(
                '/[^a-z0-9_]+/',
                '_',
                $key
            ) ?? '';

            $key = trim($key, '_');

            if ($key === '') {
                $key = 'field_' . ($index + 1);
            }

            $key = substr($key, 0, 50);
            $baseKey = $key;
            $suffix = 2;

            while (isset($used[$key])) {
                $key =
                    substr($baseKey, 0, 45)
                    . '_'
                    . $suffix++;
            }

            $used[$key] = true;

            $type = strtolower(
                trim(
                    (string)(
                        $field['type']
                        ?? 'text'
                    )
                )
            );

            if (
                !in_array(
                    $type,
                    self::FIELD_TYPES,
                    true
                )
            ) {
                $type = 'text';
            }

            $options = [];

            if (
                in_array(
                    $type,
                    ['select', 'radio'],
                    true
                )
            ) {
                foreach (
                    array_slice(
                        is_array(
                            $field['options']
                            ?? null
                        )
                            ? $field['options']
                            : [],
                        0,
                        50
                    )
                    as $option
                ) {
                    $option = mb_substr(
                        trim(
                            (string)$option
                        ),
                        0,
                        200
                    );

                    if ($option !== '') {
                        $options[] = $option;
                    }
                }

                $options =
                    array_values(
                        array_unique(
                            $options
                        )
                    );
            }

            $width =
                (string)(
                    $field['width']
                    ?? 'full'
                );

            if (
                !in_array(
                    $width,
                    ['full', 'half'],
                    true
                )
            ) {
                $width = 'full';
            }

            $label = mb_substr(
                trim(
                    (string)(
                        $field['label']
                        ?? ''
                    )
                ),
                0,
                160
            );

            if ($label === '') {
                $label =
                    'Поле '
                    . ($index + 1);
            }

            $placeholder =
                in_array(
                    $type,
                    [
                        'text',
                        'email',
                        'phone',
                        'number',
                        'textarea',
                    ],
                    true
                )
                    ? mb_substr(
                        trim(
                            (string)(
                                $field[
                                    'placeholder'
                                ]
                                ?? ''
                            )
                        ),
                        0,
                        255
                    )
                    : '';

            $result[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' =>
                    !empty(
                        $field['required']
                    ),
                'placeholder' =>
                    $placeholder,
                'options' => $options,
                'width' => $width,
            ];
        }

        return $result;
    }

    public static function submit(
        int $siteId,
        int $pageId,
        int $blockId,
        array $input,
        array $meta = []
    ): array {
        if (
            $siteId <= 0
            || $pageId <= 0
            || $blockId <= 0
        ) {
            throw new SiteBuilderFormValidationException(
                'FORM_CONTEXT_INVALID'
            );
        }

        if (
            trim(
                (string)(
                    $input['_company']
                    ?? ''
                )
            ) !== ''
        ) {
            throw new SiteBuilderFormValidationException(
                'FORM_SPAM_REJECTED'
            );
        }

        $page = sb_find_page($pageId);
        $block = sb_find_block($blockId);

        if (
            !$page
            || !$block
            || (int)(
                $page['siteId']
                ?? 0
            ) !== $siteId
            || (int)(
                $block['pageId']
                ?? 0
            ) !== $pageId
        ) {
            throw new SiteBuilderFormValidationException(
                'FORM_NOT_FOUND'
            );
        }

        if (
            (string)(
                $page['status']
                ?? ''
            ) !== 'published'
            || (string)(
                $block['type']
                ?? ''
            ) !== 'form'
        ) {
            throw new SiteBuilderFormValidationException(
                'FORM_NOT_AVAILABLE'
            );
        }

        $content =
            is_array(
                $block['content']
                ?? null
            )
                ? $block['content']
                : [];

        $fields =
            self::normalizeFields(
                $content['fields']
                ?? []
            );

        if (!$fields) {
            throw new SiteBuilderFormValidationException(
                'FORM_FIELDS_EMPTY'
            );
        }

        $payload = [];
        $errors = [];

        foreach ($fields as $field) {
            $key = $field['key'];

            $raw =
                $input[$key]
                ?? (
                    $field['type']
                    === 'checkbox'
                        ? ''
                        : null
                );

            $value =
                is_array($raw)
                    ? ''
                    : trim(
                        (string)$raw
                    );

            if (
                $field['type']
                === 'checkbox'
            ) {
                $value =
                    in_array(
                        strtolower(
                            $value
                        ),
                        [
                            '1',
                            'yes',
                            'on',
                            'true',
                        ],
                        true
                    )
                        ? 'Да'
                        : 'Нет';

                if (
                    $field['required']
                    && $value !== 'Да'
                ) {
                    $errors[$key] =
                        'Подтвердите поле';
                }
            } else {
                if (
                    $field['required']
                    && $value === ''
                ) {
                    $errors[$key] =
                        'Заполните поле';
                }

                if (
                    $field['type']
                    === 'email'
                    && $value !== ''
                    && !filter_var(
                        $value,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $errors[$key] =
                        'Введите корректный email';
                }

                if (
                    $field['type']
                    === 'phone'
                    && $value !== ''
                    && !preg_match(
                        '/^[0-9+()\-\s]{5,40}$/u',
                        $value
                    )
                ) {
                    $errors[$key] =
                        'Введите корректный телефон';
                }

                if (
                    $field['type']
                    === 'number'
                    && $value !== ''
                ) {
                    $normalizedNumber =
                        str_replace(
                            ',',
                            '.',
                            $value
                        );

                    if (
                        !preg_match(
                            '/^-?(?:\d+|\d*\.\d+)$/',
                            $normalizedNumber
                        )
                    ) {
                        $errors[$key] =
                            'Введите число';
                    } else {
                        $value =
                            $normalizedNumber;
                    }
                }

                if (
                    in_array(
                        $field['type'],
                        ['select', 'radio'],
                        true
                    )
                    && $value !== ''
                    && !in_array(
                        $value,
                        $field['options'],
                        true
                    )
                ) {
                    $errors[$key] =
                        'Выберите один из предложенных вариантов';
                }

                $maxLength =
                    $field['type']
                    === 'textarea'
                        ? 5000
                        : 1000;

                if (
                    mb_strlen($value)
                    > $maxLength
                ) {
                    $errors[$key] =
                        'Слишком длинное значение';
                }
            }

            $payload[$key] = [
                'label' =>
                    $field['label'],
                'type' =>
                    $field['type'],
                'value' =>
                    mb_substr(
                        $value,
                        0,
                        $field['type']
                            === 'textarea'
                                ? 5000
                                : 1000
                    ),
            ];
        }

        if ($errors) {
            throw new SiteBuilderFormValidationException(
                'FORM_VALIDATION_FAILED',
                $errors
            );
        }

        $ipHash =
            (string)(
                $meta['ipHash']
                ?? ''
            );

        if ($ipHash !== '') {
            /*
             * Сериализуем только отправки одной формы с одного IP.
             * Endpoint открывает транзакцию, поэтому xact-lock действует
             * до завершения проверки лимита и INSERT.
             */
            sb_db_fetch_one(
                "SELECT pg_advisory_xact_lock(761250, hashtext(:lock_key)) AS locked",
                [
                    ':lock_key' =>
                        $siteId
                        . ':'
                        . $blockId
                        . ':'
                        . $ipHash,
                ]
            );

            $recent =
                sb_db_fetch_one(
                    "SELECT COUNT(*) AS cnt FROM sitebuilder.form_submission
                     WHERE site_id=:site_id AND block_id=:block_id
                       AND meta_json->>'ipHash'=:ip_hash
                       AND created_at > NOW() - INTERVAL '5 minutes'",
                    [
                        ':site_id' =>
                            $siteId,
                        ':block_id' =>
                            $blockId,
                        ':ip_hash' =>
                            $ipHash,
                    ]
                );

            if (
                (int)(
                    $recent['cnt']
                    ?? 0
                ) >= 5
            ) {
                throw new SiteBuilderFormValidationException(
                    'FORM_RATE_LIMIT'
                );
            }
        }

        $safeMeta = [
            'ipHash' => $ipHash,
            'userAgent' =>
                mb_substr(
                    trim(
                        (string)(
                            $meta[
                                'userAgent'
                            ]
                            ?? ''
                        )
                    ),
                    0,
                    500
                ),
            'userId' =>
                max(
                    0,
                    (int)(
                        $meta['userId']
                        ?? 0
                    )
                ),
            'pageTitle' =>
                mb_substr(
                    (string)(
                        $page['title']
                        ?? ''
                    ),
                    0,
                    255
                ),
            'formTitle' =>
                mb_substr(
                    (string)(
                        $content['title']
                        ?? 'Форма'
                    ),
                    0,
                    255
                ),
        ];

        $row =
            sb_db_fetch_one(
                "INSERT INTO sitebuilder.form_submission (
                    site_id,page_id,block_id,status,payload_json,meta_json,created_at,updated_at
                 ) VALUES (
                    :site_id,:page_id,:block_id,'new',CAST(:payload AS jsonb),CAST(:meta AS jsonb),NOW(),NOW()
                 ) RETURNING *",
                [
                    ':site_id' =>
                        $siteId,
                    ':page_id' =>
                        $pageId,
                    ':block_id' =>
                        $blockId,
                    ':payload' =>
                        json_encode(
                            $payload,
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                            | JSON_THROW_ON_ERROR
                        ),
                    ':meta' =>
                        json_encode(
                            $safeMeta,
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                            | JSON_THROW_ON_ERROR
                        ),
                ]
            );

        if (!$row) {
            throw new RuntimeException(
                'FORM_SUBMISSION_SAVE_FAILED'
            );
        }

        return self::mapRow($row);
    }

    public static function list(
        int $siteId,
        array $filters = [],
        int $limit = 100
    ): array {
        return self::listInternal(
            $siteId,
            $filters,
            max(
                1,
                min(
                    500,
                    $limit
                )
            )
        );
    }

    public static function listForExport(
        int $siteId,
        array $filters = [],
        int $limit = 5000
    ): array {
        return self::listInternal(
            $siteId,
            $filters,
            max(
                1,
                min(
                    5000,
                    $limit
                )
            )
        );
    }

    public static function summary(
        int $siteId,
        array $filters = []
    ): array {
        [$where, $params] =
            self::buildWhere(
                $siteId,
                $filters,
                false
            );

        $row =
            sb_db_fetch_one(
                "SELECT
                    COUNT(*) AS total_count,
                    COUNT(*) FILTER (
                        WHERE status='new'
                    ) AS new_count,
                    COUNT(*) FILTER (
                        WHERE status='in_progress'
                    ) AS in_progress_count,
                    COUNT(*) FILTER (
                        WHERE status='done'
                    ) AS done_count,
                    COUNT(*) FILTER (
                        WHERE status='spam'
                    ) AS spam_count
                 FROM sitebuilder.form_submission
                 WHERE "
                . implode(
                    ' AND ',
                    $where
                ),
                $params
            ) ?: [];

        return [
            'total' =>
                (int)(
                    $row['total_count']
                    ?? 0
                ),
            'new' =>
                (int)(
                    $row['new_count']
                    ?? 0
                ),
            'in_progress' =>
                (int)(
                    $row[
                        'in_progress_count'
                    ]
                    ?? 0
                ),
            'done' =>
                (int)(
                    $row['done_count']
                    ?? 0
                ),
            'spam' =>
                (int)(
                    $row['spam_count']
                    ?? 0
                ),
        ];
    }

    public static function formsForSite(
        int $siteId
    ): array {
        if ($siteId <= 0) {
            return [];
        }

        $rows =
            sb_db_fetch_all(
                "SELECT
                    b.id AS block_id,
                    b.page_id,
                    p.title AS page_title,
                    p.status AS page_status,
                    b.content_json
                 FROM sitebuilder.block b
                 INNER JOIN sitebuilder.page p
                    ON p.id=b.page_id
                 WHERE
                    p.site_id=:site_id
                    AND b.type='form'
                 ORDER BY
                    LOWER(p.title) ASC,
                    b.id ASC",
                [
                    ':site_id' =>
                        $siteId,
                ]
            );

        $result = [];

        foreach ($rows as $row) {
            $content =
                sb_json_decode_assoc(
                    $row['content_json']
                    ?? '{}'
                );

            $blockId =
                (int)(
                    $row['block_id']
                    ?? 0
                );

            if ($blockId <= 0) {
                continue;
            }

            $title =
                trim(
                    (string)(
                        $content['title']
                        ?? ''
                    )
                );

            if ($title === '') {
                $title =
                    'Форма #'
                    . $blockId;
            }

            $pageTitle =
                trim(
                    (string)(
                        $row['page_title']
                        ?? ''
                    )
                );

            $result[] = [
                'id' => $blockId,
                'pageId' =>
                    (int)(
                        $row['page_id']
                        ?? 0
                    ),
                'title' => $title,
                'pageTitle' =>
                    $pageTitle !== ''
                        ? $pageTitle
                        : 'Страница',
                'pageStatus' =>
                    (string)(
                        $row['page_status']
                        ?? 'draft'
                    ),
                'label' =>
                    $title
                    . ' · '
                    . (
                        $pageTitle !== ''
                            ? $pageTitle
                            : 'Страница'
                    ),
            ];
        }

        return $result;
    }

    private static function listInternal(
        int $siteId,
        array $filters,
        int $limit
    ): array {
        [$where, $params] =
            self::buildWhere(
                $siteId,
                $filters,
                true
            );

        $rows =
            sb_db_fetch_all(
                'SELECT * FROM sitebuilder.form_submission WHERE '
                . implode(
                    ' AND ',
                    $where
                )
                . ' ORDER BY created_at DESC,id DESC LIMIT '
                . $limit,
                $params
            );

        return array_map(
            [self::class, 'mapRow'],
            $rows
        );
    }

    private static function buildWhere(
        int $siteId,
        array $filters,
        bool $includeStatus
    ): array {
        if ($siteId <= 0) {
            throw new InvalidArgumentException(
                'SITE_ID_REQUIRED'
            );
        }

        $where = [
            'site_id=:site_id',
        ];

        $params = [
            ':site_id' => $siteId,
        ];

        if ($includeStatus) {
            $status =
                trim(
                    (string)(
                        $filters['status']
                        ?? ''
                    )
                );

            if (
                $status !== ''
                && in_array(
                    $status,
                    self::STATUSES,
                    true
                )
            ) {
                $where[] =
                    'status=:status';
                $params[':status'] =
                    $status;
            }
        }

        $blockId =
            (int)(
                $filters['blockId']
                ?? 0
            );

        if ($blockId > 0) {
            $where[] =
                'block_id=:block_id';
            $params[':block_id'] =
                $blockId;
        }

        $search =
            mb_substr(
                trim(
                    (string)(
                        $filters['search']
                        ?? ''
                    )
                ),
                0,
                200
            );

        if ($search !== '') {
            /*
             * ESCAPE '!' makes the user query literal:
             * % and _ are not treated as SQL wildcards.
             */
            $needle =
                str_replace(
                    ['!', '%', '_'],
                    ['!!', '!%', '!_'],
                    $search
                );

            $where[] =
                "(
                    CAST(id AS TEXT)
                        ILIKE :search ESCAPE '!'
                    OR CAST(page_id AS TEXT)
                        ILIKE :search ESCAPE '!'
                    OR CAST(block_id AS TEXT)
                        ILIKE :search ESCAPE '!'
                    OR payload_json::text
                        ILIKE :search ESCAPE '!'
                    OR meta_json::text
                        ILIKE :search ESCAPE '!'
                )";

            $params[':search'] =
                '%'
                . $needle
                . '%';
        }

        return [
            $where,
            $params,
        ];
    }

    public static function updateStatus(
        int $siteId,
        int $id,
        string $status,
        int $userId
    ): ?array {
        if (
            !in_array(
                $status,
                self::STATUSES,
                true
            )
        ) {
            throw new RuntimeException(
                'FORM_STATUS_INVALID'
            );
        }

        $row =
            sb_db_fetch_one(
                "UPDATE sitebuilder.form_submission SET status=:status_value,handled_by=:user_id,
                 handled_at=CASE WHEN :status_terminal IN ('done','spam') THEN NOW() ELSE handled_at END,
                 updated_at=NOW() WHERE id=:id AND site_id=:site_id RETURNING *",
                [
                    /*
                     * PostgreSQL must infer these placeholders independently.
                     * Reusing one placeholder both for VARCHAR assignment and
                     * text comparison causes SQLSTATE 42P08.
                     */
                    ':status_value' =>
                        $status,
                    ':status_terminal' =>
                        $status,
                    ':user_id' =>
                        $userId
                            ?: null,
                    ':id' =>
                        $id,
                    ':site_id' =>
                        $siteId,
                ]
            );

        return $row
            ? self::mapRow($row)
            : null;
    }

    public static function delete(
        int $siteId,
        int $id
    ): bool {
        $stmt =
            sb_db()->prepare(
                'DELETE FROM sitebuilder.form_submission WHERE id=:id AND site_id=:site_id'
            );

        $stmt->execute([
            ':id' => $id,
            ':site_id' => $siteId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function mapRow(
        array $row
    ): array {
        return [
            'id' =>
                (int)(
                    $row['id']
                    ?? 0
                ),
            'siteId' =>
                (int)(
                    $row['site_id']
                    ?? 0
                ),
            'pageId' =>
                (int)(
                    $row['page_id']
                    ?? 0
                ),
            'blockId' =>
                (int)(
                    $row['block_id']
                    ?? 0
                ),
            'status' =>
                (string)(
                    $row['status']
                    ?? 'new'
                ),
            'payload' =>
                sb_json_decode_assoc(
                    $row['payload_json']
                    ?? '{}'
                ),
            'meta' =>
                sb_json_decode_assoc(
                    $row['meta_json']
                    ?? '{}'
                ),
            'handledBy' =>
                (int)(
                    $row['handled_by']
                    ?? 0
                ),
            'handledAt' =>
                (string)(
                    $row['handled_at']
                    ?? ''
                ),
            'createdAt' =>
                (string)(
                    $row['created_at']
                    ?? ''
                ),
            'updatedAt' =>
                (string)(
                    $row['updated_at']
                    ?? ''
                ),
        ];
    }
}

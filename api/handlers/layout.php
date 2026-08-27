<?php

global $USER;

if (!function_exists('sb_layout_handler_get_or_create')) {
    function sb_layout_handler_get_or_create(
        int $siteId,
        int $userId
    ): array {
        $layout =
            RevisionService::getLayout(
                $siteId,
                false
            );

        if ($layout) {
            return sb_normalize_layout_record(
                $layout
            );
        }

        $default =
            sb_layout_default_record(
                $siteId
            );

        $stmt =
            sb_db()->prepare(
                "
                    INSERT INTO sitebuilder.layout (
                        site_id,
                        settings_json,
                        zones_json,
                        created_by,
                        created_at,
                        updated_by,
                        updated_at,
                        version
                    )
                    VALUES (
                        :site_id,
                        CAST(:settings AS jsonb),
                        CAST(:zones AS jsonb),
                        :user_id,
                        NOW(),
                        :user_id,
                        NOW(),
                        1
                    )
                    ON CONFLICT (site_id) DO NOTHING
                    RETURNING
                        site_id,
                        settings_json,
                        zones_json,
                        created_by,
                        created_at,
                        updated_by,
                        updated_at,
                        version
                "
            );

        $stmt->execute([
            ':site_id' =>
                $siteId,
            ':settings' =>
                json_encode(
                    $default['settings'],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                ),
            ':zones' =>
                json_encode(
                    $default['zones'],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                ),
            ':user_id' =>
                $userId > 0
                    ? $userId
                    : null,
        ]);

        $row =
            $stmt->fetch();

        $layout =
            $row
                ? sb_map_layout_row(
                    $row
                )
                : RevisionService::getLayout(
                    $siteId,
                    false
                );

        if (!$layout) {
            throw new RuntimeException(
                'LAYOUT_CREATE_FAILED'
            );
        }

        if ($row) {
            RevisionService::recordLayout(
                $layout,
                'create',
                $userId
            );
        }

        return sb_normalize_layout_record(
            $layout
        );
    }
}

if (!function_exists('sb_layout_handler_require')) {
    function sb_layout_handler_require(
        int $siteId
    ): array {
        if ($siteId <= 0) {
            sb_json_error(
                'SITE_ID_REQUIRED',
                422
            );
        }

        sb_require_content_manager(
            $siteId
        );

        return sb_layout_handler_get_or_create(
            $siteId,
            (int)(
                $GLOBALS['USER']->GetID()
            )
        );
    }
}

if (!function_exists('sb_layout_handler_reindex_zone')) {
    /**
     * Makes the current array order authoritative and keeps sort values
     * deterministic after insert/relocate/duplicate.
     */
    function sb_layout_handler_reindex_zone(
        array &$layout,
        string $zone
    ): void {
        if (!isset($layout['zones'])) {
            $layout['zones'] = [];
        }

        $blocks =
            array_values(
                is_array(
                    $layout['zones'][$zone]
                    ?? null
                )
                    ? $layout['zones'][$zone]
                    : []
            );

        foreach ($blocks as $index => &$block) {
            $block['sort'] =
                ($index + 1) * 10;
        }
        unset($block);

        $layout['zones'][$zone] =
            $blocks;
    }
}

if (!function_exists('sb_layout_handler_locate_block')) {
    function sb_layout_handler_locate_block(
        array $layout,
        int $blockId
    ): ?array {
        foreach (
            ['header', 'footer', 'left', 'right']
            as $zone
        ) {
            $blocks =
                array_values(
                    is_array(
                        $layout['zones'][$zone]
                        ?? null
                    )
                        ? $layout['zones'][$zone]
                        : []
                );

            foreach ($blocks as $index => $block) {
                if (
                    (int)(
                        $block['id']
                        ?? 0
                    ) === $blockId
                ) {
                    return [
                        'zone' => $zone,
                        'index' => $index,
                        'block' => $block,
                    ];
                }
            }
        }

        return null;
    }
}

if (!function_exists('sb_layout_handler_insert_block')) {
    function sb_layout_handler_insert_block(
        array &$layout,
        string $zone,
        array $block,
        int $targetIndex
    ): array {
        if (!sb_layout_valid_zone($zone)) {
            throw new InvalidArgumentException(
                'BAD_ZONE'
            );
        }

        $blocks =
            array_values(
                is_array(
                    $layout['zones'][$zone]
                    ?? null
                )
                    ? $layout['zones'][$zone]
                    : []
            );

        $targetIndex =
            max(
                0,
                min(
                    count($blocks),
                    $targetIndex
                )
            );

        array_splice(
            $blocks,
            $targetIndex,
            0,
            [$block]
        );

        $layout['zones'][$zone] =
            $blocks;

        sb_layout_handler_reindex_zone(
            $layout,
            $zone
        );

        return $layout['zones'][$zone][
            $targetIndex
        ];
    }
}

if (!function_exists('sb_layout_handler_normalize_settings')) {
    function sb_layout_handler_normalize_settings(
        array $current,
        array $input
    ): array {
        $result =
            $current;

        foreach (
            [
                'showHeader',
                'showFooter',
                'showLeft',
                'showRight',
            ]
            as $key
        ) {
            if (
                array_key_exists(
                    $key,
                    $input
                )
            ) {
                $result[$key] =
                    (bool)$input[$key];
            }
        }

        if (
            array_key_exists(
                'leftWidth',
                $input
            )
        ) {
            $result['leftWidth'] =
                max(
                    120,
                    min(
                        800,
                        (int)$input[
                            'leftWidth'
                        ]
                    )
                );
        }

        if (
            array_key_exists(
                'rightWidth',
                $input
            )
        ) {
            $result['rightWidth'] =
                max(
                    120,
                    min(
                        800,
                        (int)$input[
                            'rightWidth'
                        ]
                    )
                );
        }

        if (
            array_key_exists(
                'leftMode',
                $input
            )
        ) {
            $mode =
                trim(
                    (string)$input[
                        'leftMode'
                    ]
                );

            $result['leftMode'] =
                in_array(
                    $mode,
                    [
                        'blocks',
                        'menu',
                    ],
                    true
                )
                    ? $mode
                    : 'blocks';
        }

        return $result;
    }
}

if (!function_exists('sb_layout_handler_existing_blocks')) {
    function sb_layout_handler_existing_blocks(
        array $layout
    ): array {
        $result = [];

        foreach (
            [
                'header',
                'footer',
                'left',
                'right',
            ]
            as $zone
        ) {
            foreach (
                is_array(
                    $layout['zones'][$zone]
                    ?? null
                )
                    ? $layout['zones'][$zone]
                    : []
                as $block
            ) {
                $id =
                    (int)(
                        $block['id']
                        ?? 0
                    );

                if ($id > 0) {
                    $result[$id] =
                        $block;
                }
            }
        }

        return $result;
    }
}

if (!function_exists('sb_layout_handler_next_draft_block_id')) {
    function sb_layout_handler_next_draft_block_id(
        array $existing
    ): int {
        $max = 0;

        foreach (
            array_keys($existing)
            as $id
        ) {
            $max =
                max(
                    $max,
                    (int)$id
                );
        }

        return $max + 1;
    }
}

if ($action === 'layout.get') {
    $siteId =
        (int)(
            $_POST['siteId']
            ?? 0
        );

    if ($siteId <= 0) {
        sb_json_error(
            'SITE_ID_REQUIRED',
            422
        );
    }

    sb_require_viewer(
        $siteId
    );

    $layout =
        sb_layout_handler_get_or_create(
            $siteId,
            (int)$USER->GetID()
        );

    sb_json_ok([
        'layout' => $layout,
        'handler' => 'layout',
    ]);
}

if ($action === 'layout.updateSettings') {
    $siteId =
        (int)(
            $_POST['siteId']
            ?? 0
        );

    $layout =
        sb_layout_handler_require(
            $siteId
        );

    $raw =
        $_POST['settings']
        ?? null;

    if ($raw === null) {
        sb_json_error(
            'SETTINGS_REQUIRED',
            422
        );
    }

    $settings =
        is_array($raw)
            ? $raw
            : json_decode(
                (string)$raw,
                true
            );

    if (!is_array($settings)) {
        sb_json_error(
            'BAD_SETTINGS_JSON',
            422
        );
    }

    $allowed = [
        'showHeader',
        'showFooter',
        'showLeft',
        'showRight',
        'leftWidth',
        'rightWidth',
        'leftMode',
    ];

    $filtered = [];

    foreach ($allowed as $key) {
        if (
            array_key_exists(
                $key,
                $settings
            )
        ) {
            $filtered[$key] =
                $settings[$key];
        }
    }

    foreach (
        [
            'showHeader',
            'showFooter',
            'showLeft',
            'showRight',
        ]
        as $key
    ) {
        if (
            isset(
                $filtered[$key]
            )
        ) {
            $filtered[$key] =
                (bool)$filtered[$key];
        }
    }

    if (
        isset(
            $filtered['leftWidth']
        )
    ) {
        $filtered['leftWidth'] =
            max(
                120,
                min(
                    800,
                    (int)$filtered[
                        'leftWidth'
                    ]
                )
            );
    }

    if (
        isset(
            $filtered['rightWidth']
        )
    ) {
        $filtered['rightWidth'] =
            max(
                120,
                min(
                    800,
                    (int)$filtered[
                        'rightWidth'
                    ]
                )
            );
    }

    if (
        isset(
            $filtered['leftMode']
        )
    ) {
        $filtered['leftMode'] =
            in_array(
                (string)$filtered[
                    'leftMode'
                ],
                [
                    'blocks',
                    'menu',
                ],
                true
            )
                ? (string)$filtered[
                    'leftMode'
                ]
                : 'blocks';
    }

    $layout['settings'] =
        array_merge(
            $layout['settings'],
            $filtered
        );

    $saved =
        RevisionService::saveLayout(
            $layout,
            RevisionService::requireExpectedVersion(
                $_POST['expectedVersion']
                ?? null
            ),
            (int)$USER->GetID(),
            'settings_update'
        );

    sb_json_ok([
        'layout' =>
            sb_normalize_layout_record(
                $saved
            ),
        'handler' => 'layout',
    ]);
}

if ($action === 'layout.save') {
    $siteId =
        (int)(
            $_POST['siteId']
            ?? 0
        );

    $layout =
        sb_layout_handler_require(
            $siteId
        );

    $settingsRaw =
        $_POST['settings']
        ?? null;

    $zonesRaw =
        $_POST['zones']
        ?? null;

    if (
        $settingsRaw === null
        || $zonesRaw === null
    ) {
        sb_json_error(
            'LAYOUT_DRAFT_REQUIRED',
            422
        );
    }

    if (
        is_string($zonesRaw)
        && strlen($zonesRaw)
            > 2 * 1024 * 1024
    ) {
        sb_json_error(
            'LAYOUT_DRAFT_TOO_LARGE',
            413
        );
    }

    $settings =
        is_array($settingsRaw)
            ? $settingsRaw
            : json_decode(
                (string)$settingsRaw,
                true
            );

    $zones =
        is_array($zonesRaw)
            ? $zonesRaw
            : json_decode(
                (string)$zonesRaw,
                true
            );

    if (!is_array($settings)) {
        sb_json_error(
            'BAD_SETTINGS_JSON',
            422
        );
    }

    if (!is_array($zones)) {
        sb_json_error(
            'BAD_ZONES_JSON',
            422
        );
    }

    $layout['settings'] =
        sb_layout_handler_normalize_settings(
            is_array(
                $layout['settings']
                ?? null
            )
                ? $layout['settings']
                : [],
            $settings
        );

    $existing =
        sb_layout_handler_existing_blocks(
            $layout
        );

    $nextId =
        sb_layout_handler_next_draft_block_id(
            $existing
        );

    $usedExisting = [];
    $usedClientIds = [];
    $idMap = [];
    $normalizedZones = [];
    $totalBlocks = 0;
    $now = date('c');
    $userId =
        (int)$USER->GetID();

    foreach (
        [
            'header',
            'footer',
            'left',
            'right',
        ]
        as $zone
    ) {
        $items =
            is_array(
                $zones[$zone]
                ?? null
            )
                ? array_values(
                    $zones[$zone]
                )
                : [];

        if (
            count($items)
            > 200
        ) {
            sb_json_error(
                'LAYOUT_ZONE_BLOCK_LIMIT',
                422,
                [
                    'zone' => $zone,
                ]
            );
        }

        $normalizedZones[$zone] = [];

        foreach (
            $items
            as $index => $item
        ) {
            if (!is_array($item)) {
                sb_json_error(
                    'BAD_LAYOUT_BLOCK',
                    422,
                    [
                        'zone' => $zone,
                        'index' => $index,
                    ]
                );
            }

            $clientId =
                (int)(
                    $item['id']
                    ?? 0
                );

            if (
                $clientId !== 0
                && isset(
                    $usedClientIds[
                        (string)$clientId
                    ]
                )
            ) {
                sb_json_error(
                    'DUPLICATE_LAYOUT_BLOCK_ID',
                    422,
                    [
                        'id' => $clientId,
                    ]
                );
            }

            if ($clientId !== 0) {
                $usedClientIds[
                    (string)$clientId
                ] = true;
            }

            $type =
                preg_replace(
                    '/[^a-z0-9_-]/i',
                    '',
                    trim(
                        (string)(
                            $item['type']
                            ?? 'text'
                        )
                    )
                ) ?? '';

            $type =
                mb_substr(
                    $type !== ''
                        ? $type
                        : 'text',
                    0,
                    64
                );

            $content =
                is_array(
                    $item['content']
                    ?? null
                )
                    ? $item['content']
                    : [];

            $props =
                is_array(
                    $item['props']
                    ?? null
                )
                    ? $item['props']
                    : [];

            if ($clientId > 0) {
                if (
                    !isset(
                        $existing[$clientId]
                    )
                    || isset(
                        $usedExisting[
                            $clientId
                        ]
                    )
                ) {
                    sb_json_error(
                        'LAYOUT_BLOCK_NOT_FOUND',
                        404,
                        [
                            'id' => $clientId,
                        ]
                    );
                }

                $original =
                    $existing[$clientId];

                $realId =
                    $clientId;

                $usedExisting[
                    $clientId
                ] = true;

                $createdBy =
                    (int)(
                        $original[
                            'createdBy'
                        ]
                        ?? 0
                    );

                $createdAt =
                    (string)(
                        $original[
                            'createdAt'
                        ]
                        ?? $now
                    );
            } else {
                $realId =
                    $nextId++;

                if ($clientId < 0) {
                    $idMap[
                        (string)$clientId
                    ] =
                        $realId;
                }

                $createdBy =
                    $userId;

                $createdAt =
                    $now;
            }

            $normalizedZones[$zone][] = [
                'id' =>
                    $realId,
                'type' =>
                    $type,
                'sort' =>
                    ($index + 1)
                    * 10,
                'content' =>
                    $content,
                'props' =>
                    $props,
                'createdBy' =>
                    $createdBy,
                'createdAt' =>
                    $createdAt,
                'updatedBy' =>
                    $userId,
                'updatedAt' =>
                    $now,
            ];

            $totalBlocks++;

            if (
                $totalBlocks
                > 500
            ) {
                sb_json_error(
                    'LAYOUT_BLOCK_LIMIT',
                    422
                );
            }
        }
    }

    $layout['zones'] =
        $normalizedZones;

    $saved =
        RevisionService::saveLayout(
            $layout,
            RevisionService::requireExpectedVersion(
                $_POST['expectedVersion']
                ?? null
            ),
            $userId,
            'layout_save'
        );

    sb_json_ok([
        'layout' =>
            sb_normalize_layout_record(
                $saved
            ),
        'idMap' =>
            $idMap,
        'handler' =>
            'layout',
    ]);
}

if ($action === 'layout.block.list') {
    $siteId =
        (int)(
            $_POST['siteId']
            ?? 0
        );

    $zone =
        trim(
            (string)(
                $_POST['zone']
                ?? ''
            )
        );

    if ($siteId <= 0) {
        sb_json_error(
            'SITE_ID_REQUIRED',
            422
        );
    }

    if (
        !sb_layout_valid_zone(
            $zone
        )
    ) {
        sb_json_error(
            'BAD_ZONE',
            422
        );
    }

    sb_require_viewer(
        $siteId
    );

    $layout =
        sb_layout_handler_get_or_create(
            $siteId,
            (int)$USER->GetID()
        );

    sb_json_ok([
        'blocks' =>
            array_values(
                $layout['zones'][$zone]
                ?? []
            ),
        'zone' => $zone,
        'layoutVersion' =>
            (int)$layout['version'],
    ]);
}

if ($action === 'layout.block.create') {
    $siteId =
        (int)(
            $_POST['siteId']
            ?? 0
        );

    $zone =
        trim(
            (string)(
                $_POST['zone']
                ?? ''
            )
        );

    $type =
        trim(
            (string)(
                $_POST['type']
                ?? 'text'
            )
        );

    if (
        !sb_layout_valid_zone(
            $zone
        )
    ) {
        sb_json_error(
            'BAD_ZONE',
            422
        );
    }

    if ($type === '') {
        sb_json_error(
            'TYPE_REQUIRED',
            422
        );
    }

    $layout =
        sb_layout_handler_require(
            $siteId
        );

    $blocks =
        array_values(
            is_array(
                $layout['zones'][$zone]
                ?? null
            )
                ? $layout['zones'][$zone]
                : []
        );

    $targetIndex =
        array_key_exists(
            'targetIndex',
            $_POST
        )
            ? max(
                0,
                min(
                    count($blocks),
                    (int)$_POST[
                        'targetIndex'
                    ]
                )
            )
            : count($blocks);

    $now =
        date('c');

    $block = [
        'id' =>
            sb_layout_next_block_id(
                $layout
            ),
        'type' => $type,
        'sort' => 0,
        'content' =>
            sb_default_block_content(
                $type
            ),
        'props' => [],
        'createdBy' =>
            (int)$USER->GetID(),
        'createdAt' => $now,
        'updatedBy' =>
            (int)$USER->GetID(),
        'updatedAt' => $now,
    ];

    $block =
        sb_layout_handler_insert_block(
            $layout,
            $zone,
            $block,
            $targetIndex
        );

    $saved =
        RevisionService::saveLayout(
            $layout,
            RevisionService::requireExpectedVersion(
                $_POST['expectedVersion']
                ?? null
            ),
            (int)$USER->GetID(),
            'block_create'
        );

    sb_json_ok([
        'block' =>
            sb_normalize_block_record(
                $block
            ),
        'zone' => $zone,
        'targetIndex' =>
            $targetIndex,
        'layout' =>
            sb_normalize_layout_record(
                $saved
            ),
    ]);
}

if ($action === 'layout.block.update') {
    $siteId =
        (int)(
            $_POST['siteId']
            ?? 0
        );

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    if ($id <= 0) {
        sb_json_error(
            'ID_REQUIRED',
            422
        );
    }

    $layout =
        sb_layout_handler_require(
            $siteId
        );

    $contentRaw =
        $_POST['content']
        ?? null;

    $propsRaw =
        $_POST['props']
        ?? null;

    $typeRaw =
        $_POST['type']
        ?? null;

    $newContent = null;
    $newProps = null;
    $newType = null;

    if ($contentRaw !== null) {
        $newContent =
            is_array($contentRaw)
                ? $contentRaw
                : json_decode(
                    (string)$contentRaw,
                    true
                );

        if (!is_array($newContent)) {
            sb_json_error(
                'BAD_CONTENT_JSON',
                422
            );
        }
    }

    if ($propsRaw !== null) {
        $newProps =
            is_array($propsRaw)
                ? $propsRaw
                : json_decode(
                    (string)$propsRaw,
                    true
                );

        if (!is_array($newProps)) {
            sb_json_error(
                'BAD_PROPS_JSON',
                422
            );
        }
    }

    if ($typeRaw !== null) {
        $newType =
            trim(
                (string)$typeRaw
            );

        if ($newType === '') {
            sb_json_error(
                'TYPE_REQUIRED',
                422
            );
        }
    }

    $found = false;
    $updatedBlock = null;

    foreach (
        ['header', 'footer', 'left', 'right']
        as $zone
    ) {
        foreach (
            $layout['zones'][$zone]
            as &$block
        ) {
            if (
                (int)(
                    $block['id']
                    ?? 0
                ) !== $id
            ) {
                continue;
            }

            if ($newType !== null) {
                $block['type'] =
                    $newType;
            }

            if ($newContent !== null) {
                $block['content'] =
                    $newContent;
            }

            if ($newProps !== null) {
                $block['props'] =
                    $newProps;
            }

            $block['updatedAt'] =
                date('c');

            $block['updatedBy'] =
                (int)$USER->GetID();

            $updatedBlock =
                $block;

            $found = true;

            break 2;
        }
        unset($block);
    }
    unset($block);

    if (!$found) {
        sb_json_error(
            'BLOCK_NOT_FOUND',
            404
        );
    }

    $saved =
        RevisionService::saveLayout(
            $layout,
            RevisionService::requireExpectedVersion(
                $_POST['expectedVersion']
                ?? null
            ),
            (int)$USER->GetID(),
            'block_update'
        );

    sb_json_ok([
        'block' =>
            sb_normalize_block_record(
                $updatedBlock
            ),
        'layout' =>
            sb_normalize_layout_record(
                $saved
            ),
    ]);
}

if ($action === 'layout.block.delete') {
    $siteId =
        (int)(
            $_POST['siteId']
            ?? 0
        );

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    if ($id <= 0) {
        sb_json_error(
            'ID_REQUIRED',
            422
        );
    }

    $layout =
        sb_layout_handler_require(
            $siteId
        );

    $found = false;

    foreach (
        ['header', 'footer', 'left', 'right']
        as $zone
    ) {
        $before =
            count(
                $layout['zones'][$zone]
            );

        $layout['zones'][$zone] =
            array_values(
                array_filter(
                    $layout['zones'][$zone],
                    static fn(array $block): bool =>
                        (int)(
                            $block['id']
                            ?? 0
                        ) !== $id
                )
            );

        if (
            count(
                $layout['zones'][$zone]
            ) !== $before
        ) {
            sb_layout_handler_reindex_zone(
                $layout,
                $zone
            );

            $found = true;
            break;
        }
    }

    if (!$found) {
        sb_json_error(
            'BLOCK_NOT_FOUND',
            404
        );
    }

    $saved =
        RevisionService::saveLayout(
            $layout,
            RevisionService::requireExpectedVersion(
                $_POST['expectedVersion']
                ?? null
            ),
            (int)$USER->GetID(),
            'block_delete'
        );

    sb_json_ok([
        'layout' =>
            sb_normalize_layout_record(
                $saved
            ),
    ]);
}

if ($action === 'layout.block.move') {
    $siteId =
        (int)(
            $_POST['siteId']
            ?? 0
        );

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    $direction =
        trim(
            (string)(
                $_POST['dir']
                ?? ''
            )
        );

    if ($id <= 0) {
        sb_json_error(
            'ID_REQUIRED',
            422
        );
    }

    if (
        !in_array(
            $direction,
            ['up', 'down'],
            true
        )
    ) {
        sb_json_error(
            'DIR_REQUIRED',
            422
        );
    }

    $layout =
        sb_layout_handler_require(
            $siteId
        );

    $located =
        sb_layout_handler_locate_block(
            $layout,
            $id
        );

    if (!$located) {
        sb_json_error(
            'BLOCK_NOT_FOUND',
            404
        );
    }

    $sourceIndex =
        (int)$located['index'];

    $targetIndex =
        $direction === 'up'
            ? $sourceIndex - 1
            : $sourceIndex + 1;

    $expected =
        RevisionService::requireExpectedVersion(
            $_POST['expectedVersion']
            ?? null
        );

    $count =
        count(
            $layout['zones'][
                $located['zone']
            ]
        );

    if (
        $targetIndex < 0
        || $targetIndex >= $count
    ) {
        RevisionService::assertExpected(
            $layout,
            $expected,
            RevisionService::ENTITY_LAYOUT
        );

        sb_json_ok([
            'layout' =>
                sb_normalize_layout_record(
                    $layout
                ),
            'changed' => false,
        ]);
    }

    $zone =
        (string)$located['zone'];

    $blocks =
        array_values(
            $layout['zones'][$zone]
        );

    $block =
        $blocks[$sourceIndex];

    array_splice(
        $blocks,
        $sourceIndex,
        1
    );

    $block['updatedAt'] =
        date('c');

    $block['updatedBy'] =
        (int)$USER->GetID();

    $layout['zones'][$zone] =
        $blocks;

    $block =
        sb_layout_handler_insert_block(
            $layout,
            $zone,
            $block,
            $targetIndex
        );

    $saved =
        RevisionService::saveLayout(
            $layout,
            $expected,
            (int)$USER->GetID(),
            'block_move'
        );

    sb_json_ok([
        'block' =>
            sb_normalize_block_record(
                $block
            ),
        'layout' =>
            sb_normalize_layout_record(
                $saved
            ),
        'changed' => true,
    ]);
}

if ($action === 'layout.block.relocate') {
    $siteId =
        (int)(
            $_POST['siteId']
            ?? 0
        );

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    $targetZone =
        trim(
            (string)(
                $_POST['targetZone']
                ?? ''
            )
        );

    if ($id <= 0) {
        sb_json_error(
            'ID_REQUIRED',
            422
        );
    }

    if (
        !sb_layout_valid_zone(
            $targetZone
        )
    ) {
        sb_json_error(
            'BAD_ZONE',
            422
        );
    }

    $layout =
        sb_layout_handler_require(
            $siteId
        );

    $located =
        sb_layout_handler_locate_block(
            $layout,
            $id
        );

    if (!$located) {
        sb_json_error(
            'BLOCK_NOT_FOUND',
            404
        );
    }

    $sourceZone =
        (string)$located['zone'];

    $sourceIndex =
        (int)$located['index'];

    $targetCount =
        count(
            $layout['zones'][$targetZone]
            ?? []
        );

    if ($targetZone === $sourceZone) {
        $targetCount =
            max(
                0,
                $targetCount - 1
            );
    }

    $targetIndex =
        array_key_exists(
            'targetIndex',
            $_POST
        )
            ? max(
                0,
                min(
                    $targetCount,
                    (int)$_POST[
                        'targetIndex'
                    ]
                )
            )
            : $targetCount;

    $expected =
        RevisionService::requireExpectedVersion(
            $_POST['expectedVersion']
            ?? null
        );

    if (
        $targetZone === $sourceZone
        && $targetIndex === $sourceIndex
    ) {
        RevisionService::assertExpected(
            $layout,
            $expected,
            RevisionService::ENTITY_LAYOUT
        );

        sb_json_ok([
            'block' =>
                sb_normalize_block_record(
                    $located['block']
                ),
            'layout' =>
                sb_normalize_layout_record(
                    $layout
                ),
            'sourceZone' =>
                $sourceZone,
            'targetZone' =>
                $targetZone,
            'targetIndex' =>
                $targetIndex,
            'changed' => false,
        ]);
    }

    $sourceBlocks =
        array_values(
            $layout['zones'][
                $sourceZone
            ]
        );

    $block =
        $sourceBlocks[
            $sourceIndex
        ];

    array_splice(
        $sourceBlocks,
        $sourceIndex,
        1
    );

    $layout['zones'][
        $sourceZone
    ] = $sourceBlocks;

    if ($sourceZone !== $targetZone) {
        sb_layout_handler_reindex_zone(
            $layout,
            $sourceZone
        );
    }

    $block['updatedAt'] =
        date('c');

    $block['updatedBy'] =
        (int)$USER->GetID();

    $block =
        sb_layout_handler_insert_block(
            $layout,
            $targetZone,
            $block,
            $targetIndex
        );

    $saved =
        RevisionService::saveLayout(
            $layout,
            $expected,
            (int)$USER->GetID(),
            'block_relocate'
        );

    sb_json_ok([
        'block' =>
            sb_normalize_block_record(
                $block
            ),
        'layout' =>
            sb_normalize_layout_record(
                $saved
            ),
        'sourceZone' =>
            $sourceZone,
        'targetZone' =>
            $targetZone,
        'targetIndex' =>
            $targetIndex,
        'changed' => true,
    ]);
}

if ($action === 'layout.block.duplicate') {
    $siteId =
        (int)(
            $_POST['siteId']
            ?? 0
        );

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    if ($id <= 0) {
        sb_json_error(
            'ID_REQUIRED',
            422
        );
    }

    $layout =
        sb_layout_handler_require(
            $siteId
        );

    $located =
        sb_layout_handler_locate_block(
            $layout,
            $id
        );

    if (!$located) {
        sb_json_error(
            'BLOCK_NOT_FOUND',
            404
        );
    }

    $sourceZone =
        (string)$located['zone'];

    $targetZone =
        trim(
            (string)(
                $_POST['targetZone']
                ?? $sourceZone
            )
        );

    if (
        !sb_layout_valid_zone(
            $targetZone
        )
    ) {
        sb_json_error(
            'BAD_ZONE',
            422
        );
    }

    $targetBlocks =
        array_values(
            $layout['zones'][$targetZone]
            ?? []
        );

    $defaultTargetIndex =
        $targetZone === $sourceZone
            ? (int)$located['index'] + 1
            : count($targetBlocks);

    $targetIndex =
        array_key_exists(
            'targetIndex',
            $_POST
        )
            ? max(
                0,
                min(
                    count($targetBlocks),
                    (int)$_POST[
                        'targetIndex'
                    ]
                )
            )
            : $defaultTargetIndex;

    $now =
        date('c');

    $copy =
        $located['block'];

    $copy['id'] =
        sb_layout_next_block_id(
            $layout
        );

    $copy['sort'] = 0;
    $copy['createdBy'] =
        (int)$USER->GetID();
    $copy['createdAt'] =
        $now;
    $copy['updatedBy'] =
        (int)$USER->GetID();
    $copy['updatedAt'] =
        $now;

    $copy =
        sb_layout_handler_insert_block(
            $layout,
            $targetZone,
            $copy,
            $targetIndex
        );

    $saved =
        RevisionService::saveLayout(
            $layout,
            RevisionService::requireExpectedVersion(
                $_POST['expectedVersion']
                ?? null
            ),
            (int)$USER->GetID(),
            'block_duplicate'
        );

    sb_json_ok([
        'block' =>
            sb_normalize_block_record(
                $copy
            ),
        'layout' =>
            sb_normalize_layout_record(
                $saved
            ),
        'sourceZone' =>
            $sourceZone,
        'targetZone' =>
            $targetZone,
        'targetIndex' =>
            $targetIndex,
    ]);
}

sb_json_error(
    'UNKNOWN_LAYOUT_ACTION',
    400
);

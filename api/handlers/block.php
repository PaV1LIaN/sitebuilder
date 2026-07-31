<?php

require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/PageAccessService.php';
require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/GlobalBlockService.php';

global $USER;

/*
 * Локальные функции обработчика блоков.
 */

if (!function_exists('sb_block_handler_validate_global_reference')) {
    function sb_block_handler_validate_global_reference(string $type, array $content, int $siteId): void
    {
        if ($type !== 'global') {
            return;
        }
        $globalBlockId = (int)($content['globalBlockId'] ?? 0);
        if ($globalBlockId <= 0) {
            sb_json_error('GLOBAL_BLOCK_ID_REQUIRED', 422);
        }
        if (!GlobalBlockService::get($globalBlockId, $siteId)) {
            sb_json_error('GLOBAL_BLOCK_NOT_FOUND', 404);
        }
    }
}

if (!function_exists('sb_block_handler_current_user_id')) {
    function sb_block_handler_current_user_id(): int
    {
        global $USER;

        if (
            !is_object($USER)
            || !method_exists($USER, 'IsAuthorized')
            || !$USER->IsAuthorized()
        ) {
            sb_json_error('AUTH_REQUIRED', 401);
        }

        $userId = (int)$USER->GetID();

        if ($userId <= 0) {
            sb_json_error('AUTH_REQUIRED', 401);
        }

        return $userId;
    }
}

if (!function_exists('sb_block_handler_get_page')) {
    function sb_block_handler_get_page(int $pageId): array
    {
        if ($pageId <= 0) {
            sb_json_error('PAGE_ID_REQUIRED', 422);
        }

        $page = sb_find_page($pageId);

        if (!$page) {
            sb_json_error('PAGE_NOT_FOUND', 404);
        }

        $siteId = (int)($page['siteId'] ?? 0);

        if ($siteId <= 0) {
            sb_json_error('SITE_ID_NOT_FOUND', 422, [
                'pageId' => $pageId,
            ]);
        }

        return [
            'page' => $page,
            'pageId' => $pageId,
            'siteId' => $siteId,
        ];
    }
}

if (!function_exists('sb_block_handler_require_page_view')) {
    function sb_block_handler_require_page_view(
        int $siteId,
        int $pageId,
        int $userId
    ): void {
        if (
            !PageAccessService::canViewPage(
                $siteId,
                $pageId,
                $userId
            )
        ) {
            sb_json_error(
                'PAGE_VIEW_ACCESS_DENIED',
                403,
                [
                    'siteId' => $siteId,
                    'pageId' => $pageId,
                ]
            );
        }
    }
}

if (!function_exists('sb_block_handler_require_page_edit')) {
    function sb_block_handler_require_page_edit(
        int $siteId,
        int $pageId,
        int $userId
    ): void {
        if (
            !PageAccessService::canEditPage(
                $siteId,
                $pageId,
                $userId
            )
        ) {
            sb_json_error(
                'PAGE_EDIT_ACCESS_DENIED',
                403,
                [
                    'siteId' => $siteId,
                    'pageId' => $pageId,
                ]
            );
        }
    }
}

/*
 * Получение блоков страницы.
 */
if ($action === 'block.list') {
    $pageId = (int)($_POST['pageId'] ?? 0);

    $pageContext = sb_block_handler_get_page($pageId);

    $currentUserId =
        sb_block_handler_current_user_id();

    sb_block_handler_require_page_view(
        $pageContext['siteId'],
        $pageContext['pageId'],
        $currentUserId
    );

    $blocks = sb_blocks_for_page($pageId);

    $blocks = array_map(
        'sb_normalize_block_record',
        $blocks
    );

    sb_json_ok([
        'blocks' => $blocks,
    ]);
}

/*
 * Создание блока.
 */
if ($action === 'block.create') {
    $pageId = (int)($_POST['pageId'] ?? 0);
    $type = trim(
        (string)($_POST['type'] ?? 'text')
    );

    if ($type === '') {
        sb_json_error('TYPE_REQUIRED', 422);
    }

    $content = sb_default_block_content($type);
    $props = [];

    $contentRaw = $_POST['content'] ?? null;
    if ($contentRaw !== null) {
        $decodedContent = is_array($contentRaw)
            ? $contentRaw
            : json_decode((string)$contentRaw, true);

        if (!is_array($decodedContent)) {
            sb_json_error('BAD_CONTENT_JSON', 422);
        }

        $content = $decodedContent;
    }

    $propsRaw = $_POST['props'] ?? null;
    if ($propsRaw !== null) {
        $decodedProps = is_array($propsRaw)
            ? $propsRaw
            : json_decode((string)$propsRaw, true);

        if (!is_array($decodedProps)) {
            sb_json_error('BAD_PROPS_JSON', 422);
        }

        $props = $decodedProps;
    }

    $pageContext = sb_block_handler_get_page($pageId);

    $currentUserId =
        sb_block_handler_current_user_id();

    sb_block_handler_require_page_edit(
        $pageContext['siteId'],
        $pageContext['pageId'],
        $currentUserId
    );

    sb_block_handler_validate_global_reference($type, $content, (int)$pageContext['siteId']);

    $blocks = sb_read_blocks();

    $block = [
        'id' => sb_next_block_id($blocks),
        'pageId' => $pageId,
        'type' => $type,
        'sort' => sb_next_block_sort(
            $pageId,
            $blocks
        ),
        'content' => $content,
        'props' => $props,
        'createdBy' => $currentUserId,
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'updatedBy' => $currentUserId,
    ];

    $blocks[] = $block;

    sb_write_blocks([$block]);

    sb_json_ok([
        'block' => sb_normalize_block_record(
            $block
        ),
    ]);
}

/*
 * Изменение блока.
 */
if ($action === 'block.update') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    $block = sb_find_block($id);

    if (!$block) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    $pageId = (int)($block['pageId'] ?? 0);

    $pageContext =
        sb_block_handler_get_page($pageId);

    $currentUserId =
        sb_block_handler_current_user_id();

    sb_block_handler_require_page_edit(
        $pageContext['siteId'],
        $pageContext['pageId'],
        $currentUserId
    );

    $contentRaw = $_POST['content'] ?? null;
    $propsRaw = $_POST['props'] ?? null;
    $typeRaw = $_POST['type'] ?? null;

    $newContent = null;
    $newProps = null;
    $newType = null;

    if ($contentRaw !== null) {
        if (is_array($contentRaw)) {
            $newContent = $contentRaw;
        } else {
            $decoded = json_decode(
                (string)$contentRaw,
                true
            );

            if (!is_array($decoded)) {
                sb_json_error(
                    'BAD_CONTENT_JSON',
                    422
                );
            }

            $newContent = $decoded;
        }
    }

    if ($propsRaw !== null) {
        if (is_array($propsRaw)) {
            $newProps = $propsRaw;
        } else {
            $decoded = json_decode(
                (string)$propsRaw,
                true
            );

            if (!is_array($decoded)) {
                sb_json_error(
                    'BAD_PROPS_JSON',
                    422
                );
            }

            $newProps = $decoded;
        }
    }

    if ($typeRaw !== null) {
        $newType = trim((string)$typeRaw);

        if ($newType === '') {
            sb_json_error('TYPE_REQUIRED', 422);
        }
    }

    $updated = $block;

    if ($newType !== null) {
        $updated['type'] = $newType;
    }

    if ($newContent !== null) {
        $updated['content'] = $newContent;
    }

    if ($newProps !== null) {
        $updated['props'] = $newProps;
    }

    sb_block_handler_validate_global_reference(
        (string)($updated['type'] ?? ''),
        is_array($updated['content'] ?? null) ? $updated['content'] : [],
        (int)$pageContext['siteId']
    );

    $expectedVersion = RevisionService::requireExpectedVersion(
        $_POST['expectedVersion'] ?? null
    );

    $saved = RevisionService::saveBlock(
        $updated,
        $expectedVersion,
        $currentUserId,
        'content_update'
    );

    sb_json_ok([
        'block' => sb_normalize_block_record($saved),
    ]);
}

/*
 * Удаление блока.
 */
if ($action === 'block.delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    $block = sb_find_block($id);

    if (!$block) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    $pageId = (int)($block['pageId'] ?? 0);

    $pageContext =
        sb_block_handler_get_page($pageId);

    $currentUserId =
        sb_block_handler_current_user_id();

    sb_block_handler_require_page_edit(
        $pageContext['siteId'],
        $pageContext['pageId'],
        $currentUserId
    );

    $expectedVersion = RevisionService::requireExpectedVersion(
        $_POST['expectedVersion'] ?? null
    );

    $lockedBlock = RevisionService::getBlock($id, true);

    if (!$lockedBlock) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    if ((int)$lockedBlock['pageId'] !== $pageId) {
        sb_json_error('BLOCK_CONTEXT_CHANGED', 409, [
            'entityType' => RevisionService::ENTITY_BLOCK,
            'entityId' => $id,
            'expectedVersion' => $expectedVersion,
            'currentVersion' => (int)($lockedBlock['version'] ?? 0),
        ]);
    }

    RevisionService::assertExpected(
        $lockedBlock,
        $expectedVersion,
        RevisionService::ENTITY_BLOCK
    );
    RevisionService::recordDeletedBlock($lockedBlock, $currentUserId);

    $stmt = sb_db()->prepare("
        DELETE FROM sitebuilder.block
        WHERE id = :id
          AND page_id = :page_id
          AND version = :version
    ");
    $stmt->execute([
        ':id' => $id,
        ':page_id' => $pageId,
        ':version' => $expectedVersion,
    ]);

    if ($stmt->rowCount() !== 1) {
        $latest = RevisionService::getBlock($id, false);

        if ($latest) {
            throw new SiteBuilderVersionConflictException(
                RevisionService::ENTITY_BLOCK,
                $id,
                $expectedVersion,
                (int)($latest['version'] ?? 0)
            );
        }

        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    sb_json_ok([
        'deleted' => true,
        'id' => $id,
        'siteId' => (int)$pageContext['siteId'],
        'pageId' => $pageId,
    ]);
}

/*
 * Копирование блока.
 */
if ($action === 'block.duplicate') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    $expectedVersion = RevisionService::requireExpectedVersion(
        $_POST['expectedVersion'] ?? null
    );
    $versionMap = RevisionService::decodeVersionMap(
        $_POST['expectedVersions'] ?? null
    );

    $sourceBlock = RevisionService::getBlock($id, true);

    if (!$sourceBlock) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    RevisionService::assertExpected(
        $sourceBlock,
        $expectedVersion,
        RevisionService::ENTITY_BLOCK
    );

    $pageId = (int)($sourceBlock['pageId'] ?? 0);
    $pageContext = sb_block_handler_get_page($pageId);
    $currentUserId = sb_block_handler_current_user_id();

    sb_block_handler_require_page_edit(
        $pageContext['siteId'],
        $pageContext['pageId'],
        $currentUserId
    );

    $sourceSort = (int)($sourceBlock['sort'] ?? 500);
    $blocks = sb_read_blocks();

    /*
     * Освобождаем позицию после исходного блока. Каждая затронутая
     * запись проверяется по версии, поэтому дублирование не может
     * молча перезаписать параллельную сортировку.
     */
    foreach ($blocks as $currentBlock) {
        if (
            (int)($currentBlock['pageId'] ?? 0) !== $pageId
            || (int)($currentBlock['sort'] ?? 0) <= $sourceSort
        ) {
            continue;
        }

        $currentId = (int)($currentBlock['id'] ?? 0);
        $currentExpectedVersion = RevisionService::requireVersionFromMap(
            $versionMap,
            $currentId
        );
        $lockedBlock = RevisionService::getBlock($currentId, true);

        if (!$lockedBlock || (int)$lockedBlock['pageId'] !== $pageId) {
            sb_json_error('BLOCK_NOT_FOUND', 404, ['blockId' => $currentId]);
        }

        $lockedBlock['sort'] = (int)$lockedBlock['sort'] + 10;
        RevisionService::saveBlock(
            $lockedBlock,
            $currentExpectedVersion,
            $currentUserId,
            'duplicate_shift'
        );
    }

    $now = date('c');
    $copy = sb_normalize_block_record([
        'id' => RevisionService::nextEntityId(RevisionService::ENTITY_BLOCK),
        'pageId' => $pageId,
        'type' => (string)($sourceBlock['type'] ?? 'text'),
        'sort' => $sourceSort + 10,
        'content' => is_array($sourceBlock['content'] ?? null)
            ? $sourceBlock['content']
            : [],
        'props' => is_array($sourceBlock['props'] ?? null)
            ? $sourceBlock['props']
            : [],
        'createdBy' => $currentUserId,
        'createdAt' => $now,
        'updatedBy' => $currentUserId,
        'updatedAt' => $now,
        'version' => 1,
    ]);

    sb_write_blocks([$copy]);
    $savedCopy = RevisionService::getBlock((int)$copy['id'], false) ?? $copy;

    sb_json_ok([
        'block' => sb_normalize_block_record($savedCopy),
    ]);
}

/*
 * Перемещение блока вверх или вниз.
 */
if ($action === 'block.move') {
    $id = (int)($_POST['id'] ?? 0);
    $direction = trim(
        (string)($_POST['dir'] ?? '')
    );

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    if (
        $direction !== 'up'
        && $direction !== 'down'
    ) {
        sb_json_error('DIR_REQUIRED', 422);
    }

    $block = sb_find_block($id);

    if (!$block) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    $pageId = (int)($block['pageId'] ?? 0);

    $pageContext =
        sb_block_handler_get_page($pageId);

    $currentUserId =
        sb_block_handler_current_user_id();

    sb_block_handler_require_page_edit(
        $pageContext['siteId'],
        $pageContext['pageId'],
        $currentUserId
    );

    $blocks = sb_read_blocks();

    $siblings = array_values(array_filter(
        $blocks,
        static function ($currentBlock) use ($pageId) {
            return
                (int)($currentBlock['pageId'] ?? 0)
                === $pageId;
        }
    ));

    usort(
        $siblings,
        static function ($first, $second) {
            $sortCompare =
                (int)($first['sort'] ?? 500)
                <=>
                (int)($second['sort'] ?? 500);

            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            return
                (int)($first['id'] ?? 0)
                <=>
                (int)($second['id'] ?? 0);
        }
    );

    $position = null;

    foreach ($siblings as $index => $sibling) {
        if (
            (int)($sibling['id'] ?? 0)
            === $id
        ) {
            $position = (int)$index;
            break;
        }
    }

    if ($position === null) {
        sb_json_error(
            'BLOCK_NOT_FOUND_IN_PAGE',
            404
        );
    }

    if (
        $direction === 'up'
        && $position === 0
    ) {
        sb_json_ok([
            'moved' => false,
        ]);
    }

    if (
        $direction === 'down'
        && $position === count($siblings) - 1
    ) {
        sb_json_ok([
            'moved' => false,
        ]);
    }

    $swapPosition =
        $direction === 'up'
            ? $position - 1
            : $position + 1;

    if (!isset($siblings[$swapPosition])) {
        sb_json_ok([
            'moved' => false,
        ]);
    }

    $firstId = (int)(
        $siblings[$position]['id'] ?? 0
    );

    $secondId = (int)(
        $siblings[$swapPosition]['id'] ?? 0
    );

    $firstSort = (int)(
        $siblings[$position]['sort'] ?? 500
    );

    $secondSort = (int)(
        $siblings[$swapPosition]['sort'] ?? 500
    );

    $firstBlock = null;
    $secondBlock = null;

    foreach ($blocks as $currentBlock) {
        $currentBlockId = (int)($currentBlock['id'] ?? 0);
        if ($currentBlockId === $firstId) {
            $firstBlock = $currentBlock;
        } elseif ($currentBlockId === $secondId) {
            $secondBlock = $currentBlock;
        }
    }

    if (!$firstBlock || !$secondBlock) {
        sb_json_error('BLOCK_NOT_FOUND_IN_PAGE', 404);
    }

    $firstBlock['sort'] = $secondSort;
    $secondBlock['sort'] = $firstSort;

    $versionMap = RevisionService::decodeVersionMap(
        $_POST['expectedVersions'] ?? null
    );

    $firstSaved = RevisionService::saveBlock(
        $firstBlock,
        RevisionService::requireVersionFromMap($versionMap, $firstId),
        $currentUserId,
        'reorder'
    );
    $secondSaved = RevisionService::saveBlock(
        $secondBlock,
        RevisionService::requireVersionFromMap($versionMap, $secondId),
        $currentUserId,
        'reorder'
    );

    sb_json_ok([
        'moved' => true,
        'blocks' => [$firstSaved, $secondSaved],
    ]);
}

/*
 * Полное изменение порядка блоков страницы.
 */
if ($action === 'block.reorder') {
    $pageId = (int)($_POST['pageId'] ?? 0);

    $pageContext =
        sb_block_handler_get_page($pageId);

    $currentUserId =
        sb_block_handler_current_user_id();

    sb_block_handler_require_page_edit(
        $pageContext['siteId'],
        $pageContext['pageId'],
        $currentUserId
    );

    $orderRaw = $_POST['order'] ?? null;

    if ($orderRaw === null) {
        sb_json_error('ORDER_REQUIRED', 422);
    }

    if (is_array($orderRaw)) {
        $order = $orderRaw;
    } else {
        $order = json_decode(
            (string)$orderRaw,
            true
        );

        if (!is_array($order)) {
            sb_json_error(
                'BAD_ORDER_JSON',
                422
            );
        }
    }

    /*
     * Убираем некорректные и повторяющиеся ID.
     */
    $orderIds = [];
    $seenIds = [];

    foreach ($order as $item) {
        $blockId = (int)$item;

        if (
            $blockId <= 0
            || isset($seenIds[$blockId])
        ) {
            continue;
        }

        $seenIds[$blockId] = true;
        $orderIds[] = $blockId;
    }

    $pageBlocks =
        sb_blocks_for_page($pageId);

    $pageBlockIds = [];

    foreach ($pageBlocks as $pageBlock) {
        $blockId = (int)(
            $pageBlock['id'] ?? 0
        );

        if ($blockId > 0) {
            $pageBlockIds[$blockId] = true;
        }
    }

    /*
     * Нельзя передать ID блока другой страницы.
     */
    foreach ($orderIds as $blockId) {
        if (!isset($pageBlockIds[$blockId])) {
            sb_json_error(
                'BLOCK_NOT_IN_PAGE',
                422,
                [
                    'blockId' => $blockId,
                    'pageId' => $pageId,
                ]
            );
        }
    }

    /*
     * Блоки, не переданные клиентом,
     * сохраняются в конце списка.
     */
    $missingIds = array_diff(
        array_keys($pageBlockIds),
        $orderIds
    );

    foreach ($missingIds as $blockId) {
        $orderIds[] = (int)$blockId;
    }

    $sortMap = [];
    $sort = 10;

    foreach ($orderIds as $blockId) {
        $sortMap[$blockId] = $sort;
        $sort += 10;
    }

    $blocks = sb_read_blocks();
    $versionMap = RevisionService::decodeVersionMap(
        $_POST['expectedVersions'] ?? null
    );
    $savedBlocks = [];

    foreach ($blocks as $currentBlock) {
        $blockId = (int)($currentBlock['id'] ?? 0);

        if (
            (int)($currentBlock['pageId'] ?? 0) !== $pageId
            || !isset($sortMap[$blockId])
        ) {
            continue;
        }

        $newSort = (int)$sortMap[$blockId];
        if ((int)($currentBlock['sort'] ?? 0) === $newSort) {
            continue;
        }

        $currentBlock['sort'] = $newSort;
        $savedBlocks[] = RevisionService::saveBlock(
            $currentBlock,
            RevisionService::requireVersionFromMap($versionMap, $blockId),
            $currentUserId,
            'reorder'
        );
    }

    sb_json_ok([
        'blocks' => array_map(
            'sb_normalize_block_record',
            sb_blocks_for_page($pageId)
        ),
        'updatedBlocks' => $savedBlocks,
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'block',
    'action' => $action,
]);
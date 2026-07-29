<?php

require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/PageAccessService.php';

global $USER;

/*
 * Локальные функции обработчика блоков.
 */

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

    $pageContext = sb_block_handler_get_page($pageId);

    $currentUserId =
        sb_block_handler_current_user_id();

    sb_block_handler_require_page_edit(
        $pageContext['siteId'],
        $pageContext['pageId'],
        $currentUserId
    );

    $blocks = sb_read_blocks();

    $block = [
        'id' => sb_next_block_id($blocks),
        'pageId' => $pageId,
        'type' => $type,
        'sort' => sb_next_block_sort(
            $pageId,
            $blocks
        ),
        'content' => sb_default_block_content($type),
        'props' => [],
        'createdBy' => $currentUserId,
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'updatedBy' => $currentUserId,
    ];

    $blocks[] = $block;

    sb_write_blocks($blocks);

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

    $blocks = sb_read_blocks();
    $updated = null;

    foreach ($blocks as &$currentBlock) {
        if (
            (int)($currentBlock['id'] ?? 0)
            !== $id
        ) {
            continue;
        }

        if ($newType !== null) {
            $currentBlock['type'] = $newType;
        }

        if ($newContent !== null) {
            $currentBlock['content'] = $newContent;
        }

        if ($newProps !== null) {
            $currentBlock['props'] = $newProps;
        }

        $currentBlock['updatedAt'] = date('c');
        $currentBlock['updatedBy'] =
            $currentUserId;

        $updated = $currentBlock;

        break;
    }
    unset($currentBlock);

    if (!$updated) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    sb_write_blocks($blocks);

    sb_json_ok([
        'block' => sb_normalize_block_record(
            $updated
        ),
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

    $blocks = sb_read_blocks();
    $before = count($blocks);

    $blocks = array_values(array_filter(
        $blocks,
        static function ($currentBlock) use ($id) {
            return
                (int)($currentBlock['id'] ?? 0)
                !== $id;
        }
    ));

    if (count($blocks) === $before) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    sb_write_blocks($blocks);

    sb_json_ok([
        'deleted' => true,
        'id' => $id,
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

    $sourceBlock = sb_find_block($id);

    if (!$sourceBlock) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    $pageId = (int)(
        $sourceBlock['pageId'] ?? 0
    );

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

    $sourceSort = (int)(
        $sourceBlock['sort'] ?? 500
    );

    /*
     * Освобождаем позицию сразу после
     * копируемого блока.
     */
    foreach ($blocks as &$currentBlock) {
        if (
            (int)($currentBlock['pageId'] ?? 0)
                === $pageId
            && (int)($currentBlock['sort'] ?? 0)
                > $sourceSort
        ) {
            $currentBlock['sort'] =
                (int)($currentBlock['sort'] ?? 0)
                + 10;

            $currentBlock['updatedAt'] = date('c');
            $currentBlock['updatedBy'] =
                $currentUserId;
        }
    }
    unset($currentBlock);

    $copy = $sourceBlock;

    $copy['id'] = sb_next_block_id($blocks);
    $copy['sort'] = $sourceSort + 10;
    $copy['createdBy'] = $currentUserId;
    $copy['createdAt'] = date('c');
    $copy['updatedAt'] = date('c');
    $copy['updatedBy'] = $currentUserId;

    $blocks[] = $copy;

    sb_write_blocks($blocks);

    sb_json_ok([
        'block' => sb_normalize_block_record(
            $copy
        ),
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

    foreach ($blocks as &$currentBlock) {
        $currentBlockId = (int)(
            $currentBlock['id'] ?? 0
        );

        if ($currentBlockId === $firstId) {
            $currentBlock['sort'] = $secondSort;
            $currentBlock['updatedAt'] = date('c');
            $currentBlock['updatedBy'] =
                $currentUserId;
        }

        if ($currentBlockId === $secondId) {
            $currentBlock['sort'] = $firstSort;
            $currentBlock['updatedAt'] = date('c');
            $currentBlock['updatedBy'] =
                $currentUserId;
        }
    }
    unset($currentBlock);

    sb_write_blocks($blocks);

    sb_json_ok([
        'moved' => true,
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

    foreach ($blocks as &$currentBlock) {
        $blockId = (int)(
            $currentBlock['id'] ?? 0
        );

        if (
            (int)($currentBlock['pageId'] ?? 0)
                !== $pageId
            || !isset($sortMap[$blockId])
        ) {
            continue;
        }

        $currentBlock['sort'] =
            $sortMap[$blockId];

        $currentBlock['updatedAt'] = date('c');
        $currentBlock['updatedBy'] =
            $currentUserId;
    }
    unset($currentBlock);

    sb_write_blocks($blocks);

    sb_json_ok([
        'blocks' => array_map(
            'sb_normalize_block_record',
            sb_blocks_for_page($pageId)
        ),
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'block',
    'action' => $action,
]);
<?php

global $USER;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageSectionRepository.php';

if (!function_exists('sb_page_section_parse_array')) {
    function sb_page_section_parse_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('sb_page_section_find_page')) {
    function sb_page_section_find_page(int $siteId, int $pageId): ?array
    {
        foreach (sb_read_pages() as $page) {
            if (
                (int)($page['id'] ?? 0) === $pageId &&
                (int)($page['siteId'] ?? 0) === $siteId
            ) {
                return $page;
            }
        }

        return null;
    }
}

if (!function_exists('sb_page_section_require_page')) {
    function sb_page_section_require_page(int $siteId, int $pageId): array
    {
        if ($siteId <= 0) {
            sb_json_error('SITE_ID_REQUIRED', 422);
        }

        if ($pageId <= 0) {
            sb_json_error('PAGE_ID_REQUIRED', 422);
        }

        $page = sb_page_section_find_page($siteId, $pageId);

        if (!$page) {
            sb_json_error('PAGE_NOT_FOUND', 404);
        }

        return $page;
    }
}

if ($action === 'pageSection.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);

    sb_page_section_require_page($siteId, $pageId);
    sb_require_content_manager($siteId);

    $defaultSection = PageSectionRepository::ensureDefaultForPage(
        $siteId,
        $pageId,
        (int)$USER->GetID()
    );

    sb_json_ok([
        'defaultSection' => $defaultSection,
        'sections' => PageSectionRepository::listForPage($siteId, $pageId),
    ]);
}

if ($action === 'pageSection.create') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $layout = sb_page_section_parse_array($_POST['layout'] ?? []);
    $props = sb_page_section_parse_array($_POST['props'] ?? []);

    sb_page_section_require_page($siteId, $pageId);
    sb_require_content_manager($siteId);

    $section = PageSectionRepository::create(
        $siteId,
        $pageId,
        $title,
        $layout,
        $props,
        (int)$USER->GetID()
    );

    sb_json_ok([
        'section' => $section,
        'sections' => PageSectionRepository::listForPage($siteId, $pageId),
    ]);
}

if ($action === 'pageSection.update') {
    $sectionId = (int)($_POST['sectionId'] ?? $_POST['id'] ?? 0);

    if ($sectionId <= 0) {
        sb_json_error('PAGE_SECTION_ID_REQUIRED', 422);
    }

    $section = PageSectionRepository::getById($sectionId);

    if (!$section) {
        sb_json_error('PAGE_SECTION_NOT_FOUND', 404);
    }

    $siteId = (int)$section['siteId'];
    sb_require_content_manager($siteId);

    $fields = [];

    if (array_key_exists('title', $_POST)) {
        $fields['title'] = (string)$_POST['title'];
    }

    if (array_key_exists('layout', $_POST)) {
        $fields['layout'] = sb_page_section_parse_array($_POST['layout']);
    }

    if (array_key_exists('props', $_POST)) {
        $fields['props'] = sb_page_section_parse_array($_POST['props']);
    }

    try {
        $updated = PageSectionRepository::update(
            $sectionId,
            $fields,
            (int)$USER->GetID(),
            RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null)
        );

        sb_json_ok([
            'section' => $updated,
            'sections' => PageSectionRepository::listForPage(
                (int)$updated['siteId'],
                (int)$updated['pageId']
            ),
        ]);
    } catch (SiteBuilderVersionConflictException|InvalidArgumentException $e) {
        throw $e;
    } catch (RuntimeException $e) {
        $known = ['PAGE_SECTION_NOT_FOUND'];
        if (in_array($e->getMessage(), $known, true)) {
            sb_json_error($e->getMessage(), 422);
        }
        throw $e;
    }
}

if ($action === 'pageSection.move') {
    $sectionId = (int)($_POST['sectionId'] ?? $_POST['id'] ?? 0);
    $dir = trim((string)($_POST['dir'] ?? ''));

    if ($sectionId <= 0) {
        sb_json_error('PAGE_SECTION_ID_REQUIRED', 422);
    }

    $section = PageSectionRepository::getById($sectionId);

    if (!$section) {
        sb_json_error('PAGE_SECTION_NOT_FOUND', 404);
    }

    sb_require_content_manager((int)$section['siteId']);

    try {
        $moved = PageSectionRepository::move(
            $sectionId,
            $dir,
            (int)$USER->GetID(),
            RevisionService::decodeVersionMap($_POST['expectedVersions'] ?? null)
        );

        sb_json_ok([
            'moved' => $moved,
            'sections' => PageSectionRepository::listForPage(
                (int)$section['siteId'],
                (int)$section['pageId']
            ),
        ]);
    } catch (SiteBuilderVersionConflictException|InvalidArgumentException $e) {
        throw $e;
    } catch (RuntimeException $e) {
        $known = ['INVALID_DIR', 'PAGE_SECTION_NOT_FOUND', 'PAGE_SECTION_NOT_FOUND_IN_SIBLINGS'];
        if (in_array($e->getMessage(), $known, true)) {
            sb_json_error($e->getMessage(), 422);
        }
        throw $e;
    }
}

if ($action === 'pageSection.delete') {
    $sectionId = (int)($_POST['sectionId'] ?? $_POST['id'] ?? 0);

    if ($sectionId <= 0) {
        sb_json_error('PAGE_SECTION_ID_REQUIRED', 422);
    }

    $section = PageSectionRepository::getById($sectionId);

    if (!$section) {
        sb_json_error('PAGE_SECTION_NOT_FOUND', 404);
    }

    sb_require_content_manager((int)$section['siteId']);

    try {
        PageSectionRepository::delete(
            $sectionId,
            (int)$USER->GetID(),
            RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null)
        );

        sb_json_ok([
            'deleted' => true,
            'id' => $sectionId,
            'siteId' => (int)$section['siteId'],
            'pageId' => (int)$section['pageId'],
            'sections' => PageSectionRepository::listForPage(
                (int)$section['siteId'],
                (int)$section['pageId']
            ),
        ]);
    } catch (SiteBuilderVersionConflictException|InvalidArgumentException $e) {
        throw $e;
    } catch (RuntimeException $e) {
        $known = ['PAGE_SECTION_NOT_FOUND', 'CANNOT_DELETE_LAST_SECTION', 'TARGET_SECTION_NOT_FOUND'];
        if (in_array($e->getMessage(), $known, true)) {
            sb_json_error($e->getMessage(), 422);
        }
        throw $e;
    }
}

if ($action === 'pageSection.assignBlock') {
    $blockId = (int)($_POST['blockId'] ?? 0);
    $sectionId = (int)($_POST['sectionId'] ?? 0);
    $column = (int)($_POST['column'] ?? 1);

    if ($blockId <= 0) {
        sb_json_error('BLOCK_ID_REQUIRED', 422);
    }

    if ($sectionId <= 0) {
        sb_json_error('PAGE_SECTION_ID_REQUIRED', 422);
    }

    $section = PageSectionRepository::getById($sectionId);

    if (!$section) {
        sb_json_error('PAGE_SECTION_NOT_FOUND', 404);
    }

    sb_require_content_manager((int)$section['siteId']);

    $expectedVersion = RevisionService::requireExpectedVersion(
        $_POST['expectedVersion'] ?? null
    );

    $block = PageSectionRepository::assignBlock(
        $blockId,
        $sectionId,
        $column,
        (int)$USER->GetID(),
        $expectedVersion
    );

    sb_json_ok([
        'block' => $block,
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'page_section',
    'action' => $action,
]);

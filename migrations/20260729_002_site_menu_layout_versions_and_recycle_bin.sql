BEGIN;

SELECT pg_advisory_xact_lock(761239, 6);

ALTER TABLE sitebuilder.site ADD COLUMN IF NOT EXISTS version INTEGER;
UPDATE sitebuilder.site SET version = 1 WHERE version IS NULL OR version < 1;
ALTER TABLE sitebuilder.site ALTER COLUMN version SET DEFAULT 1, ALTER COLUMN version SET NOT NULL;

ALTER TABLE sitebuilder.menu ADD COLUMN IF NOT EXISTS version INTEGER;
UPDATE sitebuilder.menu SET version = 1 WHERE version IS NULL OR version < 1;
ALTER TABLE sitebuilder.menu ALTER COLUMN version SET DEFAULT 1, ALTER COLUMN version SET NOT NULL;

ALTER TABLE sitebuilder.layout ADD COLUMN IF NOT EXISTS version INTEGER;
UPDATE sitebuilder.layout SET version = 1 WHERE version IS NULL OR version < 1;
ALTER TABLE sitebuilder.layout ALTER COLUMN version SET DEFAULT 1, ALTER COLUMN version SET NOT NULL;

/*
 * В старых инсталляциях запись layout могла отсутствовать.
 * Создаём её до начального снимка истории, чтобы layout.get не был
 * вынужден исправлять схему отдельной нетранзакционной операцией.
 */
INSERT INTO sitebuilder.layout (
    site_id, settings_json, zones_json,
    created_by, created_at, updated_by, updated_at, version
)
SELECT
    s.id,
    '{"showHeader":true,"showFooter":true,"showLeft":false,"showRight":false,"leftWidth":260,"rightWidth":260,"leftMode":"blocks"}'::jsonb,
    '{"header":[],"footer":[],"left":[],"right":[]}'::jsonb,
    s.created_by,
    COALESCE(s.created_at, NOW()),
    s.updated_by,
    COALESCE(s.updated_at, NOW()),
    1
FROM sitebuilder.site s
WHERE NOT EXISTS (
    SELECT 1
    FROM sitebuilder.layout l
    WHERE l.site_id = s.id
);

ALTER TABLE sitebuilder.entity_revision DROP CONSTRAINT IF EXISTS entity_revision_type_chk;
ALTER TABLE sitebuilder.entity_revision
    ADD CONSTRAINT entity_revision_type_chk
    CHECK (entity_type IN ('site', 'page', 'block', 'menu', 'layout'));

CREATE TABLE IF NOT EXISTS sitebuilder.recycle_bin (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    root_entity_id BIGINT NOT NULL,
    title VARCHAR(500) NOT NULL DEFAULT '',
    snapshot_json JSONB NOT NULL,
    deleted_by BIGINT NULL,
    deleted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    restored_by BIGINT NULL,
    restored_at TIMESTAMPTZ NULL,
    CONSTRAINT recycle_bin_type_chk CHECK (entity_type IN ('page_tree'))
);

CREATE INDEX IF NOT EXISTS recycle_bin_site_active_idx
    ON sitebuilder.recycle_bin (site_id, deleted_at DESC)
    WHERE restored_at IS NULL;

CREATE INDEX IF NOT EXISTS recycle_bin_root_idx
    ON sitebuilder.recycle_bin (entity_type, root_entity_id, id DESC);

INSERT INTO sitebuilder.entity_revision (
    site_id, entity_type, entity_id, page_id, entity_version,
    operation, snapshot_json, created_by, created_at
)
SELECT
    s.id, 'site', s.id, NULL, s.version, 'seed',
    jsonb_build_object(
        'id', s.id,
        'name', s.name,
        'slug', s.slug,
        'sectionId', COALESCE(s.section_id, 0),
        'homePageId', COALESCE(s.home_page_id, 0),
        'diskFolderId', COALESCE(s.disk_folder_id, 0),
        'topMenuId', COALESCE(s.top_menu_id, 0),
        'bitrixGroupId', COALESCE(s.bitrix_group_id, 0),
        'bitrixGroupCreatedBy', COALESCE(s.bitrix_group_created_by, 0),
        'bitrixGroupCreatedAt', s.bitrix_group_created_at,
        'settings', s.settings_json,
        'layout', s.layout_json,
        'createdBy', s.created_by,
        'createdAt', s.created_at,
        'updatedBy', s.updated_by,
        'updatedAt', s.updated_at,
        'version', s.version
    ),
    s.updated_by,
    COALESCE(s.updated_at, NOW())
FROM sitebuilder.site s
WHERE NOT EXISTS (
    SELECT 1 FROM sitebuilder.entity_revision r
    WHERE r.entity_type = 'site' AND r.entity_id = s.id
);

INSERT INTO sitebuilder.entity_revision (
    site_id, entity_type, entity_id, page_id, entity_version,
    operation, snapshot_json, created_by, created_at
)
SELECT
    m.site_id, 'menu', m.id, NULL, m.version, 'seed',
    jsonb_build_object(
        'id', m.id,
        'siteId', m.site_id,
        'name', m.name,
        'items', m.items_json,
        'createdBy', m.created_by,
        'createdAt', m.created_at,
        'updatedBy', m.updated_by,
        'updatedAt', m.updated_at,
        'version', m.version
    ),
    m.updated_by,
    COALESCE(m.updated_at, NOW())
FROM sitebuilder.menu m
WHERE NOT EXISTS (
    SELECT 1 FROM sitebuilder.entity_revision r
    WHERE r.entity_type = 'menu' AND r.entity_id = m.id
);

INSERT INTO sitebuilder.entity_revision (
    site_id, entity_type, entity_id, page_id, entity_version,
    operation, snapshot_json, created_by, created_at
)
SELECT
    l.site_id, 'layout', l.site_id, NULL, l.version, 'seed',
    jsonb_build_object(
        'siteId', l.site_id,
        'settings', l.settings_json,
        'zones', l.zones_json,
        'createdBy', l.created_by,
        'createdAt', l.created_at,
        'updatedBy', l.updated_by,
        'updatedAt', l.updated_at,
        'version', l.version
    ),
    l.updated_by,
    COALESCE(l.updated_at, NOW())
FROM sitebuilder.layout l
WHERE NOT EXISTS (
    SELECT 1 FROM sitebuilder.entity_revision r
    WHERE r.entity_type = 'layout' AND r.entity_id = l.site_id
);

COMMIT;

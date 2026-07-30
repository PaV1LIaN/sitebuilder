BEGIN;

SELECT pg_advisory_xact_lock(761239, 5);

ALTER TABLE sitebuilder.page
    ADD COLUMN IF NOT EXISTS version INTEGER;

UPDATE sitebuilder.page
SET version = 1
WHERE version IS NULL OR version < 1;

ALTER TABLE sitebuilder.page
    ALTER COLUMN version SET DEFAULT 1,
    ALTER COLUMN version SET NOT NULL;

ALTER TABLE sitebuilder.block
    ADD COLUMN IF NOT EXISTS version INTEGER;

UPDATE sitebuilder.block
SET version = 1
WHERE version IS NULL OR version < 1;

ALTER TABLE sitebuilder.block
    ALTER COLUMN version SET DEFAULT 1,
    ALTER COLUMN version SET NOT NULL;

CREATE TABLE IF NOT EXISTS sitebuilder.entity_revision (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL,
    entity_type VARCHAR(16) NOT NULL,
    entity_id BIGINT NOT NULL,
    page_id BIGINT NULL,
    entity_version INTEGER NOT NULL,
    operation VARCHAR(32) NOT NULL,
    snapshot_json JSONB NOT NULL,
    created_by BIGINT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    restored_from_revision_id BIGINT NULL,
    CONSTRAINT entity_revision_type_chk
        CHECK (entity_type IN ('page', 'block')),
    CONSTRAINT entity_revision_version_chk
        CHECK (entity_version > 0)
);

CREATE INDEX IF NOT EXISTS entity_revision_entity_idx
    ON sitebuilder.entity_revision (entity_type, entity_id, id DESC);

CREATE INDEX IF NOT EXISTS entity_revision_site_idx
    ON sitebuilder.entity_revision (site_id, created_at DESC);

CREATE INDEX IF NOT EXISTS entity_revision_page_idx
    ON sitebuilder.entity_revision (page_id, created_at DESC)
    WHERE page_id IS NOT NULL;

INSERT INTO sitebuilder.entity_revision (
    site_id,
    entity_type,
    entity_id,
    page_id,
    entity_version,
    operation,
    snapshot_json,
    created_by,
    created_at
)
SELECT
    p.site_id,
    'page',
    p.id,
    p.id,
    p.version,
    'seed',
    jsonb_build_object(
        'id', p.id,
        'siteId', p.site_id,
        'title', p.title,
        'slug', p.slug,
        'parentId', COALESCE(p.parent_id, 0),
        'sort', p.sort,
        'status', p.status,
        'publishedAt', p.published_at,
        'createdBy', p.created_by,
        'createdAt', p.created_at,
        'updatedBy', p.updated_by,
        'updatedAt', p.updated_at,
        'version', p.version
    ),
    p.updated_by,
    COALESCE(p.updated_at, NOW())
FROM sitebuilder.page p
WHERE NOT EXISTS (
    SELECT 1
    FROM sitebuilder.entity_revision r
    WHERE r.entity_type = 'page'
      AND r.entity_id = p.id
);

INSERT INTO sitebuilder.entity_revision (
    site_id,
    entity_type,
    entity_id,
    page_id,
    entity_version,
    operation,
    snapshot_json,
    created_by,
    created_at
)
SELECT
    p.site_id,
    'block',
    b.id,
    b.page_id,
    b.version,
    'seed',
    jsonb_build_object(
        'id', b.id,
        'pageId', b.page_id,
        'type', b.type,
        'sort', b.sort,
        'content', b.content_json,
        'props', b.props_json,
        'createdBy', b.created_by,
        'createdAt', b.created_at,
        'updatedBy', b.updated_by,
        'updatedAt', b.updated_at,
        'version', b.version
    ),
    b.updated_by,
    COALESCE(b.updated_at, NOW())
FROM sitebuilder.block b
JOIN sitebuilder.page p ON p.id = b.page_id
WHERE NOT EXISTS (
    SELECT 1
    FROM sitebuilder.entity_revision r
    WHERE r.entity_type = 'block'
      AND r.entity_id = b.id
);

COMMIT;

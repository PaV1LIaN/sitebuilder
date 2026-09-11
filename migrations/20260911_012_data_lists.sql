BEGIN;
SELECT pg_advisory_xact_lock(761239, 23);

CREATE TABLE IF NOT EXISTS sitebuilder.data_list (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL,
    owner_page_id BIGINT NOT NULL,
    title VARCHAR(160) NOT NULL,
    fields_json JSONB NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(fields_json) = 'array'),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by BIGINT NOT NULL,
    updated_by BIGINT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS data_list_site_page_idx ON sitebuilder.data_list(site_id, owner_page_id);

CREATE TABLE IF NOT EXISTS sitebuilder.data_list_item (
    id BIGSERIAL PRIMARY KEY,
    list_id BIGINT NOT NULL REFERENCES sitebuilder.data_list(id) ON DELETE CASCADE,
    values_json JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(values_json) = 'object'),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    request_key VARCHAR(64),
    created_by BIGINT NOT NULL,
    updated_by BIGINT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ,
    UNIQUE (list_id, request_key)
);
CREATE INDEX IF NOT EXISTS data_list_item_active_idx ON sitebuilder.data_list_item(list_id, deleted_at, id);
COMMIT;

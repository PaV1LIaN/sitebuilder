BEGIN;

/*
 * Этап 9.
 * 1. Канонические PostgreSQL sequences для основных сущностей.
 * 2. Transactional outbox для внешних операций Битрикс24.
 */

CREATE SEQUENCE IF NOT EXISTS sitebuilder.site_id_seq AS BIGINT;
CREATE SEQUENCE IF NOT EXISTS sitebuilder.page_id_seq AS BIGINT;
CREATE SEQUENCE IF NOT EXISTS sitebuilder.block_id_seq AS BIGINT;
CREATE SEQUENCE IF NOT EXISTS sitebuilder.menu_id_seq AS BIGINT;

ALTER SEQUENCE sitebuilder.site_id_seq OWNED BY sitebuilder.site.id;
ALTER SEQUENCE sitebuilder.page_id_seq OWNED BY sitebuilder.page.id;
ALTER SEQUENCE sitebuilder.block_id_seq OWNED BY sitebuilder.block.id;
ALTER SEQUENCE sitebuilder.menu_id_seq OWNED BY sitebuilder.menu.id;

ALTER TABLE sitebuilder.site
    ALTER COLUMN id SET DEFAULT nextval('sitebuilder.site_id_seq'::regclass);
ALTER TABLE sitebuilder.page
    ALTER COLUMN id SET DEFAULT nextval('sitebuilder.page_id_seq'::regclass);
ALTER TABLE sitebuilder.block
    ALTER COLUMN id SET DEFAULT nextval('sitebuilder.block_id_seq'::regclass);
ALTER TABLE sitebuilder.menu
    ALTER COLUMN id SET DEFAULT nextval('sitebuilder.menu_id_seq'::regclass);

/*
 * Синхронизируем sequences не только с текущими строками, но и с историей.
 * Удалённый ID не будет повторно выдан и не смешает две цепочки ревизий.
 */
DO $$
DECLARE
    v_entity_max BIGINT;
    v_last BIGINT;
    v_called BOOLEAN;
    v_target BIGINT;
BEGIN
    SELECT GREATEST(
        COALESCE((SELECT MAX(id) FROM sitebuilder.site), 0),
        COALESCE((SELECT MAX(entity_id) FROM sitebuilder.entity_revision WHERE entity_type = 'site'), 0)
    ) INTO v_entity_max;
    SELECT last_value, is_called INTO v_last, v_called FROM sitebuilder.site_id_seq;
    v_target := GREATEST(v_entity_max, v_last);
    PERFORM setval(
        'sitebuilder.site_id_seq'::regclass,
        v_target,
        NOT (v_target = v_last AND NOT v_called AND v_entity_max < v_last)
    );

    SELECT GREATEST(
        COALESCE((SELECT MAX(id) FROM sitebuilder.page), 0),
        COALESCE((SELECT MAX(entity_id) FROM sitebuilder.entity_revision WHERE entity_type = 'page'), 0)
    ) INTO v_entity_max;
    SELECT last_value, is_called INTO v_last, v_called FROM sitebuilder.page_id_seq;
    v_target := GREATEST(v_entity_max, v_last);
    PERFORM setval(
        'sitebuilder.page_id_seq'::regclass,
        v_target,
        NOT (v_target = v_last AND NOT v_called AND v_entity_max < v_last)
    );

    SELECT GREATEST(
        COALESCE((SELECT MAX(id) FROM sitebuilder.block), 0),
        COALESCE((SELECT MAX(entity_id) FROM sitebuilder.entity_revision WHERE entity_type = 'block'), 0)
    ) INTO v_entity_max;
    SELECT last_value, is_called INTO v_last, v_called FROM sitebuilder.block_id_seq;
    v_target := GREATEST(v_entity_max, v_last);
    PERFORM setval(
        'sitebuilder.block_id_seq'::regclass,
        v_target,
        NOT (v_target = v_last AND NOT v_called AND v_entity_max < v_last)
    );

    SELECT GREATEST(
        COALESCE((SELECT MAX(id) FROM sitebuilder.menu), 0),
        COALESCE((SELECT MAX(entity_id) FROM sitebuilder.entity_revision WHERE entity_type = 'menu'), 0)
    ) INTO v_entity_max;
    SELECT last_value, is_called INTO v_last, v_called FROM sitebuilder.menu_id_seq;
    v_target := GREATEST(v_entity_max, v_last);
    PERFORM setval(
        'sitebuilder.menu_id_seq'::regclass,
        v_target,
        NOT (v_target = v_last AND NOT v_called AND v_entity_max < v_last)
    );
END
$$;

CREATE TABLE IF NOT EXISTS sitebuilder.outbox_job (
    id BIGSERIAL PRIMARY KEY,
    job_type VARCHAR(100) NOT NULL,
    site_id BIGINT NULL,
    aggregate_type VARCHAR(50) NOT NULL DEFAULT '',
    aggregate_id BIGINT NULL,
    payload_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    dedupe_key VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    priority SMALLINT NOT NULL DEFAULT 100,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 8,
    available_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    locked_at TIMESTAMPTZ NULL,
    locked_by VARCHAR(120) NULL,
    last_error_code VARCHAR(120) NULL,
    last_error_at TIMESTAMPTZ NULL,
    result_json JSONB NULL,
    created_by BIGINT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ NULL,
    CONSTRAINT outbox_job_status_chk CHECK (
        status IN ('pending', 'running', 'retry', 'succeeded', 'cancelled', 'dead')
    ),
    CONSTRAINT outbox_job_attempts_chk CHECK (attempts >= 0 AND max_attempts > 0),
    CONSTRAINT outbox_job_payload_object_chk CHECK (jsonb_typeof(payload_json) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_outbox_job_claim
    ON sitebuilder.outbox_job (status, available_at, priority, id)
    WHERE status IN ('pending', 'retry');

CREATE INDEX IF NOT EXISTS idx_outbox_job_site_created
    ON sitebuilder.outbox_job (site_id, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_outbox_job_running
    ON sitebuilder.outbox_job (locked_at, id)
    WHERE status = 'running';

CREATE UNIQUE INDEX IF NOT EXISTS uq_outbox_job_active_dedupe
    ON sitebuilder.outbox_job (dedupe_key)
    WHERE dedupe_key IS NOT NULL
      AND status IN ('pending', 'running', 'retry');

CREATE TABLE IF NOT EXISTS sitebuilder.outbox_job_event (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT NOT NULL REFERENCES sitebuilder.outbox_job(id) ON DELETE CASCADE,
    event_type VARCHAR(50) NOT NULL,
    details_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT outbox_job_event_details_object_chk CHECK (jsonb_typeof(details_json) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_outbox_job_event_job
    ON sitebuilder.outbox_job_event (job_id, id ASC);

COMMIT;

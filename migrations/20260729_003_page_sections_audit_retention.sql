BEGIN;

SELECT pg_advisory_xact_lock(761239, 7);

CREATE TABLE IF NOT EXISTS sitebuilder.page_section (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL,
    page_id BIGINT NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'section',
    title VARCHAR(500) NOT NULL DEFAULT 'Секция',
    sort INTEGER NOT NULL DEFAULT 500,
    layout_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    props_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_by BIGINT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by BIGINT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    version INTEGER NOT NULL DEFAULT 1,
    CONSTRAINT page_section_version_chk CHECK (version > 0),
    CONSTRAINT page_section_site_fk FOREIGN KEY (site_id)
        REFERENCES sitebuilder.site(id) ON DELETE CASCADE,
    CONSTRAINT page_section_page_fk FOREIGN KEY (page_id)
        REFERENCES sitebuilder.page(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS page_section_page_sort_idx
    ON sitebuilder.page_section (page_id, sort, id);

CREATE INDEX IF NOT EXISTS page_section_site_idx
    ON sitebuilder.page_section (site_id, page_id);

CREATE TABLE IF NOT EXISTS sitebuilder.audit_log (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(64) NOT NULL,
    site_id BIGINT NULL,
    actor_user_id BIGINT NULL,
    actor_access_code VARCHAR(128) NOT NULL DEFAULT '',
    action VARCHAR(128) NOT NULL,
    entity_type VARCHAR(64) NOT NULL DEFAULT '',
    entity_id BIGINT NULL,
    page_id BIGINT NULL,
    outcome VARCHAR(16) NOT NULL DEFAULT 'success',
    http_status SMALLINT NOT NULL DEFAULT 200,
    client_ip VARCHAR(64) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    details_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT audit_log_outcome_chk CHECK (outcome IN ('success', 'error')),
    CONSTRAINT audit_log_http_status_chk CHECK (http_status BETWEEN 100 AND 599)
);

CREATE INDEX IF NOT EXISTS audit_log_site_created_idx
    ON sitebuilder.audit_log (site_id, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS audit_log_actor_created_idx
    ON sitebuilder.audit_log (actor_user_id, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS audit_log_action_created_idx
    ON sitebuilder.audit_log (action, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS audit_log_entity_idx
    ON sitebuilder.audit_log (entity_type, entity_id, created_at DESC);

CREATE TABLE IF NOT EXISTS sitebuilder.maintenance_state (
    task_key VARCHAR(100) PRIMARY KEY,
    last_run_at TIMESTAMPTZ NULL,
    last_success_at TIMESTAMPTZ NULL,
    last_result_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO sitebuilder.maintenance_state (task_key, last_result_json)
VALUES ('retention_cleanup', '{}'::jsonb)
ON CONFLICT (task_key) DO NOTHING;

COMMIT;

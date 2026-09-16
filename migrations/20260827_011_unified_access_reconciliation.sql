/*
 * SiteBuilder — этап 22.
 * Надёжная сверка прав SiteBuilder, рабочей группы портала и Bitrix Disk ACL.
 */

CREATE TABLE IF NOT EXISTS sitebuilder.access_reconcile_run (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL REFERENCES sitebuilder.site(id) ON DELETE CASCADE,
    mode VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    actor_user_id BIGINT NULL,
    job_id BIGINT NULL REFERENCES sitebuilder.outbox_job(id) ON DELETE SET NULL,
    planned_count INTEGER NOT NULL DEFAULT 0,
    applied_count INTEGER NOT NULL DEFAULT 0,
    conflict_count INTEGER NOT NULL DEFAULT 0,
    skipped_count INTEGER NOT NULL DEFAULT 0,
    error_code VARCHAR(120) NULL,
    details_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ NULL,
    CONSTRAINT access_reconcile_run_mode_chk
        CHECK (mode IN ('audit','repair')),
    CONSTRAINT access_reconcile_run_status_chk
        CHECK (status IN ('running','succeeded','partial','failed')),
    CONSTRAINT access_reconcile_run_counts_chk CHECK (
        planned_count >= 0
        AND applied_count >= 0
        AND conflict_count >= 0
        AND skipped_count >= 0
    ),
    CONSTRAINT access_reconcile_run_details_chk
        CHECK (jsonb_typeof(details_json) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_access_reconcile_run_site
    ON sitebuilder.access_reconcile_run (site_id, started_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS sitebuilder.access_sync_binding (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL REFERENCES sitebuilder.site(id) ON DELETE CASCADE,
    target_type VARCHAR(30) NOT NULL,
    target_id BIGINT NOT NULL,
    access_code VARCHAR(100) NOT NULL,
    desired_level VARCHAR(100) NOT NULL DEFAULT '',
    applied_level VARCHAR(100) NOT NULL DEFAULT '',
    last_external_level VARCHAR(100) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    managed BOOLEAN NOT NULL DEFAULT TRUE,
    last_run_id BIGINT NULL REFERENCES sitebuilder.access_reconcile_run(id) ON DELETE SET NULL,
    last_error_code VARCHAR(120) NULL,
    metadata_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    first_managed_at TIMESTAMPTZ NULL,
    last_checked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_applied_at TIMESTAMPTZ NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT access_sync_binding_target_chk
        CHECK (target_type IN ('portal_member','disk_acl')),
    CONSTRAINT access_sync_binding_status_chk
        CHECK (status IN ('pending','synced','drift','conflict','removed','error')),
    CONSTRAINT access_sync_binding_access_code_chk
        CHECK (access_code ~ '^U[1-9][0-9]*$'),
    CONSTRAINT access_sync_binding_metadata_chk
        CHECK (jsonb_typeof(metadata_json) = 'object'),
    CONSTRAINT access_sync_binding_unique
        UNIQUE (site_id, target_type, target_id, access_code)
);

CREATE INDEX IF NOT EXISTS idx_access_sync_binding_status
    ON sitebuilder.access_sync_binding (
        site_id,
        target_type,
        status,
        target_id,
        access_code
    );

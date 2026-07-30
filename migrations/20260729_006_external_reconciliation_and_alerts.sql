/*
 * SiteBuilder — этап 11.
 * 1. Реестр внешних ресурсов Битрикс24/Диска.
 * 2. История запусков сверки.
 * 3. Системные оповещения и история доставок.
 */

CREATE TABLE IF NOT EXISTS sitebuilder.external_reconcile_run (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NULL,
    mode VARCHAR(20) NOT NULL DEFAULT 'audit',
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    actor_user_id BIGINT NULL,
    job_id BIGINT NULL REFERENCES sitebuilder.outbox_job(id) ON DELETE SET NULL,
    checked_sites INTEGER NOT NULL DEFAULT 0,
    checked_groups INTEGER NOT NULL DEFAULT 0,
    checked_folders INTEGER NOT NULL DEFAULT 0,
    anomalies INTEGER NOT NULL DEFAULT 0,
    repairs INTEGER NOT NULL DEFAULT 0,
    cleanup_jobs INTEGER NOT NULL DEFAULT 0,
    error_code VARCHAR(120) NULL,
    details_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ NULL,
    CONSTRAINT external_reconcile_run_mode_chk CHECK (mode IN ('audit','repair')),
    CONSTRAINT external_reconcile_run_status_chk CHECK (status IN ('running','succeeded','partial','failed')),
    CONSTRAINT external_reconcile_run_counts_chk CHECK (
        checked_sites >= 0 AND checked_groups >= 0 AND checked_folders >= 0
        AND anomalies >= 0 AND repairs >= 0 AND cleanup_jobs >= 0
    ),
    CONSTRAINT external_reconcile_run_details_chk CHECK (jsonb_typeof(details_json) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_external_reconcile_run_started
    ON sitebuilder.external_reconcile_run (started_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_external_reconcile_run_site
    ON sitebuilder.external_reconcile_run (site_id, started_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS sitebuilder.external_resource_registry (
    id BIGSERIAL PRIMARY KEY,
    resource_type VARCHAR(40) NOT NULL,
    external_id BIGINT NOT NULL,
    site_id BIGINT NULL,
    expected_name TEXT NOT NULL DEFAULT '',
    actual_name TEXT NOT NULL DEFAULT '',
    relation_status VARCHAR(30) NOT NULL,
    managed BOOLEAN NOT NULL DEFAULT FALSE,
    last_reconcile_run_id BIGINT NULL REFERENCES sitebuilder.external_reconcile_run(id) ON DELETE SET NULL,
    metadata_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    first_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_checked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMPTZ NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT external_resource_registry_type_chk CHECK (resource_type IN ('bitrix_group','disk_folder')),
    CONSTRAINT external_resource_registry_status_chk CHECK (
        relation_status IN ('attached','missing','mismatched','orphaned','cleanup_pending','deleted','unknown')
    ),
    CONSTRAINT external_resource_registry_metadata_chk CHECK (jsonb_typeof(metadata_json) = 'object'),
    CONSTRAINT external_resource_registry_unique UNIQUE (resource_type, external_id)
);

CREATE INDEX IF NOT EXISTS idx_external_resource_registry_site
    ON sitebuilder.external_resource_registry (site_id, resource_type, relation_status, external_id);
CREATE INDEX IF NOT EXISTS idx_external_resource_registry_status
    ON sitebuilder.external_resource_registry (relation_status, resource_type, updated_at DESC);

CREATE TABLE IF NOT EXISTS sitebuilder.system_alert (
    id BIGSERIAL PRIMARY KEY,
    alert_key VARCHAR(255) NOT NULL UNIQUE,
    severity VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    site_id BIGINT NULL,
    source_type VARCHAR(50) NOT NULL DEFAULT '',
    source_id BIGINT NULL,
    code VARCHAR(120) NOT NULL,
    title TEXT NOT NULL,
    details_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    occurrences INTEGER NOT NULL DEFAULT 1,
    first_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_notified_at TIMESTAMPTZ NULL,
    acknowledged_by BIGINT NULL,
    acknowledged_at TIMESTAMPTZ NULL,
    resolved_by BIGINT NULL,
    resolved_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT system_alert_severity_chk CHECK (severity IN ('info','warning','critical')),
    CONSTRAINT system_alert_status_chk CHECK (status IN ('open','acknowledged','resolved')),
    CONSTRAINT system_alert_occurrences_chk CHECK (occurrences > 0),
    CONSTRAINT system_alert_details_chk CHECK (jsonb_typeof(details_json) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_system_alert_open
    ON sitebuilder.system_alert (status, severity, last_seen_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_system_alert_site
    ON sitebuilder.system_alert (site_id, status, last_seen_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS sitebuilder.system_alert_delivery (
    id BIGSERIAL PRIMARY KEY,
    alert_id BIGINT NOT NULL REFERENCES sitebuilder.system_alert(id) ON DELETE CASCADE,
    channel VARCHAR(30) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL,
    error_code VARCHAR(120) NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    delivered_at TIMESTAMPTZ NULL,
    CONSTRAINT system_alert_delivery_channel_chk CHECK (channel IN ('bitrix_im','email')),
    CONSTRAINT system_alert_delivery_status_chk CHECK (status IN ('delivered','failed','skipped'))
);

CREATE INDEX IF NOT EXISTS idx_system_alert_delivery_alert
    ON sitebuilder.system_alert_delivery (alert_id, attempted_at DESC, id DESC);

INSERT INTO sitebuilder.maintenance_state (task_key,last_result_json)
VALUES ('external_resource_reconcile', '{}'::jsonb)
ON CONFLICT (task_key) DO NOTHING;

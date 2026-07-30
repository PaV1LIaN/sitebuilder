/*
 * SiteBuilder — этап 12.
 * 1. Реестр резервных копий сайта.
 * 2. История проверок целостности PostgreSQL-модели.
 */

BEGIN;

SELECT pg_advisory_xact_lock(761239, 12);

CREATE TABLE IF NOT EXISTS sitebuilder.site_backup (
    id BIGSERIAL PRIMARY KEY,
    original_site_id BIGINT NOT NULL,
    site_name TEXT NOT NULL DEFAULT '',
    site_slug TEXT NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'creating',
    format_version INTEGER NOT NULL DEFAULT 1,
    include_access BOOLEAN NOT NULL DEFAULT FALSE,
    storage_path TEXT NOT NULL DEFAULT '',
    compression VARCHAR(20) NOT NULL DEFAULT 'gzip',
    sha256 CHAR(64) NOT NULL DEFAULT '',
    file_size BIGINT NOT NULL DEFAULT 0,
    payload_size BIGINT NOT NULL DEFAULT 0,
    metadata_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    error_code VARCHAR(120) NULL,
    created_by BIGINT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    verified_at TIMESTAMPTZ NULL,
    expires_at TIMESTAMPTZ NULL,
    last_restored_site_id BIGINT NULL,
    restored_by BIGINT NULL,
    restored_at TIMESTAMPTZ NULL,
    deleted_by BIGINT NULL,
    deleted_at TIMESTAMPTZ NULL,
    CONSTRAINT site_backup_status_chk CHECK (
        status IN ('creating','ready','failed','corrupt','deleted')
    ),
    CONSTRAINT site_backup_compression_chk CHECK (
        compression IN ('gzip','none')
    ),
    CONSTRAINT site_backup_sizes_chk CHECK (
        file_size >= 0 AND payload_size >= 0 AND format_version > 0
    ),
    CONSTRAINT site_backup_metadata_chk CHECK (
        jsonb_typeof(metadata_json) = 'object'
    )
);

CREATE INDEX IF NOT EXISTS idx_site_backup_site_created
    ON sitebuilder.site_backup (original_site_id, created_at DESC, id DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_site_backup_expiry
    ON sitebuilder.site_backup (expires_at, id)
    WHERE status IN ('ready','corrupt') AND deleted_at IS NULL;

CREATE TABLE IF NOT EXISTS sitebuilder.integrity_check_run (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    actor_user_id BIGINT NULL,
    checked_sites INTEGER NOT NULL DEFAULT 0,
    checked_pages INTEGER NOT NULL DEFAULT 0,
    checked_blocks INTEGER NOT NULL DEFAULT 0,
    checked_sections INTEGER NOT NULL DEFAULT 0,
    checked_menus INTEGER NOT NULL DEFAULT 0,
    errors_count INTEGER NOT NULL DEFAULT 0,
    warnings_count INTEGER NOT NULL DEFAULT 0,
    issues_json JSONB NOT NULL DEFAULT '[]'::jsonb,
    summary_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    error_code VARCHAR(120) NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ NULL,
    duration_ms BIGINT NULL,
    CONSTRAINT integrity_check_run_status_chk CHECK (
        status IN ('running','succeeded','failed')
    ),
    CONSTRAINT integrity_check_run_counts_chk CHECK (
        checked_sites >= 0 AND checked_pages >= 0 AND checked_blocks >= 0
        AND checked_sections >= 0 AND checked_menus >= 0
        AND errors_count >= 0 AND warnings_count >= 0
        AND (duration_ms IS NULL OR duration_ms >= 0)
    ),
    CONSTRAINT integrity_check_run_issues_chk CHECK (
        jsonb_typeof(issues_json) = 'array'
    ),
    CONSTRAINT integrity_check_run_summary_chk CHECK (
        jsonb_typeof(summary_json) = 'object'
    )
);

CREATE INDEX IF NOT EXISTS idx_integrity_check_run_site
    ON sitebuilder.integrity_check_run (site_id, started_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_integrity_check_run_started
    ON sitebuilder.integrity_check_run (started_at DESC, id DESC);

COMMIT;

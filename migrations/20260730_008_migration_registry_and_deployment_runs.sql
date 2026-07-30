/*
 * SiteBuilder — этап 13.
 * 1. Реестр применённых миграций с SHA-256.
 * 2. История запусков развёртывания и preflight-проверок.
 */

BEGIN;

SELECT pg_advisory_xact_lock(761239, 13);

CREATE TABLE IF NOT EXISTS sitebuilder.schema_migration (
    migration_key VARCHAR(64) PRIMARY KEY,
    stage_number INTEGER NOT NULL,
    filename TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    checksum CHAR(64) NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'executed',
    execution_ms BIGINT NOT NULL DEFAULT 0,
    applied_by BIGINT NULL,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    metadata_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT schema_migration_stage_chk CHECK (stage_number > 0),
    CONSTRAINT schema_migration_source_chk CHECK (source IN ('executed','baseline')),
    CONSTRAINT schema_migration_execution_chk CHECK (execution_ms >= 0),
    CONSTRAINT schema_migration_checksum_chk CHECK (checksum ~ '^[0-9a-f]{64}$'),
    CONSTRAINT schema_migration_metadata_chk CHECK (jsonb_typeof(metadata_json) = 'object')
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_schema_migration_stage
    ON sitebuilder.schema_migration (stage_number);

CREATE TABLE IF NOT EXISTS sitebuilder.deployment_run (
    id BIGSERIAL PRIMARY KEY,
    mode VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    actor_user_id BIGINT NULL,
    host_name VARCHAR(255) NOT NULL DEFAULT '',
    php_sapi VARCHAR(80) NOT NULL DEFAULT '',
    current_migration_key VARCHAR(64) NULL,
    applied_count INTEGER NOT NULL DEFAULT 0,
    baseline_count INTEGER NOT NULL DEFAULT 0,
    skipped_count INTEGER NOT NULL DEFAULT 0,
    failed_migration_key VARCHAR(64) NULL,
    error_code VARCHAR(120) NULL,
    details_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ NULL,
    duration_ms BIGINT NULL,
    CONSTRAINT deployment_run_mode_chk CHECK (mode IN ('bootstrap','migrate','preflight')),
    CONSTRAINT deployment_run_status_chk CHECK (status IN ('running','succeeded','partial','failed')),
    CONSTRAINT deployment_run_counts_chk CHECK (
        applied_count >= 0 AND baseline_count >= 0 AND skipped_count >= 0
        AND (duration_ms IS NULL OR duration_ms >= 0)
    ),
    CONSTRAINT deployment_run_details_chk CHECK (jsonb_typeof(details_json) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_deployment_run_started
    ON sitebuilder.deployment_run (started_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_deployment_run_status
    ON sitebuilder.deployment_run (status, started_at DESC, id DESC);

COMMIT;

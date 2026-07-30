BEGIN;

/*
 * Этап 10.
 * 1. Метрики и heartbeat фоновых worker-процессов.
 * 2. История запусков очереди для диагностики и мониторинга.
 * Типы заданий очистки используют существующую sitebuilder.outbox_job.
 */

CREATE TABLE IF NOT EXISTS sitebuilder.queue_worker_state (
    worker_id VARCHAR(120) PRIMARY KEY,
    host_name VARCHAR(255) NOT NULL DEFAULT '',
    process_id INTEGER NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'idle',
    current_job_id BIGINT NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    heartbeat_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_run_started_at TIMESTAMPTZ NULL,
    last_run_finished_at TIMESTAMPTZ NULL,
    last_error_code VARCHAR(120) NULL,
    batches_total BIGINT NOT NULL DEFAULT 0,
    claimed_total BIGINT NOT NULL DEFAULT 0,
    succeeded_total BIGINT NOT NULL DEFAULT 0,
    retried_total BIGINT NOT NULL DEFAULT 0,
    dead_total BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT queue_worker_state_status_chk CHECK (
        status IN ('starting','running','idle','failed','stopped')
    ),
    CONSTRAINT queue_worker_state_totals_chk CHECK (
        batches_total >= 0 AND claimed_total >= 0 AND succeeded_total >= 0
        AND retried_total >= 0 AND dead_total >= 0
    )
);

CREATE INDEX IF NOT EXISTS idx_queue_worker_state_heartbeat
    ON sitebuilder.queue_worker_state (heartbeat_at DESC);

CREATE TABLE IF NOT EXISTS sitebuilder.queue_worker_run (
    id BIGSERIAL PRIMARY KEY,
    worker_id VARCHAR(120) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    requested_limit INTEGER NOT NULL DEFAULT 0,
    claimed INTEGER NOT NULL DEFAULT 0,
    succeeded INTEGER NOT NULL DEFAULT 0,
    retried INTEGER NOT NULL DEFAULT 0,
    dead INTEGER NOT NULL DEFAULT 0,
    error_code VARCHAR(120) NULL,
    details_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ NULL,
    duration_ms BIGINT NULL,
    CONSTRAINT queue_worker_run_status_chk CHECK (
        status IN ('running','succeeded','partial','failed')
    ),
    CONSTRAINT queue_worker_run_counts_chk CHECK (
        requested_limit >= 0 AND claimed >= 0 AND succeeded >= 0
        AND retried >= 0 AND dead >= 0
    ),
    CONSTRAINT queue_worker_run_details_object_chk CHECK (
        jsonb_typeof(details_json) = 'object'
    )
);

CREATE INDEX IF NOT EXISTS idx_queue_worker_run_started
    ON sitebuilder.queue_worker_run (started_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_queue_worker_run_worker
    ON sitebuilder.queue_worker_run (worker_id, started_at DESC, id DESC);

COMMIT;

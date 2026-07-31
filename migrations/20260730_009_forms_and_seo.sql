/*
 * SiteBuilder — этап 20.
 * 1. SEO-настройки страниц.
 * 2. Приём и обработка заявок из блоков формы.
 */

BEGIN;

SELECT pg_advisory_xact_lock(761239, 20);

ALTER TABLE sitebuilder.page
    ADD COLUMN IF NOT EXISTS seo_json JSONB NOT NULL DEFAULT '{}'::jsonb;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'page_seo_json_chk'
          AND conrelid = 'sitebuilder.page'::regclass
    ) THEN
        ALTER TABLE sitebuilder.page
            ADD CONSTRAINT page_seo_json_chk
            CHECK (jsonb_typeof(seo_json) = 'object');
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS sitebuilder.form_submission (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL,
    page_id BIGINT NOT NULL,
    block_id BIGINT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    payload_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    meta_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    handled_by BIGINT NULL,
    handled_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT form_submission_status_chk CHECK (status IN ('new','in_progress','done','spam')),
    CONSTRAINT form_submission_payload_chk CHECK (jsonb_typeof(payload_json) = 'object'),
    CONSTRAINT form_submission_meta_chk CHECK (jsonb_typeof(meta_json) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_form_submission_site_created
    ON sitebuilder.form_submission (site_id, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_form_submission_page_created
    ON sitebuilder.form_submission (page_id, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_form_submission_block_created
    ON sitebuilder.form_submission (block_id, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_form_submission_status_created
    ON sitebuilder.form_submission (status, created_at DESC, id DESC);

COMMIT;

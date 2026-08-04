/*
 * SiteBuilder — этап 21.
 * Индивидуальные права пользователей на папки компонента «Диск».
 */

BEGIN;

SELECT pg_advisory_xact_lock(761239, 21);

CREATE TABLE IF NOT EXISTS sitebuilder.disk_folder_access (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL,
    block_id BIGINT NOT NULL,
    folder_id BIGINT NOT NULL,
    access_code VARCHAR(128) NOT NULL,
    role VARCHAR(16) NOT NULL,
    created_by BIGINT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by BIGINT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT disk_folder_access_role_chk
        CHECK (role IN ('DENY', 'VIEWER', 'EDITOR')),
    CONSTRAINT disk_folder_access_site_fk
        FOREIGN KEY (site_id) REFERENCES sitebuilder.site(id) ON DELETE CASCADE,
    CONSTRAINT disk_folder_access_block_fk
        FOREIGN KEY (block_id) REFERENCES sitebuilder.block(id) ON DELETE CASCADE,
    CONSTRAINT disk_folder_access_unique
        UNIQUE (block_id, folder_id, access_code)
);

CREATE INDEX IF NOT EXISTS disk_folder_access_lookup_idx
    ON sitebuilder.disk_folder_access (block_id, access_code, folder_id);

CREATE INDEX IF NOT EXISTS disk_folder_access_site_idx
    ON sitebuilder.disk_folder_access (site_id, block_id, folder_id);

COMMIT;

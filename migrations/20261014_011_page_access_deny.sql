/* Explicit denial. Existing rows keep their original permissive semantics. */
BEGIN;
SELECT pg_advisory_xact_lock(761239, 22);
ALTER TABLE sitebuilder.page_access
    ADD COLUMN IF NOT EXISTS deny_view BOOLEAN NOT NULL DEFAULT FALSE;
COMMENT ON COLUMN sitebuilder.page_access.deny_view IS
    'Explicit Sitebuilder page denial. Includes Disk on the page; protected administrators bypass it.';
COMMIT;

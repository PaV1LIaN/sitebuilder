/* Local Sitebuilder groups. No changes to Bitrix groups or personal ACL. */
BEGIN;
SELECT pg_advisory_xact_lock(761239, 25);
CREATE TABLE IF NOT EXISTS sitebuilder.access_group (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(160) NOT NULL CHECK (length(btrim(name)) > 0),
    version BIGINT NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by BIGINT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS access_group_name_uq ON sitebuilder.access_group(lower(name));
CREATE TABLE IF NOT EXISTS sitebuilder.access_group_member (
    group_id BIGINT NOT NULL REFERENCES sitebuilder.access_group(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL CHECK (user_id > 0),
    added_by BIGINT NOT NULL,
    added_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY(group_id,user_id)
);
CREATE INDEX IF NOT EXISTS access_group_member_user_idx ON sitebuilder.access_group_member(user_id,group_id);
CREATE TABLE IF NOT EXISTS sitebuilder.access_group_rule (
    id BIGSERIAL PRIMARY KEY,
    group_id BIGINT NOT NULL REFERENCES sitebuilder.access_group(id) ON DELETE CASCADE,
    site_id BIGINT NOT NULL REFERENCES sitebuilder.site(id) ON DELETE CASCADE,
    kind VARCHAR(10) NOT NULL CHECK (kind IN ('site','page','disk','folder')),
    page_id BIGINT NOT NULL DEFAULT 0,
    block_id BIGINT NOT NULL DEFAULT 0,
    folder_id BIGINT NOT NULL DEFAULT 0,
    root_folder_id BIGINT NOT NULL DEFAULT 0,
    permission_mode VARCHAR(20) NOT NULL DEFAULT 'local',
    can_view BOOLEAN NOT NULL DEFAULT TRUE,
    can_edit BOOLEAN NOT NULL DEFAULT FALSE,
    can_delete BOOLEAN NOT NULL DEFAULT FALSE,
    include_children BOOLEAN NOT NULL DEFAULT TRUE,
    open_page BOOLEAN NOT NULL DEFAULT FALSE,
    title TEXT NOT NULL,
    updated_by BIGINT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT access_group_rule_scope_chk CHECK (
        (kind='site' AND page_id=0 AND block_id=0 AND folder_id=0 AND root_folder_id=0 AND permission_mode='local') OR
        (kind='page' AND page_id>0 AND block_id=0 AND folder_id=0 AND root_folder_id=0 AND permission_mode='local') OR
        (kind IN ('disk','folder') AND page_id>0 AND block_id>0 AND folder_id>0 AND root_folder_id>0
         AND permission_mode IN ('custom','inherit_site','bitrix_disk'))
    ),
    CONSTRAINT access_group_rule_capability_chk CHECK (can_view AND (kind IN ('disk','folder') OR (NOT can_delete AND NOT open_page))),
    UNIQUE(group_id,site_id,kind,page_id,block_id,folder_id)
);
CREATE INDEX IF NOT EXISTS access_group_rule_site_idx ON sitebuilder.access_group_rule(site_id,group_id);
CREATE INDEX IF NOT EXISTS access_group_audit_request_idx ON sitebuilder.audit_log(request_id,actor_user_id)
    WHERE action LIKE 'accessGroup.%';
COMMIT;

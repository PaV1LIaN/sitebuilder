<?php

class BlockRepository
{
    public static function getById(int $blockId): ?array
    {
        if ($blockId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                b.id,
                b.site_id,
                b.page_id,
                b.type,
                b.sort,
                b.settings_json,
                b.is_active,
                b.created_by,
                b.created_at,
                b.updated_at
            FROM sitebuilder.sitebuilder_block b
            WHERE b.id = :id
            LIMIT 1
        ";

        return DiskDb::fetchOne($sql, [
            ':id' => $blockId,
        ]);
    }

    public static function getDiskBlockByContext(int $siteId, int $pageId, int $blockId): ?array
    {
        if ($siteId <= 0 || $pageId <= 0 || $blockId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                b.id,
                b.site_id,
                b.page_id,
                b.type,
                b.sort,
                b.settings_json,
                b.is_active,
                b.created_by,
                b.created_at,
                b.updated_at
            FROM sitebuilder.sitebuilder_block b
            WHERE b.id = :block_id
              AND b.site_id = :site_id
              AND b.page_id = :page_id
              AND b.type = 'disk'
              AND b.is_active = 1
            LIMIT 1
        ";

        return DiskDb::fetchOne($sql, [
            ':block_id' => $blockId,
            ':site_id' => $siteId,
            ':page_id' => $pageId,
        ]);
    }

    public static function create(array $data): int
    {
        $sql = "
            INSERT INTO sitebuilder.sitebuilder_block (
                site_id,
                page_id,
                type,
                sort,
                settings_json,
                is_active,
                created_by,
                created_at,
                updated_at
            ) VALUES (
                :site_id,
                :page_id,
                :type,
                :sort,
                :settings_json,
                :is_active,
                :created_by,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ";

        DiskDb::execute($sql, [
            ':site_id' => (int)$data['site_id'],
            ':page_id' => (int)$data['page_id'],
            ':type' => (string)$data['type'],
            ':sort' => (int)($data['sort'] ?? 500),
            ':settings_json' => (string)($data['settings_json'] ?? '{}'),
            ':is_active' => (int)($data['is_active'] ?? 1),
            ':created_by' => isset($data['created_by']) ? (int)$data['created_by'] : null,
        ]);

        return (int)DiskDb::lastInsertId();
    }

    public static function updateSettingsJson(int $blockId, array $settings): bool
    {
        $sql = "
            UPDATE sitebuilder.sitebuilder_block
            SET settings_json = :settings_json,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";

        return DiskDb::execute($sql, [
            ':id' => $blockId,
            ':settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
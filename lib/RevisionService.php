<?php

require_once __DIR__ . '/IdSequenceService.php';

final class SiteBuilderVersionConflictException extends RuntimeException
{
    private string $entityType;
    private int $entityId;
    private int $expectedVersion;
    private int $currentVersion;

    public function __construct(string $entityType, int $entityId, int $expectedVersion, int $currentVersion)
    {
        parent::__construct('VERSION_CONFLICT');
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->expectedVersion = $expectedVersion;
        $this->currentVersion = $currentVersion;
    }

    public function context(): array
    {
        return [
            'entityType' => $this->entityType,
            'entityId' => $this->entityId,
            'expectedVersion' => $this->expectedVersion,
            'currentVersion' => $this->currentVersion,
        ];
    }
}

final class RevisionService
{
    public const ENTITY_SITE = 'site';
    public const ENTITY_PAGE = 'page';
    public const ENTITY_BLOCK = 'block';
    public const ENTITY_MENU = 'menu';
    public const ENTITY_LAYOUT = 'layout';

    private const ENTITY_TYPES = [
        self::ENTITY_SITE,
        self::ENTITY_PAGE,
        self::ENTITY_BLOCK,
        self::ENTITY_MENU,
        self::ENTITY_LAYOUT,
    ];

    public static function normalizeEntityType(string $entityType): string
    {
        $entityType = strtolower(trim($entityType));

        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            throw new InvalidArgumentException('INVALID_ENTITY_TYPE');
        }

        return $entityType;
    }

    public static function requireExpectedVersion(mixed $value): int
    {
        $version = (int)$value;
        if ($version <= 0) {
            throw new InvalidArgumentException('EXPECTED_VERSION_REQUIRED');
        }
        return $version;
    }

    public static function decodeVersionMap(mixed $value): array
    {
        if (is_array($value)) {
            $raw = $value;
        } elseif ($value === null || $value === '') {
            return [];
        } else {
            $raw = json_decode((string)$value, true);
            if (!is_array($raw)) {
                throw new InvalidArgumentException('BAD_VERSION_MAP');
            }
        }

        $result = [];
        foreach ($raw as $id => $version) {
            $entityId = (int)$id;
            $entityVersion = (int)$version;
            if ($entityId > 0 && $entityVersion > 0) {
                $result[$entityId] = $entityVersion;
            }
        }
        return $result;
    }

    public static function requireVersionFromMap(array $versionMap, int $entityId): int
    {
        if ($entityId <= 0 || !array_key_exists($entityId, $versionMap)) {
            throw new InvalidArgumentException('EXPECTED_VERSION_REQUIRED');
        }
        return self::requireExpectedVersion($versionMap[$entityId]);
    }

    public static function nextEntityId(string $entityType): int
    {
        $entityType = self::normalizeEntityType($entityType);
        if (!in_array($entityType, [self::ENTITY_SITE, self::ENTITY_PAGE, self::ENTITY_BLOCK, self::ENTITY_MENU], true)) {
            throw new InvalidArgumentException('ENTITY_ID_SEQUENCE_NOT_SUPPORTED');
        }
        return IdSequenceService::next($entityType);
    }

    /** @return int[] */
    public static function reserveEntityIds(string $entityType, int $count): array
    {
        $entityType = self::normalizeEntityType($entityType);
        if (!in_array($entityType, [self::ENTITY_SITE, self::ENTITY_PAGE, self::ENTITY_BLOCK, self::ENTITY_MENU], true)) {
            throw new InvalidArgumentException('ENTITY_ID_SEQUENCE_NOT_SUPPORTED');
        }
        return IdSequenceService::reserve($entityType, $count);
    }

    public static function assertExpected(array $entity, int $expectedVersion, string $entityType): void
    {
        $entityType = self::normalizeEntityType($entityType);
        $entityId = self::entityId($entityType, $entity);
        $currentVersion = max(1, (int)($entity['version'] ?? 1));

        if ($expectedVersion !== $currentVersion) {
            throw new SiteBuilderVersionConflictException(
                $entityType,
                $entityId,
                $expectedVersion,
                $currentVersion
            );
        }
    }

    private static function entityId(string $entityType, array $entity): int
    {
        if ($entityType === self::ENTITY_LAYOUT) {
            return (int)($entity['siteId'] ?? $entity['id'] ?? 0);
        }
        return (int)($entity['id'] ?? 0);
    }

    public static function getSite(int $siteId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT id,name,slug,section_id,home_page_id,disk_folder_id,top_menu_id,bitrix_group_id,bitrix_group_created_by,bitrix_group_created_at,settings_json,layout_json,created_by,created_at,updated_by,updated_at,version FROM sitebuilder.site WHERE id = :id";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $row = sb_db_fetch_one($sql, [':id' => $siteId]);
        return $row ? sb_map_site_row($row) : null;
    }

    public static function getPage(int $pageId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT id,site_id,title,slug,parent_id,sort,status,published_at,seo_json,created_by,created_at,updated_by,updated_at,version FROM sitebuilder.page WHERE id = :id";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $row = sb_db_fetch_one($sql, [':id' => $pageId]);
        return $row ? sb_map_page_row($row) : null;
    }

    public static function getBlock(int $blockId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT id,page_id,type,sort,content_json,props_json,created_by,created_at,updated_by,updated_at,version FROM sitebuilder.block WHERE id = :id";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $row = sb_db_fetch_one($sql, [':id' => $blockId]);
        return $row ? sb_map_block_row($row) : null;
    }

    public static function getMenu(int $menuId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT id,site_id,name,items_json,created_by,created_at,updated_by,updated_at,version FROM sitebuilder.menu WHERE id = :id";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $row = sb_db_fetch_one($sql, [':id' => $menuId]);
        return $row ? sb_map_menu_row($row) : null;
    }

    public static function getLayout(int $siteId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT site_id,settings_json,zones_json,created_by,created_at,updated_by,updated_at,version FROM sitebuilder.layout WHERE site_id = :site_id";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $row = sb_db_fetch_one($sql, [':site_id' => $siteId]);
        return $row ? sb_map_layout_row($row) : null;
    }

    public static function saveSite(array $site, int $expectedVersion, int $userId, string $operation, ?int $restoredFromRevisionId = null): array
    {
        $siteId = (int)($site['id'] ?? 0);
        $current = self::getSite($siteId, true);
        if (!$current) throw new RuntimeException('SITE_NOT_FOUND');
        self::assertExpected($current, $expectedVersion, self::ENTITY_SITE);

        $stmt = sb_db()->prepare("\n            UPDATE sitebuilder.site SET\n                name=:name, slug=:slug, section_id=:section_id, home_page_id=:home_page_id,\n                disk_folder_id=:disk_folder_id, top_menu_id=:top_menu_id,\n                bitrix_group_id=:bitrix_group_id, bitrix_group_created_by=:bitrix_group_created_by,\n                bitrix_group_created_at=:bitrix_group_created_at,\n                settings_json=CAST(:settings_json AS jsonb), layout_json=CAST(:layout_json AS jsonb),\n                updated_by=:updated_by, updated_at=NOW(), version=version+1\n            WHERE id=:id AND version=:expected_version\n            RETURNING id,name,slug,section_id,home_page_id,disk_folder_id,top_menu_id,bitrix_group_id,bitrix_group_created_by,bitrix_group_created_at,settings_json,layout_json,created_by,created_at,updated_by,updated_at,version\n        ");
        $stmt->execute([
            ':name' => (string)($site['name'] ?? $current['name']),
            ':slug' => (string)($site['slug'] ?? $current['slug']),
            ':section_id' => !empty($site['sectionId']) ? (int)$site['sectionId'] : null,
            ':home_page_id' => !empty($site['homePageId']) ? (int)$site['homePageId'] : null,
            ':disk_folder_id' => !empty($site['diskFolderId']) ? (int)$site['diskFolderId'] : null,
            ':top_menu_id' => !empty($site['topMenuId']) ? (int)$site['topMenuId'] : null,
            ':bitrix_group_id' => !empty($site['bitrixGroupId']) ? (int)$site['bitrixGroupId'] : null,
            ':bitrix_group_created_by' => !empty($site['bitrixGroupCreatedBy']) ? (int)$site['bitrixGroupCreatedBy'] : null,
            ':bitrix_group_created_at' => !empty($site['bitrixGroupCreatedAt']) ? (string)$site['bitrixGroupCreatedAt'] : null,
            ':settings_json' => json_encode(is_array($site['settings'] ?? null) ? $site['settings'] : $current['settings'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':layout_json' => json_encode(is_array($site['layout'] ?? null) ? $site['layout'] : $current['layout'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':updated_by' => $userId,
            ':id' => $siteId,
            ':expected_version' => $expectedVersion,
        ]);
        $row = $stmt->fetch();
        if (!$row) self::throwLatestConflict(self::ENTITY_SITE, $siteId, $expectedVersion);
        $saved = sb_map_site_row($row);
        self::recordSite($saved, $operation, $userId, $restoredFromRevisionId);
        return $saved;
    }

    public static function savePage(array $page, int $expectedVersion, int $userId, string $operation, ?int $restoredFromRevisionId = null): array
    {
        $pageId = (int)($page['id'] ?? 0);
        $current = self::getPage($pageId, true);
        if (!$current) throw new RuntimeException('PAGE_NOT_FOUND');
        self::assertExpected($current, $expectedVersion, self::ENTITY_PAGE);
        $stmt = sb_db()->prepare("UPDATE sitebuilder.page SET title=:title,slug=:slug,parent_id=:parent_id,sort=:sort,status=:status,published_at=:published_at,seo_json=CAST(:seo_json AS jsonb),updated_by=:updated_by,updated_at=NOW(),version=version+1 WHERE id=:id AND version=:expected_version RETURNING id,site_id,title,slug,parent_id,sort,status,published_at,seo_json,created_by,created_at,updated_by,updated_at,version");
        $stmt->execute([
            ':title'=>(string)($page['title']??$current['title']), ':slug'=>(string)($page['slug']??$current['slug']),
            ':parent_id'=>!empty($page['parentId'])?(int)$page['parentId']:null, ':sort'=>(int)($page['sort']??$current['sort']),
            ':status'=>(string)($page['status']??$current['status']), ':published_at'=>!empty($page['publishedAt'])?(string)$page['publishedAt']:null,
            ':seo_json'=>json_encode(is_array($page['seo']??null)?$page['seo']:$current['seo'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':updated_by'=>$userId, ':id'=>$pageId, ':expected_version'=>$expectedVersion,
        ]);
        $row=$stmt->fetch(); if(!$row) self::throwLatestConflict(self::ENTITY_PAGE,$pageId,$expectedVersion);
        $saved=sb_map_page_row($row); self::recordPage($saved,$operation,$userId,$restoredFromRevisionId); return $saved;
    }

    public static function saveBlock(array $block, int $expectedVersion, int $userId, string $operation, ?int $restoredFromRevisionId = null): array
    {
        $blockId=(int)($block['id']??0); $current=self::getBlock($blockId,true); if(!$current) throw new RuntimeException('BLOCK_NOT_FOUND');
        self::assertExpected($current,$expectedVersion,self::ENTITY_BLOCK);
        $stmt=sb_db()->prepare("UPDATE sitebuilder.block SET page_id=:page_id,type=:type,sort=:sort,content_json=CAST(:content_json AS jsonb),props_json=CAST(:props_json AS jsonb),updated_by=:updated_by,updated_at=NOW(),version=version+1 WHERE id=:id AND version=:expected_version RETURNING id,page_id,type,sort,content_json,props_json,created_by,created_at,updated_by,updated_at,version");
        $stmt->execute([
            ':page_id'=>(int)($block['pageId']??$current['pageId']), ':type'=>(string)($block['type']??$current['type']), ':sort'=>(int)($block['sort']??$current['sort']),
            ':content_json'=>json_encode(is_array($block['content']??null)?$block['content']:$current['content'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':props_json'=>json_encode(is_array($block['props']??null)?$block['props']:$current['props'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':updated_by'=>$userId, ':id'=>$blockId, ':expected_version'=>$expectedVersion,
        ]);
        $row=$stmt->fetch(); if(!$row) self::throwLatestConflict(self::ENTITY_BLOCK,$blockId,$expectedVersion);
        $saved=sb_map_block_row($row); self::recordBlock($saved,$operation,$userId,$restoredFromRevisionId); return $saved;
    }

    public static function saveMenu(array $menu, int $expectedVersion, int $userId, string $operation, ?int $restoredFromRevisionId = null): array
    {
        $menuId=(int)($menu['id']??0); $current=self::getMenu($menuId,true); if(!$current) throw new RuntimeException('MENU_NOT_FOUND');
        self::assertExpected($current,$expectedVersion,self::ENTITY_MENU);
        $stmt=sb_db()->prepare("UPDATE sitebuilder.menu SET site_id=:site_id,name=:name,items_json=CAST(:items_json AS jsonb),updated_by=:updated_by,updated_at=NOW(),version=version+1 WHERE id=:id AND version=:expected_version RETURNING id,site_id,name,items_json,created_by,created_at,updated_by,updated_at,version");
        $stmt->execute([
            ':site_id'=>(int)($menu['siteId']??$current['siteId']), ':name'=>(string)($menu['name']??$current['name']),
            ':items_json'=>json_encode(array_values(is_array($menu['items']??null)?$menu['items']:$current['items']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':updated_by'=>$userId, ':id'=>$menuId, ':expected_version'=>$expectedVersion,
        ]);
        $row=$stmt->fetch(); if(!$row) self::throwLatestConflict(self::ENTITY_MENU,$menuId,$expectedVersion);
        $saved=sb_map_menu_row($row); self::recordMenu($saved,$operation,$userId,$restoredFromRevisionId); return $saved;
    }

    public static function saveLayout(array $layout, int $expectedVersion, int $userId, string $operation, ?int $restoredFromRevisionId = null): array
    {
        $siteId=(int)($layout['siteId']??0); $current=self::getLayout($siteId,true); if(!$current) throw new RuntimeException('LAYOUT_NOT_FOUND');
        self::assertExpected($current,$expectedVersion,self::ENTITY_LAYOUT);
        $stmt=sb_db()->prepare("UPDATE sitebuilder.layout SET settings_json=CAST(:settings_json AS jsonb),zones_json=CAST(:zones_json AS jsonb),updated_by=:updated_by,updated_at=NOW(),version=version+1 WHERE site_id=:site_id AND version=:expected_version RETURNING site_id,settings_json,zones_json,created_by,created_at,updated_by,updated_at,version");
        $stmt->execute([
            ':settings_json'=>json_encode(is_array($layout['settings']??null)?$layout['settings']:$current['settings'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':zones_json'=>json_encode(is_array($layout['zones']??null)?$layout['zones']:$current['zones'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':updated_by'=>$userId, ':site_id'=>$siteId, ':expected_version'=>$expectedVersion,
        ]);
        $row=$stmt->fetch(); if(!$row) self::throwLatestConflict(self::ENTITY_LAYOUT,$siteId,$expectedVersion);
        $saved=sb_map_layout_row($row); self::recordLayout($saved,$operation,$userId,$restoredFromRevisionId); return $saved;
    }

    private static function throwLatestConflict(string $entityType, int $entityId, int $expectedVersion): never
    {
        $latest = match ($entityType) {
            self::ENTITY_SITE => self::getSite($entityId),
            self::ENTITY_PAGE => self::getPage($entityId),
            self::ENTITY_BLOCK => self::getBlock($entityId),
            self::ENTITY_MENU => self::getMenu($entityId),
            self::ENTITY_LAYOUT => self::getLayout($entityId),
            default => null,
        };
        throw new SiteBuilderVersionConflictException($entityType,$entityId,$expectedVersion,(int)($latest['version']??0));
    }

    public static function recordSite(array $site,string $operation,int $userId,?int $restoredFromRevisionId=null): int
    { return self::record(self::ENTITY_SITE,(int)$site['id'],(int)$site['id'],null,(int)($site['version']??1),$operation,$site,$userId,$restoredFromRevisionId); }
    public static function recordPage(array $page,string $operation,int $userId,?int $restoredFromRevisionId=null): int
    { return self::record(self::ENTITY_PAGE,(int)$page['id'],(int)$page['siteId'],(int)$page['id'],(int)($page['version']??1),$operation,$page,$userId,$restoredFromRevisionId); }
    public static function recordBlock(array $block,string $operation,int $userId,?int $restoredFromRevisionId=null): int
    { $page=self::getPage((int)$block['pageId']); $siteId=(int)($page['siteId']??0); if($siteId<=0) throw new RuntimeException('BLOCK_SITE_NOT_FOUND'); return self::record(self::ENTITY_BLOCK,(int)$block['id'],$siteId,(int)$block['pageId'],(int)($block['version']??1),$operation,$block,$userId,$restoredFromRevisionId); }
    public static function recordMenu(array $menu,string $operation,int $userId,?int $restoredFromRevisionId=null): int
    { return self::record(self::ENTITY_MENU,(int)$menu['id'],(int)$menu['siteId'],null,(int)($menu['version']??1),$operation,$menu,$userId,$restoredFromRevisionId); }
    public static function recordLayout(array $layout,string $operation,int $userId,?int $restoredFromRevisionId=null): int
    { return self::record(self::ENTITY_LAYOUT,(int)$layout['siteId'],(int)$layout['siteId'],null,(int)($layout['version']??1),$operation,$layout,$userId,$restoredFromRevisionId); }

    public static function recordDeletedPage(array $page,int $userId): int { return self::recordPage($page,'delete',$userId); }
    public static function recordDeletedBlock(array $block,int $userId): int { return self::recordBlock($block,'delete',$userId); }
    public static function recordDeletedMenu(array $menu,int $userId): int { return self::recordMenu($menu,'delete',$userId); }

    private static function record(string $entityType,int $entityId,int $siteId,?int $pageId,int $entityVersion,string $operation,array $snapshot,int $userId,?int $restoredFromRevisionId): int
    {
        $stmt=sb_db()->prepare("INSERT INTO sitebuilder.entity_revision (site_id,entity_type,entity_id,page_id,entity_version,operation,snapshot_json,created_by,restored_from_revision_id) VALUES (:site_id,:entity_type,:entity_id,:page_id,:entity_version,:operation,CAST(:snapshot_json AS jsonb),:created_by,:restored_from_revision_id) RETURNING id");
        $stmt->execute([
            ':site_id'=>$siteId, ':entity_type'=>self::normalizeEntityType($entityType), ':entity_id'=>$entityId, ':page_id'=>$pageId,
            ':entity_version'=>max(1,$entityVersion), ':operation'=>trim($operation)!==''?trim($operation):'update',
            ':snapshot_json'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':created_by'=>$userId>0?$userId:null, ':restored_from_revision_id'=>$restoredFromRevisionId,
        ]);
        return (int)$stmt->fetchColumn();
    }

    public static function list(string $entityType,int $entityId,int $limit=50,int $offset=0): array
    {
        $entityType=self::normalizeEntityType($entityType); $limit=max(1,min(100,$limit)); $offset=max(0,$offset);
        $stmt=sb_db()->prepare("SELECT id,site_id,entity_type,entity_id,page_id,entity_version,operation,created_by,created_at,restored_from_revision_id FROM sitebuilder.entity_revision WHERE entity_type=:entity_type AND entity_id=:entity_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':entity_type',$entityType,PDO::PARAM_STR); $stmt->bindValue(':entity_id',$entityId,PDO::PARAM_INT); $stmt->bindValue(':limit',$limit,PDO::PARAM_INT); $stmt->bindValue(':offset',$offset,PDO::PARAM_INT); $stmt->execute();
        return array_map([self::class,'mapRevisionRow'],$stmt->fetchAll());
    }

    public static function getRevision(int $revisionId): ?array
    {
        $row=sb_db_fetch_one("SELECT id,site_id,entity_type,entity_id,page_id,entity_version,operation,snapshot_json,created_by,created_at,restored_from_revision_id FROM sitebuilder.entity_revision WHERE id=:id",[':id'=>$revisionId]);
        return $row?self::mapRevisionRow($row,true):null;
    }

    private static function mapRevisionRow(array $row,bool $includeSnapshot=false): array
    {
        $result=[
            'id'=>(int)$row['id'],'siteId'=>(int)$row['site_id'],'entityType'=>(string)$row['entity_type'],'entityId'=>(int)$row['entity_id'],
            'pageId'=>!empty($row['page_id'])?(int)$row['page_id']:0,'version'=>(int)$row['entity_version'],'operation'=>(string)$row['operation'],
            'createdBy'=>!empty($row['created_by'])?(int)$row['created_by']:0,'createdAt'=>(string)$row['created_at'],
            'restoredFromRevisionId'=>!empty($row['restored_from_revision_id'])?(int)$row['restored_from_revision_id']:0,
        ];
        if($includeSnapshot) $result['snapshot']=sb_json_decode_assoc($row['snapshot_json']??'{}');
        return $result;
    }
}

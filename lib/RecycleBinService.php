<?php

require_once __DIR__ . '/RevisionService.php';
require_once __DIR__ . '/PageSectionRepository.php';
require_once __DIR__ . '/PageAccessRepository.php';

final class RecycleBinService
{
    public const TYPE_PAGE_TREE = 'page_tree';

    public static function storePageTree(
        int $siteId,
        int $rootPageId,
        string $title,
        array $snapshot,
        int $userId
    ): int {
        if ($siteId <= 0 || $rootPageId <= 0) {
            throw new InvalidArgumentException('RECYCLE_CONTEXT_REQUIRED');
        }

        $stmt = sb_db()->prepare("\n            INSERT INTO sitebuilder.recycle_bin (\n                site_id, entity_type, root_entity_id, title, snapshot_json, deleted_by\n            ) VALUES (\n                :site_id, :entity_type, :root_entity_id, :title, CAST(:snapshot_json AS jsonb), :deleted_by\n            )\n            RETURNING id\n        ");
        $stmt->execute([
            ':site_id' => $siteId,
            ':entity_type' => self::TYPE_PAGE_TREE,
            ':root_entity_id' => $rootPageId,
            ':title' => trim($title),
            ':snapshot_json' => json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            ':deleted_by' => $userId > 0 ? $userId : null,
        ]);

        return (int)$stmt->fetchColumn();
    }

    public static function listActive(int $siteId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = sb_db()->prepare("\n            SELECT id,site_id,entity_type,root_entity_id,title,deleted_by,deleted_at,restored_by,restored_at\n            FROM sitebuilder.recycle_bin\n            WHERE site_id=:site_id AND restored_at IS NULL\n            ORDER BY id DESC\n            LIMIT :limit\n        ");
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'mapRow'], $stmt->fetchAll());
    }

    public static function get(int $id, bool $includeSnapshot = true, bool $forUpdate = false): ?array
    {
        $fields = 'id,site_id,entity_type,root_entity_id,title,deleted_by,deleted_at,restored_by,restored_at';
        if ($includeSnapshot) $fields .= ',snapshot_json';
        $sql = "SELECT {$fields} FROM sitebuilder.recycle_bin WHERE id=:id";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $row = sb_db_fetch_one($sql, [':id' => $id]);
        return $row ? self::mapRow($row, $includeSnapshot) : null;
    }

    public static function restore(int $id, int $userId): array
    {
        $item = self::get($id, true, true);
        if (!$item) throw new RuntimeException('RECYCLE_ITEM_NOT_FOUND');
        if (!empty($item['restoredAt'])) throw new RuntimeException('RECYCLE_ITEM_ALREADY_RESTORED');
        if ($item['entityType'] !== self::TYPE_PAGE_TREE) throw new RuntimeException('RECYCLE_TYPE_NOT_SUPPORTED');

        $siteId = (int)$item['siteId'];
        $site = RevisionService::getSite($siteId, true);
        if (!$site) throw new RuntimeException('SITE_NOT_FOUND');

        $snapshot = is_array($item['snapshot'] ?? null) ? $item['snapshot'] : [];
        $pages = array_values(array_filter($snapshot['pages'] ?? [], 'is_array'));
        $blocks = array_values(array_filter($snapshot['blocks'] ?? [], 'is_array'));
        $sections = array_values(array_filter($snapshot['sections'] ?? [], 'is_array'));
        $accessRows = array_values(array_filter($snapshot['pageAccess'] ?? [], 'is_array'));
        $removedMenuItems = array_values(array_filter($snapshot['removedMenuItems'] ?? [], 'is_array'));

        if (empty($pages)) throw new RuntimeException('RECYCLE_SNAPSHOT_EMPTY');

        $pageIds = array_values(array_unique(array_map(static fn(array $p): int => (int)($p['id'] ?? 0), $pages)));
        $blockIds = array_values(array_unique(array_map(static fn(array $b): int => (int)($b['id'] ?? 0), $blocks)));
        if (in_array(0, $pageIds, true)) throw new RuntimeException('RECYCLE_PAGE_ID_INVALID');

        self::assertIdsAvailable('sitebuilder.page', $pageIds);
        self::assertIdsAvailable('sitebuilder.block', array_values(array_filter($blockIds)));

        $existingSlugs = [];
        foreach (sb_db_fetch_all('SELECT slug FROM sitebuilder.page WHERE site_id=:site_id', [':site_id'=>$siteId]) as $row) {
            $existingSlugs[(string)$row['slug']] = true;
        }

        $pageIdMap = array_fill_keys($pageIds, true);
        $pendingPages = [];
        foreach ($pages as $page) {
            $pendingPages[(int)$page['id']] = $page;
        }

        uasort($pendingPages, static function (array $a, array $b): int {
            $sortCompare = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
            return $sortCompare !== 0
                ? $sortCompare
                : (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        $restoredPages = [];
        $restoredPageIds = [];

        while (!empty($pendingPages)) {
            $madeProgress = false;

            foreach ($pendingPages as $pageId => $page) {
                $parentId = (int)($page['parentId'] ?? 0);

                /*
                 * Родитель, находящийся в том же снимке, должен быть
                 * восстановлен раньше дочерней страницы. Это исключает
                 * нарушение внешнего ключа и выявляет повреждённые циклы.
                 */
                if (
                    $parentId > 0
                    && isset($pageIdMap[$parentId])
                    && !isset($restoredPageIds[$parentId])
                ) {
                    continue;
                }

                if ($parentId > 0 && !isset($pageIdMap[$parentId])) {
                    $parent = RevisionService::getPage($parentId, false);
                    if (!$parent || (int)$parent['siteId'] !== $siteId) {
                        $parentId = 0;
                    }
                }

                $slug = self::uniqueSlug(
                    (string)($page['slug'] ?? ('page-' . $pageId)),
                    $pageId,
                    $existingSlugs
                );
                $version = max(1, (int)($page['version'] ?? 1) + 1);
                $stmt = sb_db()->prepare("
                    INSERT INTO sitebuilder.page (
                        id,site_id,title,slug,parent_id,sort,status,published_at,seo_json,
                        created_by,created_at,updated_by,updated_at,version
                    ) VALUES (
                        :id,:site_id,:title,:slug,:parent_id,:sort,:status,:published_at,CAST(:seo_json AS jsonb),
                        :created_by,:created_at,:updated_by,NOW(),:version
                    )
                    RETURNING id,site_id,title,slug,parent_id,sort,status,published_at,seo_json,
                              created_by,created_at,updated_by,updated_at,version
                ");
                $stmt->execute([
                    ':id' => $pageId,
                    ':site_id' => $siteId,
                    ':title' => (string)($page['title'] ?? 'Страница'),
                    ':slug' => $slug,
                    ':parent_id' => $parentId > 0 ? $parentId : null,
                    ':sort' => (int)($page['sort'] ?? 500),
                    ':status' => (string)($page['status'] ?? 'draft'),
                    ':published_at' => !empty($page['publishedAt'])
                        ? (string)$page['publishedAt']
                        : null,
                    ':seo_json' => json_encode(is_array($page['seo'] ?? null) ? $page['seo'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':created_by' => !empty($page['createdBy'])
                        ? (int)$page['createdBy']
                        : $userId,
                    ':created_at' => !empty($page['createdAt'])
                        ? (string)$page['createdAt']
                        : date('c'),
                    ':updated_by' => $userId,
                    ':version' => $version,
                ]);

                $saved = sb_map_page_row($stmt->fetch());
                RevisionService::recordPage($saved, 'recycle_restore', $userId);
                $restoredPages[] = $saved;
                $restoredPageIds[$pageId] = true;
                unset($pendingPages[$pageId]);
                $madeProgress = true;
            }

            if (!$madeProgress) {
                throw new RuntimeException('RECYCLE_PAGE_TREE_CYCLE');
            }
        }

        $sectionMap = PageSectionRepository::restoreSnapshots($sections, $userId);
        $restoredBlocks = [];
        foreach ($blocks as $block) {
            $blockId = (int)($block['id'] ?? 0);
            $pageId = (int)($block['pageId'] ?? 0);
            if ($blockId <= 0 || !isset($pageIdMap[$pageId])) continue;
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            self::remapSectionReferences($props, $sectionMap);
            $version = max(1, (int)($block['version'] ?? 1) + 1);
            $stmt=sb_db()->prepare("\n                INSERT INTO sitebuilder.block (id,page_id,type,sort,content_json,props_json,created_by,created_at,updated_by,updated_at,version)\n                VALUES (:id,:page_id,:type,:sort,CAST(:content AS jsonb),CAST(:props AS jsonb),:created_by,:created_at,:updated_by,NOW(),:version)\n                RETURNING id,page_id,type,sort,content_json,props_json,created_by,created_at,updated_by,updated_at,version\n            ");
            $stmt->execute([
                ':id'=>$blockId, ':page_id'=>$pageId, ':type'=>(string)($block['type']??'text'), ':sort'=>(int)($block['sort']??500),
                ':content'=>json_encode(is_array($block['content']??null)?$block['content']:[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                ':props'=>json_encode($props,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                ':created_by'=>!empty($block['createdBy'])?(int)$block['createdBy']:$userId,
                ':created_at'=>!empty($block['createdAt'])?(string)$block['createdAt']:date('c'), ':updated_by'=>$userId, ':version'=>$version,
            ]);
            $saved=sb_map_block_row($stmt->fetch());
            RevisionService::recordBlock($saved,'recycle_restore',$userId);
            $restoredBlocks[]=$saved;
        }

        foreach ($accessRows as $access) {
            $pageId = (int)($access['pageId'] ?? 0);
            if (!isset($pageIdMap[$pageId])) {
                continue;
            }

            PageAccessRepository::restoreIfMissing(
                $siteId,
                $pageId,
                (string)($access['accessCode'] ?? ''),
                (bool)($access['canView'] ?? false),
                (bool)($access['canEdit'] ?? false),
                (bool)($access['includeChildren'] ?? false),
                $userId,
                (bool)($access['canDiskView'] ?? false),
                (bool)($access['canDiskEdit'] ?? false)
            );
        }

        foreach ($removedMenuItems as $menuSnapshot) {
            $menuId=(int)($menuSnapshot['menuId']??0);
            $menu=$menuId>0?RevisionService::getMenu($menuId,false):null;
            if(!$menu || (int)$menu['siteId']!==$siteId) continue;
            $items=array_values($menu['items']??[]);
            $usedItemIds=array_fill_keys(array_map(static fn(array $i):int=>(int)($i['id']??0),$items),true);
            foreach(array_values(array_filter($menuSnapshot['items']??[],'is_array')) as $removedItem){
                $pageId=(int)($removedItem['pageId']??0);
                if(!isset($pageIdMap[$pageId])) continue;
                $duplicate=false;
                foreach($items as $currentItem){if((string)($currentItem['type']??'')==='page' && (int)($currentItem['pageId']??0)===$pageId && (string)($currentItem['title']??'')===(string)($removedItem['title']??'')){$duplicate=true;break;}}
                if($duplicate)continue;
                $itemId=(int)($removedItem['id']??0);
                if($itemId<=0 || isset($usedItemIds[$itemId])){$itemId=sb_next_menu_item_id($items);}
                $removedItem['id']=$itemId;$usedItemIds[$itemId]=true;$items[]=$removedItem;
            }
            $menu['items']=$items;
            RevisionService::saveMenu($menu,(int)$menu['version'],$userId,'recycle_reference_restore');
        }

        if (!empty($snapshot['wasHomePage']) && (int)($site['homePageId'] ?? 0) === 0) {
            $site['homePageId'] = (int)$item['rootEntityId'];
            $site = RevisionService::saveSite($site,(int)$site['version'],$userId,'recycle_home_restore');
        }

        sb_db_execute("UPDATE sitebuilder.recycle_bin SET restored_by=:user_id,restored_at=NOW() WHERE id=:id AND restored_at IS NULL", [':user_id'=>$userId, ':id'=>$id]);

        return [
            'itemId'=>$id,'siteId'=>$siteId,'rootPageId'=>(int)$item['rootEntityId'],
            'pages'=>$restoredPages,'blocks'=>$restoredBlocks,'sectionIdMap'=>$sectionMap,
            'siteVersion'=>(int)$site['version'],
        ];
    }

    public static function purge(int $id): bool
    {
        $stmt=sb_db()->prepare('DELETE FROM sitebuilder.recycle_bin WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        return $stmt->rowCount()===1;
    }

    private static function assertIdsAvailable(string $table, array $ids): void
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
        if(empty($ids))return;
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $stmt=sb_db()->prepare("SELECT id FROM {$table} WHERE id IN ({$ph}) LIMIT 1");
        $stmt->execute($ids);
        if($stmt->fetchColumn()!==false) throw new RuntimeException('RECYCLE_ENTITY_ID_CONFLICT');
    }

    private static function uniqueSlug(string $slug,int $pageId,array &$used): string
    {
        $slug=trim($slug)!==''?trim($slug):'page-'.$pageId;
        if(!isset($used[$slug])){$used[$slug]=true;return $slug;}
        $base=$slug.'-restored-'.$pageId;$candidate=$base;$i=2;
        while(isset($used[$candidate])){$candidate=$base.'-'.$i++;}
        $used[$candidate]=true;return $candidate;
    }

    private static function remapSectionReferences(array &$props,array $map): void
    {
        foreach(['sectionId'] as $key){$old=(int)($props[$key]??0);if($old>0&&isset($map[$old]))$props[$key]=(int)$map[$old];}
        if(is_array($props['_placement']??null)){$old=(int)($props['_placement']['sectionId']??0);if($old>0&&isset($map[$old]))$props['_placement']['sectionId']=(int)$map[$old];}
    }

    private static function mapRow(array $row,bool $includeSnapshot=false): array
    {
        $result=[
            'id'=>(int)$row['id'],'siteId'=>(int)$row['site_id'],'entityType'=>(string)$row['entity_type'],
            'rootEntityId'=>(int)$row['root_entity_id'],'title'=>(string)($row['title']??''),
            'deletedBy'=>!empty($row['deleted_by'])?(int)$row['deleted_by']:0,'deletedAt'=>(string)$row['deleted_at'],
            'restoredBy'=>!empty($row['restored_by'])?(int)$row['restored_by']:0,'restoredAt'=>!empty($row['restored_at'])?(string)$row['restored_at']:'',
        ];
        if($includeSnapshot)$result['snapshot']=sb_json_decode_assoc($row['snapshot_json']??'{}');
        return $result;
    }
}

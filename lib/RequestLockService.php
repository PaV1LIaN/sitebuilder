<?php

require_once __DIR__ . '/db.php';


final class SiteBuilderResourceBusyException extends RuntimeException
{
    private array $lockContext;

    public function __construct(array $lockContext, ?Throwable $previous = null)
    {
        parent::__construct('RESOURCE_BUSY', 0, $previous);
        $this->lockContext = $lockContext;
    }

    public function context(): array
    {
        return $this->lockContext;
    }
}

/**
 * Точечные transaction-level advisory locks для изменяющих API-запросов.
 *
 * Обычные операции берут shared-блокировку сайта: они могут выполняться
 * параллельно в разных страницах/объектах одного сайта, но полное удаление
 * сайта получает exclusive-блокировку и ждёт завершения всех операций.
 *
 * Все дополнительные блокировки сортируются по namespace и resource ID,
 * чтобы несколько запросов захватывали их в одинаковом порядке.
 */
final class RequestLockService
{
    /** Координация жизненного цикла сайта. Shared для обычных операций, exclusive для удаления/снимка. */
    public const NS_SITE_LIFECYCLE = 761236;

    /** Жизненный цикл одной страницы. Shared для обычных операций, exclusive для согласованного снимка. */
    private const NS_PAGE_LIFECYCLE = 761299;

    /** Изменение строки sitebuilder.site. */
    private const NS_SITE_ENTITY = 761300;

    /** Иерархия страниц одного сайта. */
    private const NS_PAGE_TREE = 761301;

    /** Порядок и состав блоков одной страницы. */
    private const NS_PAGE_BLOCKS = 761302;

    /** Один menu JSON-документ. */
    private const NS_MENU = 761303;

    /** Layout сайта. */
    private const NS_LAYOUT = 761304;

    /** Секции одной страницы. Совпадает с PageSectionRepository::LOCK_NAMESPACE. */
    private const NS_PAGE_SECTIONS = 761242;

    /** Общий JSON-файл шаблонов. */
    private const NS_TEMPLATE_STORE = 761306;

    /** Точечные права одной страницы. */
    private const NS_PAGE_ACCESS = 761307;

    /** Коллекция разделов сайтов. */
    private const NS_SITE_SECTION = 761308;

    /** Отдельная запись корзины. */
    private const NS_TRASH = 761309;

    /** Отдельный блок. */
    private const NS_BLOCK = 761310;

    /** Восстановление одной ревизии. */
    private const NS_REVISION = 761311;

    /** Создание сайта и проверка уникальности slug. */
    private const NS_SITE_COLLECTION = 761312;

    /** Коллекция меню одного сайта. */
    private const NS_SITE_MENUS = 761313;

    /** Одна страница без изменения дерева. */
    private const NS_PAGE_ENTITY = 761314;

    /** Изменения папки Битрикс.Диска одного сайта. */
    private const NS_SITE_DISK = 761315;

    /** Управление отдельным заданием transactional outbox. */
    private const NS_OUTBOX_JOB = 761316;

    /** Системное оповещение. */
    private const NS_SYSTEM_ALERT = 761321;

    /** Сверка внешних ресурсов. */
    private const NS_EXTERNAL_RECONCILE = 761322;

    /** Внешняя группа-сирота. */
    private const NS_EXTERNAL_GROUP = 761323;

    /** Внешняя папка-сирота. */
    private const NS_EXTERNAL_FOLDER = 761324;

    /** Отдельная резервная копия. */
    private const NS_BACKUP = 761325;

    /** Согласованный запуск проверки целостности. */
    private const NS_INTEGRITY = 761326;

    /** Реестр резервных копий конкретного сайта. */
    private const NS_BACKUP_COLLECTION = 761327;


    public static function lockMutation(string $action, array $input): array
    {
        if (!sb_db()->inTransaction()) {
            throw new RuntimeException('REQUEST_LOCK_REQUIRES_TRANSACTION');
        }

        $locks = self::planMutation($action, $input);
        self::applyLockTimeout();
        self::acquire($locks, $action);

        $GLOBALS['SB_REQUEST_RESOURCE_LOCKS'] = $locks;
        return $locks;
    }

    /**
     * Возвращает нормализованный план без захвата блокировок.
     * Используется диагностикой и статическими тестами этапа 8.
     */
    public static function planMutation(string $action, array $input): array
    {
        return self::normalize(self::buildLocks($action, $input));
    }

    /**
     * Exclusive lifecycle lock используется SiteDeletionService.
     */
    public static function lockSiteExclusive(int $siteId): void
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('INVALID_SITE_ID');
        }

        self::applyLockTimeout();
        self::acquire([
            self::lock(self::NS_SITE_LIFECYCLE, $siteId, false, 'site.lifecycle'),
        ], 'site.delete');
    }

    private static function buildLocks(string $action, array $input): array
    {
        $locks = [];

        switch ($action) {
            case 'site.create':
                self::add($locks, self::NS_SITE_COLLECTION, 1, false, 'site.collection');
                self::add($locks, self::NS_SITE_SECTION, 1, true, 'site.section.collection');
                break;

            case 'site.update':
                self::add($locks, self::NS_SITE_COLLECTION, 1, false, 'site.collection');
                self::addSiteMutationLocks($locks, self::int($input, 'siteId'));
                break;

            case 'site.setHome':
            case 'site.ensureGroup':
            case 'site.appearanceUpdate':
            case 'site.appearanceUpload':
            case 'site.appearanceRemove':
                self::addSiteMutationLocks($locks, self::int($input, 'siteId'));
                break;

            case 'site.setSection':
                self::add($locks, self::NS_SITE_SECTION, 1, true, 'site.section.collection');
                self::addSiteMutationLocks($locks, self::int($input, 'siteId'));
                break;

            case 'site.syncAccess':
            case 'site.accessSet':
            case 'site.accessRemove':
                self::addSiteShared($locks, self::int($input, 'siteId'));
                break;

            case 'system.alert.ack':
            case 'system.alert.resolve':
                self::add($locks, self::NS_SYSTEM_ALERT, self::int($input, 'alertId'), false, 'system.alert');
                break;

            case 'external.reconcile.enqueue':
                $siteId = self::int($input, 'siteId');
                if ($siteId > 0) {
                    self::addSiteShared($locks, $siteId);
                }
                self::add($locks, self::NS_EXTERNAL_RECONCILE, $siteId > 0 ? $siteId : 1, false, 'external.reconcile');
                break;

            case 'external.resource.cleanup':
                $externalId = self::int($input, 'externalId');
                $type = trim((string)($input['resourceType'] ?? ''));
                self::add(
                    $locks,
                    $type === 'disk_folder' ? self::NS_EXTERNAL_FOLDER : self::NS_EXTERNAL_GROUP,
                    $externalId,
                    false,
                    'external.resource'
                );
                break;

            case 'job.retry':
            case 'job.cancel':
                self::add($locks, self::NS_OUTBOX_JOB, self::int($input, 'jobId'), false, 'outbox.job');
                break;

            case 'file.upload':
                $siteId = self::int($input, 'siteId');
                self::addSiteMutationLocks($locks, $siteId);
                self::add($locks, self::NS_SITE_DISK, $siteId, false, 'site.disk');
                break;

            case 'file.delete':
                $siteId = self::int($input, 'siteId');
                self::addSiteShared($locks, $siteId);
                self::add($locks, self::NS_SITE_DISK, $siteId, false, 'site.disk');
                break;

            case 'page.create':
                $siteId = self::int($input, 'siteId');
                self::addSiteShared($locks, $siteId);
                self::add($locks, self::NS_PAGE_TREE, $siteId, false, 'page.tree');
                break;

            case 'page.save':
            case 'page.updateMeta':
            case 'page.setParent':
            case 'page.move':
            case 'page.reorderTree':
                self::addPageTreeMutationLocks($locks, self::int($input, 'id'));
                break;

            case 'page.setStatus':
                self::addPageEntityLocks($locks, self::int($input, 'id'));
                break;

            case 'page.delete':
                self::addPageBroadMutationLocks($locks, self::int($input, 'id'));
                break;

            case 'page.duplicate':
                self::addPageDuplicateLocks($locks, self::int($input, 'id'));
                break;

            case 'block.create':
                self::addPageBlockCollectionLocks($locks, self::int($input, 'pageId'));
                if ((string)($input['type'] ?? '') === 'global') {
                    self::add($locks, self::NS_TEMPLATE_STORE, 1, true, 'global.block.definition');
                }
                break;

            case 'block.update':
            case 'block.delete':
                $blockId = self::int($input, 'id');
                self::addBlockEntityLocks($locks, $blockId);
                if (self::isGlobalBlock($blockId) || (string)($input['type'] ?? '') === 'global') {
                    self::add($locks, self::NS_TEMPLATE_STORE, 1, true, 'global.block.definition');
                }
                break;

            case 'block.duplicate':
                $blockId = self::int($input, 'id');
                self::addBlockCollectionFromBlockLocks($locks, $blockId);
                if (self::isGlobalBlock($blockId)) {
                    self::add($locks, self::NS_TEMPLATE_STORE, 1, true, 'global.block.definition');
                }
                break;

            case 'block.move':
                self::addBlockCollectionFromBlockLocks($locks, self::int($input, 'id'));
                break;

            case 'block.reorder':
                self::addPageBlockCollectionLocks($locks, self::int($input, 'pageId'));
                break;

            case 'menu.create':
                $siteId = self::int($input, 'siteId');
                self::addSiteShared($locks, $siteId);
                self::add($locks, self::NS_SITE_MENUS, $siteId, false, 'menu.collection');
                break;

            case 'menu.update':
            case 'menu.item.add':
            case 'menu.item.update':
            case 'menu.item.delete':
            case 'menu.item.move':
                self::addMenuLocks($locks, self::int($input, $action === 'menu.update' ? 'id' : 'menuId'));
                break;

            case 'menu.delete':
                self::addMenuDeleteLocks($locks, self::int($input, 'id'));
                break;

            case 'menu.setTop':
                self::addSiteMutationLocks($locks, self::int($input, 'siteId'));
                break;

            case 'layout.get':
            case 'layout.block.list':
                $siteId = self::int($input, 'siteId');
                self::addSiteShared($locks, $siteId);
                if (!self::layoutExists($siteId)) {
                    /* Первое чтение лениво создаёт layout. */
                    self::add($locks, self::NS_LAYOUT, $siteId, false, 'layout');
                }
                break;

            case 'layout.updateSettings':
            case 'layout.block.create':
            case 'layout.block.update':
            case 'layout.block.delete':
            case 'layout.block.move':
                $siteId = self::int($input, 'siteId');
                self::addSiteShared($locks, $siteId);
                self::add($locks, self::NS_LAYOUT, $siteId, false, 'layout');
                break;

            case 'pageAccess.save':
            case 'pageAccess.delete':
                $siteId = self::int($input, 'siteId');
                $pageId = self::int($input, 'pageId');
                self::addSiteShared($locks, $siteId);
                self::add($locks, self::NS_PAGE_ACCESS, $pageId, false, 'page.access');
                break;

            case 'pageSection.create':
                self::addPageSectionCollectionLocks(
                    $locks,
                    self::int($input, 'siteId'),
                    self::int($input, 'pageId'),
                    false
                );
                break;

            case 'pageSection.createPreset':
                $siteId = self::int($input, 'siteId');
                $pageId = self::int($input, 'pageId');
                self::addPageSectionCollectionLocks($locks, $siteId, $pageId, false);
                self::addPageBlockCollectionLocks($locks, $pageId);
                break;

            case 'pageSection.update':
            case 'pageSection.move':
                self::addPageSectionFromIdLocks(
                    $locks,
                    self::firstInt($input, ['sectionId', 'id']),
                    false
                );
                break;

            case 'pageSection.reorder':
                self::addPageSectionCollectionLocks(
                    $locks,
                    self::int($input, 'siteId'),
                    self::int($input, 'pageId'),
                    false
                );
                break;

            case 'pageSection.delete':
                self::addPageSectionFromIdLocks(
                    $locks,
                    self::firstInt($input, ['sectionId', 'id']),
                    true
                );
                break;

            case 'pageSection.assignBlock':
                self::addPageSectionAssignLocks(
                    $locks,
                    self::int($input, 'sectionId'),
                    self::int($input, 'blockId')
                );
                break;

            case 'section.create':
            case 'section.update':
                self::add($locks, self::NS_SITE_SECTION, 1, false, 'site.section.collection');
                break;

            case 'section.delete':
                self::addSectionDeleteLocks($locks, self::int($input, 'id'));
                break;

            case 'globalBlock.create':
            case 'globalBlock.update':
                self::addBlockEntityLocks($locks, self::int($input, 'blockId'));
                self::add($locks, self::NS_TEMPLATE_STORE, 1, false, 'template.store');
                break;

            case 'globalBlock.rename':
            case 'globalBlock.delete':
                self::add($locks, self::NS_TEMPLATE_STORE, 1, false, 'template.store');
                break;

            case 'template.createFromSite':
                $siteId = self::int($input, 'siteId');
                self::add($locks, self::NS_SITE_LIFECYCLE, $siteId, false, 'site.snapshot');
                self::add($locks, self::NS_TEMPLATE_STORE, 1, false, 'template.store');
                break;

            case 'template.update':
            case 'template.delete':
                self::add($locks, self::NS_TEMPLATE_STORE, 1, false, 'template.store');
                break;

            case 'template.createSite':
                self::add($locks, self::NS_TEMPLATE_STORE, 1, false, 'template.store');
                self::add($locks, self::NS_SITE_SECTION, 1, true, 'site.section.collection');
                self::add($locks, self::NS_SITE_COLLECTION, 1, false, 'site.collection');
                break;


            case 'backup.create':
                $siteId = self::int($input, 'siteId');
                self::add($locks, self::NS_SITE_LIFECYCLE, $siteId, false, 'site.snapshot');
                self::add($locks, self::NS_BACKUP_COLLECTION, $siteId, false, 'backup.collection');
                self::add($locks, self::NS_TEMPLATE_STORE, 1, true, 'global.block.definition');
                break;

            case 'backup.import':
                $siteId = self::int($input, 'siteId');
                self::add($locks, self::NS_SITE_LIFECYCLE, $siteId, true, 'site.lifecycle');
                self::add($locks, self::NS_BACKUP_COLLECTION, $siteId, false, 'backup.collection');
                break;

            case 'backup.verify':
            case 'backup.delete':
                self::add($locks, self::NS_BACKUP, self::int($input, 'backupId'), false, 'backup');
                break;

            case 'backup.restore':
                self::add($locks, self::NS_BACKUP, self::int($input, 'backupId'), false, 'backup');
                self::add($locks, self::NS_SITE_SECTION, 1, true, 'site.section.collection');
                self::add($locks, self::NS_SITE_COLLECTION, 1, false, 'site.collection');
                self::add($locks, self::NS_TEMPLATE_STORE, 1, false, 'template.store');
                break;

            case 'integrity.run':
                $siteId = self::int($input, 'siteId');
                self::add($locks, self::NS_SITE_LIFECYCLE, $siteId, false, 'site.integrity');
                self::add($locks, self::NS_INTEGRITY, $siteId, false, 'integrity.run');
                self::add($locks, self::NS_TEMPLATE_STORE, 1, true, 'global.block.definition');
                break;

            case 'trash.restore':
                self::addTrashLocks($locks, self::int($input, 'id'), true);
                break;

            case 'trash.purge':
                self::addTrashLocks($locks, self::int($input, 'id'), false);
                break;

            case 'history.restore':
                self::addHistoryRestoreLocks($locks, self::int($input, 'revisionId'));
                break;

            default:
                /* Неизвестное действие всё равно будет отклонено роутером API. */
                break;
        }

        return $locks;
    }

    private static function addSiteMutationLocks(array &$locks, int $siteId): void
    {
        self::addSiteShared($locks, $siteId);
        self::add($locks, self::NS_SITE_ENTITY, $siteId, false, 'site.entity');
    }

    private static function addSiteShared(array &$locks, int $siteId): void
    {
        self::add($locks, self::NS_SITE_LIFECYCLE, $siteId, true, 'site.lifecycle');
    }

    private static function addPageShared(array &$locks, int $pageId): void
    {
        self::add($locks, self::NS_PAGE_LIFECYCLE, $pageId, true, 'page.lifecycle');
    }

    private static function addPageExclusive(array &$locks, int $pageId): void
    {
        self::add($locks, self::NS_PAGE_LIFECYCLE, $pageId, false, 'page.lifecycle');
    }

    private static function addPageEntityLocks(array &$locks, int $pageId): void
    {
        $row = self::pageContext($pageId);
        if (!$row) {
            return;
        }
        self::addSiteShared($locks, (int)$row['site_id']);
        self::addPageShared($locks, $pageId);
        self::add($locks, self::NS_PAGE_ENTITY, $pageId, false, 'page.entity');
    }

    private static function addPageTreeMutationLocks(array &$locks, int $pageId): void
    {
        $row = self::pageContext($pageId);
        if (!$row) {
            return;
        }
        $siteId = (int)$row['site_id'];
        self::addSiteShared($locks, $siteId);
        self::addPageShared($locks, $pageId);
        self::add($locks, self::NS_PAGE_TREE, $siteId, false, 'page.tree');
    }

    private static function addPageBroadMutationLocks(array &$locks, int $pageId): void
    {
        $row = self::pageContext($pageId);
        if (!$row) {
            return;
        }
        /* Удаление ветки затрагивает страницы, блоки, секции, меню и права. */
        self::add($locks, self::NS_SITE_LIFECYCLE, (int)$row['site_id'], false, 'site.lifecycle');
    }

    private static function addPageDuplicateLocks(array &$locks, int $pageId): void
    {
        $row = self::pageContext($pageId);
        if (!$row) {
            return;
        }
        $siteId = (int)$row['site_id'];
        self::addSiteShared($locks, $siteId);

        /*
         * Дублирование читает страницу и все её блоки. Exclusive page lifecycle
         * не допускает параллельное изменение исходной страницы, её блоков и
         * секций, но не блокирует другие страницы этого сайта.
         */
        self::addPageExclusive($locks, $pageId);
        self::add($locks, self::NS_PAGE_TREE, $siteId, false, 'page.tree');
    }

    private static function addPageBlockCollectionLocks(array &$locks, int $pageId): void
    {
        $row = self::pageContext($pageId);
        if (!$row) {
            return;
        }
        self::addSiteShared($locks, (int)$row['site_id']);
        self::addPageShared($locks, $pageId);
        self::add($locks, self::NS_PAGE_BLOCKS, $pageId, false, 'page.blocks');
    }

    private static function addBlockEntityLocks(array &$locks, int $blockId): void
    {
        $row = self::blockContext($blockId);
        if (!$row) {
            return;
        }
        self::addSiteShared($locks, (int)$row['site_id']);
        self::addPageShared($locks, (int)$row['page_id']);
        self::add($locks, self::NS_BLOCK, $blockId, false, 'block.entity');
    }

    private static function addBlockCollectionFromBlockLocks(array &$locks, int $blockId): void
    {
        $row = self::blockContext($blockId);
        if (!$row) {
            return;
        }
        self::addSiteShared($locks, (int)$row['site_id']);
        self::addPageShared($locks, (int)$row['page_id']);
        self::add($locks, self::NS_PAGE_BLOCKS, (int)$row['page_id'], false, 'page.blocks');
    }

    private static function addMenuLocks(array &$locks, int $menuId): void
    {
        $row = self::menuContext($menuId);
        if (!$row) {
            return;
        }
        self::addSiteShared($locks, (int)$row['site_id']);
        self::add($locks, self::NS_MENU, $menuId, false, 'menu');
    }

    private static function addMenuDeleteLocks(array &$locks, int $menuId): void
    {
        $row = self::menuContext($menuId);
        if (!$row) {
            return;
        }
        $siteId = (int)$row['site_id'];
        self::addSiteShared($locks, $siteId);
        self::add($locks, self::NS_SITE_ENTITY, $siteId, false, 'site.entity');
        self::add($locks, self::NS_MENU, $menuId, false, 'menu');
    }

    private static function addPageSectionCollectionLocks(array &$locks, int $siteId, int $pageId, bool $touchBlocks): void
    {
        self::addSiteShared($locks, $siteId);
        self::addPageShared($locks, $pageId);
        self::add($locks, self::NS_PAGE_SECTIONS, $pageId, false, 'page.sections');
        if ($touchBlocks) {
            self::add($locks, self::NS_PAGE_BLOCKS, $pageId, false, 'page.blocks');
        }
    }

    private static function addPageSectionFromIdLocks(array &$locks, int $sectionId, bool $touchBlocks): void
    {
        $row = self::pageSectionContext($sectionId);
        if (!$row) {
            return;
        }
        self::addPageSectionCollectionLocks(
            $locks,
            (int)$row['site_id'],
            (int)$row['page_id'],
            $touchBlocks
        );
    }

    private static function addPageSectionAssignLocks(array &$locks, int $sectionId, int $blockId): void
    {
        $section = self::pageSectionContext($sectionId);
        $block = self::blockContext($blockId);
        if (!$section || !$block) {
            return;
        }
        self::addSiteShared($locks, (int)$section['site_id']);
        self::addPageShared($locks, (int)$section['page_id']);
        self::add($locks, self::NS_PAGE_SECTIONS, (int)$section['page_id'], false, 'page.sections');
        self::add($locks, self::NS_BLOCK, $blockId, false, 'block.entity');
    }

    private static function addSectionDeleteLocks(array &$locks, int $sectionId): void
    {
        self::add($locks, self::NS_SITE_SECTION, 1, false, 'site.section.collection');
        if ($sectionId <= 0) {
            return;
        }

        $rows = sb_db_fetch_all(
            'SELECT id FROM sitebuilder.site WHERE section_id=:section_id ORDER BY id ASC',
            [':section_id' => $sectionId]
        );
        foreach ($rows as $row) {
            self::addSiteMutationLocks($locks, (int)($row['id'] ?? 0));
        }
    }

    private static function addTrashLocks(array &$locks, int $trashId, bool $restore): void
    {
        if ($trashId <= 0) {
            return;
        }
        $row = sb_db_fetch_one(
            'SELECT site_id FROM sitebuilder.recycle_bin WHERE id=:id',
            [':id' => $trashId]
        );
        if ($row) {
            self::add(
                $locks,
                self::NS_SITE_LIFECYCLE,
                (int)$row['site_id'],
                !$restore,
                $restore ? 'site.lifecycle' : 'site.lifecycle'
            );
        }
        self::add($locks, self::NS_TRASH, $trashId, false, 'trash.item');
    }

    private static function addHistoryRestoreLocks(array &$locks, int $revisionId): void
    {
        if ($revisionId <= 0) {
            return;
        }

        $row = sb_db_fetch_one(
            'SELECT site_id,entity_type,entity_id,page_id FROM sitebuilder.entity_revision WHERE id=:id',
            [':id' => $revisionId]
        );
        if (!$row) {
            return;
        }

        $siteId = (int)$row['site_id'];
        $entityId = (int)$row['entity_id'];
        $entityType = (string)$row['entity_type'];
        self::addSiteShared($locks, $siteId);
        self::add($locks, self::NS_REVISION, $revisionId, false, 'revision');

        switch ($entityType) {
            case 'site':
                self::add($locks, self::NS_SITE_COLLECTION, 1, false, 'site.collection');
                self::add($locks, self::NS_SITE_ENTITY, $siteId, false, 'site.entity');
                break;
            case 'page':
                self::addPageShared($locks, $entityId);
                self::add($locks, self::NS_PAGE_TREE, $siteId, false, 'page.tree');
                break;
            case 'block':
                self::addPageShared($locks, (int)($row['page_id'] ?? 0));
                self::add($locks, self::NS_BLOCK, $entityId, false, 'block.entity');
                break;
            case 'menu':
                self::add($locks, self::NS_MENU, $entityId, false, 'menu');
                break;
            case 'layout':
                self::add($locks, self::NS_LAYOUT, $siteId, false, 'layout');
                break;
        }
    }


    private static function pageContext(int $pageId): ?array
    {
        if ($pageId <= 0) {
            return null;
        }
        return sb_db_fetch_one(
            'SELECT id,site_id,parent_id FROM sitebuilder.page WHERE id=:id',
            [':id' => $pageId]
        );
    }

    private static function blockContext(int $blockId): ?array
    {
        if ($blockId <= 0) {
            return null;
        }
        return sb_db_fetch_one(
            'SELECT b.id,b.page_id,p.site_id FROM sitebuilder.block b JOIN sitebuilder.page p ON p.id=b.page_id WHERE b.id=:id',
            [':id' => $blockId]
        );
    }

    private static function menuContext(int $menuId): ?array
    {
        if ($menuId <= 0) {
            return null;
        }
        return sb_db_fetch_one(
            'SELECT id,site_id FROM sitebuilder.menu WHERE id=:id',
            [':id' => $menuId]
        );
    }

    private static function layoutExists(int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        return sb_db_fetch_one(
            'SELECT site_id FROM sitebuilder.layout WHERE site_id=:site_id',
            [':site_id' => $siteId]
        ) !== null;
    }

    private static function pageSectionContext(int $sectionId): ?array
    {
        if ($sectionId <= 0) {
            return null;
        }
        return sb_db_fetch_one(
            'SELECT id,site_id,page_id FROM sitebuilder.page_section WHERE id=:id',
            [':id' => $sectionId]
        );
    }

    private static function int(array $input, string $key): int
    {
        return (int)($input[$key] ?? 0);
    }

    private static function firstInt(array $input, array $keys): int
    {
        foreach ($keys as $key) {
            $value = self::int($input, (string)$key);
            if ($value > 0) {
                return $value;
            }
        }
        return 0;
    }

    private static function isGlobalBlock(int $blockId): bool
    {
        if ($blockId <= 0) {
            return false;
        }
        try {
            $row = sb_db_fetch_one(
                'SELECT type FROM sitebuilder.block WHERE id = :id',
                [':id' => $blockId]
            );
            return (string)($row['type'] ?? '') === 'global';
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function add(array &$locks, int $namespace, int $resourceId, bool $shared, string $label): void
    {
        if ($resourceId <= 0) {
            return;
        }
        $locks[] = self::lock($namespace, $resourceId, $shared, $label);
    }

    private static function lock(int $namespace, int $resourceId, bool $shared, string $label): array
    {
        return [
            'namespace' => $namespace,
            'resourceId' => $resourceId,
            'shared' => $shared,
            'label' => $label,
        ];
    }

    private static function normalize(array $locks): array
    {
        $unique = [];
        foreach ($locks as $lock) {
            $namespace = (int)($lock['namespace'] ?? 0);
            $resourceId = (int)($lock['resourceId'] ?? 0);
            if ($namespace <= 0 || $resourceId <= 0) {
                continue;
            }
            $key = $namespace . ':' . $resourceId;
            if (!isset($unique[$key])) {
                $unique[$key] = $lock;
                continue;
            }
            /* Exclusive сильнее shared. */
            if (!empty($unique[$key]['shared']) && empty($lock['shared'])) {
                $unique[$key] = $lock;
            }
        }

        $locks = array_values($unique);
        usort($locks, static function (array $a, array $b): int {
            $byNamespace = (int)$a['namespace'] <=> (int)$b['namespace'];
            if ($byNamespace !== 0) {
                return $byNamespace;
            }
            return (int)$a['resourceId'] <=> (int)$b['resourceId'];
        });
        return $locks;
    }

    private static function applyLockTimeout(): void
    {
        static $applied = false;
        if ($applied) {
            return;
        }

        $configPath = dirname(__DIR__) . '/config/locking.php';
        $config = is_file($configPath) ? require $configPath : [];
        $timeoutMs = max(500, min(60000, (int)($config['lock_timeout_ms'] ?? 10000)));
        sb_db()->exec("SET LOCAL lock_timeout = '" . $timeoutMs . "ms'");
        $applied = true;
    }

    private static function acquire(array $locks, string $action = ''): void
    {
        $pdo = sb_db();
        foreach (self::normalize($locks) as $lock) {
            $function = !empty($lock['shared'])
                ? 'pg_advisory_xact_lock_shared'
                : 'pg_advisory_xact_lock';
            try {
                $stmt = $pdo->prepare(
                    'SELECT ' . $function . '(CAST(:namespace AS integer),CAST(:resource_id AS integer))'
                );
                $stmt->execute([
                    ':namespace' => (int)$lock['namespace'],
                    ':resource_id' => (int)$lock['resourceId'],
                ]);
            } catch (PDOException $e) {
                if (sb_db_exception_sqlstate($e) === '55P03') {
                    throw new SiteBuilderResourceBusyException([
                        'action' => $action,
                        'resource' => (string)($lock['label'] ?? 'resource'),
                        'resourceId' => (int)$lock['resourceId'],
                    ], $e);
                }
                throw $e;
            }
        }
    }
}

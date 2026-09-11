<?php

namespace {
    if (!defined('SB_DISK_TEST_RUNTIME')) {
        http_response_code(404);
        exit;
    }
    $fixture = json_decode(file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/data.json'), true);
    function fixture_object(int $id): ?array { return $GLOBALS['fixture']['objects'][$id] ?? null; }
    function fixture_user(): int { return (int)($_SERVER['HTTP_X_TEST_USER'] ?? 2); }
    function bitrix_sessid(): string { return 'test-session'; }
    function sitebuilder_require_api_auth(): void { sitebuilder_require_auth(); }
    function sb_disk_release_session_lock(): void {}
    function sb_db_fetch_all($sql, $params = []): array { return $GLOBALS['fixture']['quotaLimits'] ?? []; }
    function sitebuilder_require_auth(): void {
        if (fixture_user() <= 0) {
            header('Location: /login?return=' . rawurlencode($_SERVER['REQUEST_URI']), true, 302);
            exit;
        }
    }
    class DiskCurrentUser {
        public static function requireId(): int { return fixture_user(); }
        public static function isAdmin(): bool { return false; }
    }
    class SiteRepository {
        public static function getById(int $id): ?array { return $id === 1 ? ['id' => 1] : null; }
        public static function getRootDiskFolderId(int $id): ?int { return $GLOBALS['fixture']['blockRoot']; }
    }
    class BlockRepository {
        public static function getDiskBlockByContext($siteId, $pageId, $blockId): ?array {
            return [$siteId, $pageId, $blockId] === [1, 2, 3] ? ['id' => 3] : null;
        }
    }
    class DiskSettingsRepository {
        public static function getByBlockId(int $id): array {
            return array_merge(['rootMode' => 'site', 'permissionMode' => 'custom', 'allowDownload' => true], $GLOBALS['fixture']['settings'] ?? []);
        }
    }
    class SiteAccessRepository {
        public static function getUserRole($siteId, $userId): string { return 'site_viewer'; }
    }
    class PageAccessService {
        public static function canViewPage($siteId, $pageId, $userId): bool {
            return !in_array($userId, $GLOBALS['fixture']['pageDeniedUsers'], true);
        }
        public static function canViewDisk($siteId, $pageId, $userId): bool {
            return !in_array($userId, $GLOBALS['fixture']['diskDeniedUsers'], true);
        }
        public static function canEditDisk($siteId, $pageId, $userId): bool { return false; }
    }
    class FolderAccessRepository {
        public const ROLE_DENY = 'DENY';
        public const ROLE_VIEWER = 'VIEWER';
        public const ROLE_EDITOR = 'EDITOR';
        public static function resolveEffectiveRole($blockId, $folderId, $rootId, $userId): ?array {
            $visited = [];
            while ($folderId > 0 && !isset($visited[$folderId])) {
                $visited[$folderId] = true;
                $role = $GLOBALS['fixture']['folderRoles'][$userId . ':' . $folderId] ?? null;
                if ($role !== null) return ['role' => $role, 'folderId' => $folderId];
                if ($folderId === $rootId) break;
                $folderId = (int)(fixture_object($folderId)['parentId'] ?? 0);
            }
            return null;
        }
    }
}

namespace Bitrix\Disk {
    class Storage {
        public function getSecurityContext(int $id): int { return $id; }
    }
    class ObjectFixture {
        public function __construct(protected array $row) {}
        public function getId() { return $this->row['id']; }
        public function getParentId() { return $this->row['parentId']; }
        public function getName() { return $this->row['name']; }
        public function getCreateTime() { return '2026-09-11 10:00:00'; }
        public function getUpdateTime() { return '2026-09-11 10:00:00'; }
        public function getCreatedBy() { return 1; }
        public function getStorage() { return new Storage(); }
        public function isDeleted() { return !empty($this->row['deleted']); }
        public function canRead($context) { return !in_array($context, $this->row['deniedUsers'] ?? [], true); }
    }
    class Folder extends ObjectFixture {
        public static function loadById($id): ?self {
            $row = \fixture_object((int)$id);
            return $row && $row['type'] === 'folder' ? new self($row) : null;
        }
        public function getChildren($context): array {
            if ($context !== \fixture_user() && !($context === 'quota-all-files' && !empty($GLOBALS['fixture']['quotaTest']))) {
                throw new \RuntimeException('Wrong native security context');
            }
            $result = [];
            foreach ($GLOBALS['fixture']['objects'] as $row) {
                if ($row['parentId'] === $this->getId()) {
                    // Deliberately include denied/deleted entries to verify the adapter's own checks.
                    $result[] = $row['type'] === 'folder' ? new self($row) : new File($row);
                }
            }
            return $result;
        }
    }
    class File extends ObjectFixture {
        public static function loadById($id): ?self {
            $row = \fixture_object((int)$id);
            return $row && $row['type'] === 'file' ? new self($row) : null;
        }
        public function getExtension() { return pathinfo($this->getName(), PATHINFO_EXTENSION); }
        public function getSize() { return 1024; }
    }
    class Driver {
        public static function getInstance(): self { return new self(); }
        public function getUrlManager() { return new class {
            public function getHostUrl(): string { return 'https://portal.example'; }
        }; }
        public function getFakeSecurityContext($userId) {
            if (!empty($GLOBALS['fixture']['quotaTest'])) return 'quota-all-files';
            throw new \RuntimeException('Shared folder must use native permissions');
        }
    }
}

<?php
/**
 * Execute the real download action with isolated in-memory Bitrix boundaries.
 * Covers binary response, file IDs, SiteBuilder ACL, and secure failure output.
 */
namespace Bitrix\Disk {
    class File
    {
        public static function loadById(int $id): ?self
        {
            if ($GLOBALS['testScenario'] === 'missing') {
                return null;
            }
            return new self();
        }

        public function isDeleted(): bool
        {
            return $GLOBALS['testScenario'] === 'deleted';
        }

        public function getFile(): array
        {
            return $GLOBALS['testScenario'] === 'missingData'
                ? []
                : ['ID' => 9001, 'ORIGINAL_NAME' => 'underlying.dat'];
        }

        public function getName(): string
        {
            return "report-2026.pdf";
        }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }

    if (!isset($argv[1])) {
        function check($test, string $message): void
        {
            if (!$test) {
                throw new \RuntimeException($message);
            }
        }

        $cases = [
            'success' => 'success',
            'denied' => 'ACCESS_DENIED',
            'missing' => 'DISK_FILE_NOT_FOUND',
            'missingData' => 'DISK_FILE_NOT_FOUND',
            'deleted' => 'DISK_FILE_NOT_FOUND',
            'invalidId' => 'INVALID_FILE_ID',
            'outside' => 'FOLDER_OUT_OF_SCOPE',
            'readerError' => 'DISK_DOWNLOAD_FAILED',
            'readerErrorAdmin' => 'DISK_DOWNLOAD_FAILED',
            'notAuthorized' => 'AUTH_REQUIRED',
        ];

        $checks = 0;
        foreach ($cases as $scenario => $expected) {
            $process = proc_open(
                [PHP_BINARY, __FILE__, $scenario],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Unable to launch download test ' . $scenario);
            }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            check($exit === 0, 'Nonzero exit in ' . $scenario . ': ' . $errors);
            $json = json_decode($output, true);
            check(is_array($json), 'Expected JSON in ' . $scenario . ': ' . $output);
            ++$checks;

            if ($scenario === 'success') {
                check($json['stream'] === 'file-binary-success', 'Bitrix file streamer is used');
                check($json['physicalFileId'] === 9001, 'Uses b_file ID, not Disk object ID');
                check($json['forceDownload'] === true, 'Forces download response');
                check($json['cacheTime'] === 0, 'No cached private files');
                check($json['attachmentName'] === 'report-2026.pdf', 'Preserves original download filename');
                check($json['sessionReleased'] === true, 'Releases session lock before streaming');
                $checks += 6;
                continue;
            }

            check(($json['ok'] ?? null) === false, 'Failed download is not success: ' . $scenario);
            $expectedError = $expected === 'FOLDER_OUT_OF_SCOPE' ? 'ACCESS_DENIED' : $expected;
            check(($json['error'] ?? null) === $expectedError, 'Expected error ' . $expectedError . ' in ' . $scenario);
            check(($json['details']['userId'] ?? null) === ($scenario === 'notAuthorized' ? 0 : 42), 'Error identifies current user in ' . $scenario);
            check(($json['details']['fileId'] ?? -1) === (in_array($scenario, ['invalidId', 'notAuthorized'], true) ? 0 : 61), 'Error identifies file in ' . $scenario);
            check(strpos($json['message'] ?? '', 'Код диагностики: SD-DL-') !== false, 'Human-readable support token in ' . $scenario);
            $checks += 5;

            if ($scenario === 'readerError') {
                check(strpos($output, 'READ_FAILED_PASSWORD_SECRET') === false, 'Do not leak internal errors to regular users');
                check(!isset($json['details']['bitrixGroupIds']), 'Do not leak group membership to ordinary users');
                $checks += 2;
            }
            if ($scenario === 'readerErrorAdmin') {
                check(($json['details']['technicalError'] ?? '') === 'READ_FAILED_PASSWORD_SECRET', 'Admin sees original PHP cause');
                check(($json['details']['bitrixGroupIds'] ?? null) === [1, 20], 'Admin sees group IDs');
                check(($json['details']['effectivePermissions']['canDownload'] ?? null) === true, 'Admin sees calculated ACL');
                $checks += 3;
            }
            check(strpos($errors, 'SiteBuilder Disk download error: ') !== false, 'Server log tracks failing download ' . $scenario);
            ++$checks;
        }

        echo "Disk download action checks passed ($checks checks)\n";
        exit;
    }

    $GLOBALS['testScenario'] = (string)$argv[1];
    $_GET = [
        'siteId' => '13',
        'pageId' => '31',
        'blockId' => '80',
        'fileId' => $GLOBALS['testScenario'] === 'invalidId' ? '0' : '61',
    ];

    class DiskCurrentUser
    {
        public static function requireId(): int {
            if ($GLOBALS['testScenario'] === 'notAuthorized') {
                throw new \RuntimeException('NOT_AUTHORIZED');
            }
            return 42;
        }
        public static function getGroupIds(): array { return [1, 20]; }
        public static function isAdmin(): bool { return $GLOBALS['testScenario'] === 'readerErrorAdmin'; }
    }

    require __DIR__ . '/../components/disk/lib/DiskContext.php';

    class DiskValidator
    {
        public static function assertContext(DiskContext $context): void
        {
            if ($context->siteId !== 13 || $context->pageId !== 31
                || $context->blockId !== 80 || $context->currentUserId !== 42) {
                throw new \RuntimeException('INVALID_CONTEXT');
            }
        }

        public static function assertFileInsideRoot(int $fileId, ?int $rootFolderId, ?DiskContext $context = null): void
        {
            if ($GLOBALS['testScenario'] === 'outside') {
                throw new \RuntimeException('FOLDER_OUT_OF_SCOPE');
            }
            if ($fileId !== 61 || $rootFolderId !== 10) {
                throw new \RuntimeException('FOLDER_OUT_OF_SCOPE');
            }
        }

        public static function itemParentFolderId(string $type, int $fileId): int
        {
            return 10;
        }

        public static function permissionsForFolder(
            DiskContext $context, array $settings, int $folderId, int $rootFolderId
        ): array {
            return ['canDownload' => $GLOBALS['testScenario'] !== 'denied', 'role' => 'site_admin'];
        }

        public static function assertCan(array $permissions, string $name): void
        {
            if (empty($permissions[$name])) {
                throw new \RuntimeException('ACCESS_DENIED');
            }
        }
    }

    class DiskSettingsRepository
    {
        public static function getByBlockId(int $blockId): array
        {
            return ['permissionMode' => 'inherit_site', 'allowDownload' => true];
        }

        public static function ensureExistsForBlock(...$args): array
        {
            throw new \RuntimeException('Download must never save settings');
        }
    }

    class DiskRootResolver
    {
        public static function resolve(DiskContext $context, array $settings): int { return 10; }
    }

    class DiskResponse
    {
        public static function error(string $errorCode, string $message, array $details = []): void
        {
            echo json_encode([
                'ok' => false, 'error' => $errorCode,
                'message' => $message, 'details' => $details,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    class CFile
    {
        public static function ViewByUser(array $file, array $options): void
        {
            if (in_array($GLOBALS['testScenario'], ['readerError', 'readerErrorAdmin'], true)) {
                throw new \RuntimeException('READ_FAILED_PASSWORD_SECRET');
            }
            echo json_encode([
                'stream' => 'file-binary-success',
                'physicalFileId' => $file['ID'],
                'forceDownload' => $options['force_download'] ?? null,
                'cacheTime' => $options['cache_time'] ?? null,
                'attachmentName' => $options['attachment_name'] ?? null,
                'sessionReleased' => !empty($GLOBALS['testSessionReleased']),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    function disk_read_json_body(): array { return []; }
    function sb_disk_release_session_lock(): void
    {
        $GLOBALS['testSessionReleased'] = true;
    }

    require __DIR__ . '/../components/disk/actions/download.php';
}

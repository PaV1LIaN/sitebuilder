<?php

class SiteAppearanceService
{
    protected const UPLOAD_DIR = 'sitebuilder/appearance';

    protected const MAX_FILE_SIZE = 10485760; // 10 MB

    protected const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'gif',
    ];

    protected const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public static function get(int $siteId): array
    {
        $site = self::getSiteOrFail($siteId);
        $settings = self::normalizeAppearanceSettings($site['settings'] ?? []);
        $settings['siteVersion'] = max(1, (int)($site['version'] ?? 1));
        return self::withUrls($settings);
    }

    public static function update(int $siteId, array $data, int $currentUserId, int $expectedVersion): array
    {
        $site = self::getSiteOrFail($siteId);
        $settings = self::normalizeAppearanceSettings($site['settings'] ?? []);

        if (array_key_exists('backgroundColor', $data)) {
            $settings['backgroundColor'] = self::normalizeColor((string)$data['backgroundColor'], '#f8fafc');
        }

        if (array_key_exists('backgroundMode', $data)) {
            $settings['backgroundMode'] = self::normalizeBackgroundMode((string)$data['backgroundMode']);
        }

        if (array_key_exists('backgroundPosition', $data)) {
            $settings['backgroundPosition'] = self::normalizeBackgroundPosition((string)$data['backgroundPosition']);
        }

        if (array_key_exists('backgroundRepeat', $data)) {
            $settings['backgroundRepeat'] = self::normalizeBackgroundRepeat((string)$data['backgroundRepeat']);
        }

        if (array_key_exists('headerLogoMode', $data)) {
            $settings['headerLogoMode'] = self::normalizeHeaderLogoMode((string)$data['headerLogoMode']);
        }

        if (array_key_exists('logoSize', $data)) {
            $settings['logoSize'] = self::normalizeLogoSize((int)$data['logoSize']);
        }

        foreach (['secondaryColor', 'textColor', 'mutedColor', 'surfaceColor', 'borderColor'] as $colorKey) {
            if (array_key_exists($colorKey, $data)) {
                $settings[$colorKey] = self::normalizeColor(
                    (string)$data[$colorKey],
                    (string)($settings[$colorKey] ?? self::defaultDesignTokens()[$colorKey])
                );
            }
        }

        if (array_key_exists('headingFont', $data)) {
            $settings['headingFont'] = self::normalizeFontFamily((string)$data['headingFont']);
        }

        if (array_key_exists('bodyFont', $data)) {
            $settings['bodyFont'] = self::normalizeFontFamily((string)$data['bodyFont']);
        }

        if (array_key_exists('baseFontSize', $data)) {
            $settings['baseFontSize'] = self::normalizeRangeInt((int)$data['baseFontSize'], 14, 22, 16);
        }

        if (array_key_exists('bodyLineHeight', $data)) {
            $settings['bodyLineHeight'] = self::normalizeRangeFloat((float)$data['bodyLineHeight'], 1.2, 2.2, 1.6);
        }

        if (array_key_exists('headingWeight', $data)) {
            $settings['headingWeight'] = self::normalizeHeadingWeight((int)$data['headingWeight']);
        }

        if (array_key_exists('radiusScale', $data)) {
            $settings['radiusScale'] = self::normalizeRangeInt((int)$data['radiusScale'], 0, 32, 16);
        }

        if (array_key_exists('buttonRadius', $data)) {
            $settings['buttonRadius'] = self::normalizeRangeInt((int)$data['buttonRadius'], 0, 40, 12);
        }

        if (array_key_exists('sectionGap', $data)) {
            $settings['sectionGap'] = self::normalizeRangeInt((int)$data['sectionGap'], 0, 96, 24);
        }

        if (array_key_exists('shadowPreset', $data)) {
            $settings['shadowPreset'] = self::normalizeShadowPreset((string)$data['shadowPreset']);
        }

        $savedSite = self::saveSiteSettings($siteId, $settings, $currentUserId, $expectedVersion, 'appearance_update');
        $settings['siteVersion'] = (int)$savedSite['version'];
        return self::withUrls($settings);
    }

    public static function upload(int $siteId, string $type, array $file, int $currentUserId, int $expectedVersion): array
    {
        $type = self::normalizeAssetType($type);

        if (!class_exists('CFile')) {
            throw new RuntimeException('CFile_NOT_FOUND');
        }

        self::validateUploadFile($file);

        $site = self::getSiteOrFail($siteId);
        $settings = self::normalizeAppearanceSettings($site['settings'] ?? []);

        $oldFileIdKey = $type === 'logo' ? 'logoFileId' : 'backgroundFileId';
        $oldFileId = (int)($settings[$oldFileIdKey] ?? 0);

        $file['MODULE_ID'] = 'main';

        $newFileId = (int)CFile::SaveFile($file, self::UPLOAD_DIR);

        if ($newFileId <= 0) {
            throw new RuntimeException('FILE_SAVE_ERROR');
        }

        /*
         * Если БД откатится, новый файл больше никому не принадлежит.
         * Старый файл удаляем только после успешного COMMIT.
         */
        if (function_exists('sb_db_after_rollback')) {
            sb_db_after_rollback(static function () use ($newFileId): void {
                if (class_exists('CFile')) {
                    CFile::Delete($newFileId);
                }
            });
        }

        $settings[$oldFileIdKey] = $newFileId;

        try {
            $savedSite = self::saveSiteSettings($siteId, $settings, $currentUserId, $expectedVersion, 'appearance_upload_' . $type);
        } catch (Throwable $e) {
            if (empty($GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'])) {
                CFile::Delete($newFileId);
            }
            throw $e;
        }

        if ($oldFileId > 0) {
            $deleteOldFile = static function () use ($oldFileId): void {
                if (class_exists('CFile')) {
                    CFile::Delete($oldFileId);
                }
            };

            if (function_exists('sb_db_after_commit')) {
                sb_db_after_commit($deleteOldFile);
            } else {
                $deleteOldFile();
            }
        }

        $settings['siteVersion'] = (int)$savedSite['version'];
        return self::withUrls($settings);
    }

    public static function remove(int $siteId, string $type, int $currentUserId, int $expectedVersion): array
    {
        $type = self::normalizeAssetType($type);

        if (!class_exists('CFile')) {
            throw new RuntimeException('CFile_NOT_FOUND');
        }

        $site = self::getSiteOrFail($siteId);
        $settings = self::normalizeAppearanceSettings($site['settings'] ?? []);

        $fileIdKey = $type === 'logo' ? 'logoFileId' : 'backgroundFileId';
        $fileId = (int)($settings[$fileIdKey] ?? 0);

        $settings[$fileIdKey] = 0;

        $savedSite = self::saveSiteSettings($siteId, $settings, $currentUserId, $expectedVersion, 'appearance_remove_' . $type);

        if ($fileId > 0) {
            $deleteFile = static function () use ($fileId): void {
                if (class_exists('CFile')) {
                    CFile::Delete($fileId);
                }
            };

            if (function_exists('sb_db_after_commit')) {
                sb_db_after_commit($deleteFile);
            } else {
                $deleteFile();
            }
        }

        $settings['siteVersion'] = (int)$savedSite['version'];
        return self::withUrls($settings);
    }

    protected static function getSiteOrFail(int $siteId): array
    {
        if ($siteId <= 0) {
            throw new RuntimeException('EMPTY_SITE_ID');
        }
        $site = RevisionService::getSite($siteId, false);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }
        return $site;
    }

    protected static function saveSiteSettings(
        int $siteId,
        array $settings,
        int $currentUserId,
        int $expectedVersion,
        string $operation
    ): array {
        $site = self::getSiteOrFail($siteId);
        $currentSettings = is_array($site['settings'] ?? null) ? $site['settings'] : [];
        $site['settings'] = array_merge($currentSettings, $settings);

        return RevisionService::saveSite(
            $site,
            RevisionService::requireExpectedVersion($expectedVersion),
            $currentUserId,
            $operation
        );
    }

    protected static function normalizeAppearanceSettings(array $settings): array
    {
        $tokens = self::defaultDesignTokens();

        return [
            'accent' => self::normalizeColor((string)($settings['accent'] ?? '#2563eb'), '#2563eb'),
            'secondaryColor' => self::normalizeColor((string)($settings['secondaryColor'] ?? $tokens['secondaryColor']), $tokens['secondaryColor']),
            'textColor' => self::normalizeColor((string)($settings['textColor'] ?? $tokens['textColor']), $tokens['textColor']),
            'mutedColor' => self::normalizeColor((string)($settings['mutedColor'] ?? $tokens['mutedColor']), $tokens['mutedColor']),
            'surfaceColor' => self::normalizeColor((string)($settings['surfaceColor'] ?? $tokens['surfaceColor']), $tokens['surfaceColor']),
            'borderColor' => self::normalizeColor((string)($settings['borderColor'] ?? $tokens['borderColor']), $tokens['borderColor']),
            'headingFont' => self::normalizeFontFamily((string)($settings['headingFont'] ?? $tokens['headingFont'])),
            'bodyFont' => self::normalizeFontFamily((string)($settings['bodyFont'] ?? $tokens['bodyFont'])),
            'baseFontSize' => self::normalizeRangeInt((int)($settings['baseFontSize'] ?? $tokens['baseFontSize']), 14, 22, 16),
            'bodyLineHeight' => self::normalizeRangeFloat((float)($settings['bodyLineHeight'] ?? $tokens['bodyLineHeight']), 1.2, 2.2, 1.6),
            'headingWeight' => self::normalizeHeadingWeight((int)($settings['headingWeight'] ?? $tokens['headingWeight'])),
            'radiusScale' => self::normalizeRangeInt((int)($settings['radiusScale'] ?? $tokens['radiusScale']), 0, 32, 16),
            'buttonRadius' => self::normalizeRangeInt((int)($settings['buttonRadius'] ?? $tokens['buttonRadius']), 0, 40, 12),
            'sectionGap' => self::normalizeRangeInt((int)($settings['sectionGap'] ?? $tokens['sectionGap']), 0, 96, 24),
            'shadowPreset' => self::normalizeShadowPreset((string)($settings['shadowPreset'] ?? $tokens['shadowPreset'])),

            'logoFileId' => (int)($settings['logoFileId'] ?? 0),
            'backgroundFileId' => (int)($settings['backgroundFileId'] ?? 0),

            'backgroundColor' => self::normalizeColor(
                (string)($settings['backgroundColor'] ?? '#f8fafc'),
                '#f8fafc'
            ),

            'backgroundMode' => self::normalizeBackgroundMode(
                (string)($settings['backgroundMode'] ?? 'cover')
            ),

            'backgroundPosition' => self::normalizeBackgroundPosition(
                (string)($settings['backgroundPosition'] ?? 'center center')
            ),

            'backgroundRepeat' => self::normalizeBackgroundRepeat(
                (string)($settings['backgroundRepeat'] ?? 'no-repeat')
            ),

            'headerLogoMode' => self::normalizeHeaderLogoMode(
                (string)($settings['headerLogoMode'] ?? 'image')
            ),

            'logoSize' => self::normalizeLogoSize(
                (int)($settings['logoSize'] ?? 42)
            ),
        ];
    }

    protected static function withUrls(array $settings): array
    {
        $settings['logoUrl'] = '';
        $settings['backgroundUrl'] = '';

        if (class_exists('CFile')) {
            if (!empty($settings['logoFileId'])) {
                $settings['logoUrl'] = (string)CFile::GetPath((int)$settings['logoFileId']);
            }

            if (!empty($settings['backgroundFileId'])) {
                $settings['backgroundUrl'] = (string)CFile::GetPath((int)$settings['backgroundFileId']);
            }
        }

        return $settings;
    }

    protected static function validateUploadFile(array $file): void
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('UPLOAD_ERROR_' . $error);
        }

        $size = (int)($file['size'] ?? 0);

        if ($size <= 0) {
            throw new RuntimeException('EMPTY_FILE');
        }

        if ($size > self::MAX_FILE_SIZE) {
            throw new RuntimeException('FILE_TOO_LARGE');
        }

        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('BAD_FILE_EXTENSION');
        }

        $mime = '';

        if (!empty($file['type'])) {
            $mime = strtolower((string)$file['type']);
        }

        if ($mime !== '' && !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('BAD_FILE_MIME_TYPE');
        }
    }

    protected static function defaultDesignTokens(): array
    {
        return [
            'secondaryColor' => '#0f172a',
            'textColor' => '#0f172a',
            'mutedColor' => '#64748b',
            'surfaceColor' => '#ffffff',
            'borderColor' => '#e2e8f0',
            'headingFont' => 'system',
            'bodyFont' => 'system',
            'baseFontSize' => 16,
            'bodyLineHeight' => 1.6,
            'headingWeight' => 800,
            'radiusScale' => 16,
            'buttonRadius' => 12,
            'sectionGap' => 24,
            'shadowPreset' => 'soft',
        ];
    }

    protected static function normalizeFontFamily(string $font): string
    {
        $font = trim(strtolower($font));
        $allowed = ['system', 'arial', 'georgia', 'times', 'mono'];
        return in_array($font, $allowed, true) ? $font : 'system';
    }

    protected static function normalizeShadowPreset(string $preset): string
    {
        $preset = trim(strtolower($preset));
        $allowed = ['none', 'soft', 'medium', 'strong'];
        return in_array($preset, $allowed, true) ? $preset : 'soft';
    }

    protected static function normalizeHeadingWeight(int $weight): int
    {
        $allowed = [500, 600, 700, 800, 900];
        return in_array($weight, $allowed, true) ? $weight : 800;
    }

    protected static function normalizeRangeInt(int $value, int $min, int $max, int $fallback): int
    {
        if ($value < $min || $value > $max) {
            return $fallback;
        }
        return $value;
    }

    protected static function normalizeRangeFloat(float $value, float $min, float $max, float $fallback): float
    {
        if (!is_finite($value) || $value < $min || $value > $max) {
            return $fallback;
        }
        return round($value, 2);
    }

    protected static function normalizeAssetType(string $type): string
    {
        $type = trim($type);

        if (!in_array($type, ['logo', 'background'], true)) {
            throw new RuntimeException('BAD_ASSET_TYPE');
        }

        return $type;
    }

    protected static function normalizeColor(string $color, string $fallback): string
    {
        $color = trim($color);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return strtolower($color);
        }

        if (preg_match('/^#[0-9a-fA-F]{3}$/', $color)) {
            return strtolower($color);
        }

        return $fallback;
    }

    protected static function normalizeBackgroundMode(string $mode): string
    {
        $mode = trim($mode);

        $allowed = [
            'cover',
            'contain',
            'auto',
            'stretch',
        ];

        return in_array($mode, $allowed, true) ? $mode : 'cover';
    }

    protected static function normalizeBackgroundPosition(string $position): string
    {
        $position = trim($position);

        $allowed = [
            'center center',
            'top center',
            'bottom center',
            'left center',
            'right center',
        ];

        return in_array($position, $allowed, true) ? $position : 'center center';
    }

    protected static function normalizeBackgroundRepeat(string $repeat): string
    {
        $repeat = trim($repeat);

        $allowed = [
            'no-repeat',
            'repeat',
            'repeat-x',
            'repeat-y',
        ];

        return in_array($repeat, $allowed, true) ? $repeat : 'no-repeat';
    }

    protected static function normalizeHeaderLogoMode(string $mode): string
    {
        $mode = trim($mode);

        $allowed = [
            'image',
            'text',
            'both',
        ];

        return in_array($mode, $allowed, true) ? $mode : 'image';
    }

    protected static function normalizeLogoSize(int $size): int
    {
        if ($size < 24) {
            return 24;
        }

        if ($size > 160) {
            return 160;
        }

        return $size;
    }
}
<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage_db.php';
require_once __DIR__ . '/storage_db_extra.php';

if (!function_exists('sb_data_path')) {
    function sb_data_path(string $file): string
    {
        return $_SERVER['DOCUMENT_ROOT'] . '/upload/sitebuilder/' . $file;
    }
}

if (!function_exists('sb_json_ensure_directory')) {
    function sb_json_ensure_directory(string $path): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create JSON data directory');
        }
    }
}

if (!function_exists('sb_json_decode_file_unlocked')) {
    function sb_json_decode_file_unlocked(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Cannot read JSON file');
        }

        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }

        if (trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON file');
        }

        return array_values($data);
    }
}

if (!function_exists('sb_json_write_file_unlocked')) {
    function sb_json_write_file_unlocked(string $path, array $data, string $errMsg): void
    {
        $json = json_encode(
            array_values($data),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        );

        $tempPath = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        $written = file_put_contents($tempPath, $json);

        if ($written === false) {
            @unlink($tempPath);
            throw new RuntimeException($errMsg);
        }

        @chmod($tempPath, 0664);

        if (!rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new RuntimeException($errMsg);
        }
    }
}

if (!function_exists('sb_read_json_file')) {
    function sb_read_json_file(string $file): array
    {
        $path = sb_data_path($file);
        sb_json_ensure_directory($path);

        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException('Cannot open JSON lock file');
        }

        try {
            if (!flock($lock, LOCK_SH)) {
                throw new RuntimeException('Cannot lock ' . $file);
            }

            return sb_json_decode_file_unlocked($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

if (!function_exists('sb_write_json_file')) {
    function sb_write_json_file(string $file, array $data, string $errMsg): void
    {
        $path = sb_data_path($file);
        sb_json_ensure_directory($path);

        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException($errMsg);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock ' . $file);
            }

            sb_json_write_file_unlocked($path, $data, $errMsg);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

if (!function_exists('sb_mutate_json_file')) {
    /**
     * Выполняет read-modify-write под одной LOCK_EX-блокировкой.
     * Callback получает массив по ссылке и может вернуть произвольный результат.
     */
    function sb_mutate_json_file(string $file, callable $mutator, string $errMsg = 'Cannot update JSON file')
    {
        $path = sb_data_path($file);
        sb_json_ensure_directory($path);

        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException($errMsg);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock ' . $file);
            }

            $data = sb_json_decode_file_unlocked($path);
            $result = $mutator($data);
            sb_json_write_file_unlocked($path, $data, $errMsg);

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

if (!function_exists('sb_read_sites')) {
    function sb_read_sites(): array { return sb_read_json_file('sites.json'); }
}
if (!function_exists('sb_write_sites')) {
    function sb_write_sites(array $sites): void { sb_write_json_file('sites.json', $sites, 'Cannot open sites.json'); }
}

if (!function_exists('sb_read_pages')) {
    function sb_read_pages(): array { return sb_read_json_file('pages.json'); }
}
if (!function_exists('sb_write_pages')) {
    function sb_write_pages(array $pages): void { sb_write_json_file('pages.json', $pages, 'Cannot open pages.json'); }
}

if (!function_exists('sb_read_blocks')) {
    function sb_read_blocks(): array { return sb_read_json_file('blocks.json'); }
}
if (!function_exists('sb_write_blocks')) {
    function sb_write_blocks(array $blocks): void { sb_write_json_file('blocks.json', $blocks, 'Cannot open blocks.json'); }
}

if (!function_exists('sb_read_access')) {
    function sb_read_access(): array { return sb_read_json_file('access.json'); }
}
if (!function_exists('sb_write_access')) {
    function sb_write_access(array $access): void { sb_write_json_file('access.json', $access, 'Cannot open access.json'); }
}

if (!function_exists('sb_read_menus')) {
    function sb_read_menus(): array { return sb_read_json_file('menus.json'); }
}
if (!function_exists('sb_write_menus')) {
    function sb_write_menus(array $menus): void { sb_write_json_file('menus.json', $menus, 'Cannot open menus.json'); }
}

if (!function_exists('sb_read_templates')) {
    function sb_read_templates(): array { return sb_read_json_file('templates.json'); }
}
if (!function_exists('sb_write_templates')) {
    function sb_write_templates(array $templates): void { sb_write_json_file('templates.json', $templates, 'Cannot open templates.json'); }
}

if (!function_exists('sb_read_layouts')) {
    function sb_read_layouts(): array { return sb_read_json_file('layouts.json'); }
}
if (!function_exists('sb_write_layouts')) {
    function sb_write_layouts(array $layouts): void { sb_write_json_file('layouts.json', $layouts, 'Cannot open layouts.json'); }
}
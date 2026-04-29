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

if (!function_exists('sb_read_json_file')) {
    function sb_read_json_file(string $file): array
    {
        $path = sb_data_path($file);
        if (!file_exists($path)) {
            return [];
        }

        $fp = fopen($path, 'rb');
        if (!$fp) {
            return [];
        }

        $raw = '';
        if (flock($fp, LOCK_SH)) {
            $raw = stream_get_contents($fp);
            flock($fp, LOCK_UN);
        } else {
            $raw = stream_get_contents($fp);
        }

        fclose($fp);

        // Удаляем BOM, если вдруг файл с ним
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }

        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('sb_write_json_file')) {
    function sb_write_json_file(string $file, array $data, string $errMsg): void
    {
        $dir = dirname(sb_data_path($file));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = sb_data_path($file);
        $fp = fopen($path, 'c+');
        if (!$fp) {
            throw new RuntimeException($errMsg);
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException('Cannot lock ' . $file);
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode(array_values($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
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
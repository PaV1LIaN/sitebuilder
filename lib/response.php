<?php

if (!function_exists('sb_json_response')) {
    function sb_json_response(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('sb_json_ok')) {
    function sb_json_ok(array $data = []): void
    {
        sb_json_response(array_merge(['ok' => true], $data), 200);
    }
}

if (!function_exists('sb_json_error')) {
    function sb_json_error(string $error, int $status = 400, array $extra = []): void
    {
        sb_json_response(array_merge([
            'ok' => false,
            'error' => $error,
        ], $extra), $status);
    }
}
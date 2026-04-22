<?php

class DiskResponse
{
    public static function success(array $data = [], array $meta = []): void
    {
        self::send([
            'ok' => true,
            'data' => $data,
            'meta' => $meta,
            'error' => null,
            'message' => '',
        ]);
    }

    public static function error(string $errorCode, string $message = '', array $details = []): void
    {
        self::send([
            'ok' => false,
            'data' => [],
            'meta' => [],
            'error' => $errorCode,
            'message' => $message,
            'details' => $details,
        ]);
    }

    protected static function send(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
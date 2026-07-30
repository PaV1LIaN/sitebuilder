<?php

if (!function_exists('sb_json_response')) {
    function sb_json_response(array $payload, int $status = 200): void
    {
        /*
         * Сначала кодируем ответ, затем фиксируем транзакцию.
         * Так ошибка сериализации не оставит клиент без ответа после COMMIT.
         */
        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            error_log('SiteBuilder JSON response encoding failed: ' . $e->getMessage());

            if (function_exists('sb_db_rollback_request_transaction')) {
                sb_db_rollback_request_transaction();
            }

            $status = 500;
            $json = '{"ok":false,"error":"RESPONSE_ENCODING_FAILED"}';
        }

        if ($status >= 400) {
            if (function_exists('sb_db_rollback_request_transaction')) {
                sb_db_rollback_request_transaction();
            }

            /* Ошибку пишем после rollback отдельной autocommit-операцией. */
            if (class_exists('AuditLogService')) {
                AuditLogService::recordResponse($payload, $status);
            }
        } else {
            /* Успешная запись журнала входит в ту же транзакцию, что изменение. */
            if (class_exists('AuditLogService')) {
                AuditLogService::recordResponse($payload, $status);
            }

            if (function_exists('sb_db_commit_request_transaction')) {
                sb_db_commit_request_transaction();
            }
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo $json;
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

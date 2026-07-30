<?php

return [
    /* Максимальное ожидание глобальной блокировки миграций. */
    'migration_lock_timeout_seconds' => 15,

    /* Минимальные требования окружения. */
    'minimum_php_version' => '8.1.0',
    'minimum_postgresql_version' => '12.0',

    /* Предупреждение, если worker не присылал heartbeat дольше этого времени. */
    'worker_heartbeat_warning_seconds' => 180,

    /* Каталоги, которые должны существовать и быть доступны на запись. */
    'writable_paths' => [
        '/upload/sitebuilder',
    ],
];

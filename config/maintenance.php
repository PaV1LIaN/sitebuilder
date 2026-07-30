<?php

return [
    /* Храним ревизии 180 дней, но всегда оставляем последнюю ревизию сущности. */
    'revision_retention_days' => 180,

    /* Невосстановленные и восстановленные снимки корзины старше 30 дней удаляются. */
    'recycle_bin_retention_days' => 30,

    /* Журнал действий хранится один год. */
    'audit_log_retention_days' => 365,

    /* Успешные внешние задания хранятся 30 дней, ошибки/отменённые — 90 дней. */
    'outbox_succeeded_retention_days' => 30,
    'outbox_terminal_retention_days' => 90,

    /* История запусков worker хранится 90 дней. */
    'queue_worker_run_retention_days' => 90,
    'queue_worker_state_retention_days' => 7,

    /* Операционные данные этапа 11. */
    'system_alert_resolved_retention_days' => 180,
    'system_alert_delivery_retention_days' => 90,
    'external_reconcile_run_retention_days' => 90,
    'external_resource_deleted_retention_days' => 30,

    /* Просроченные резервные копии удаляются по expires_at из config/backup.php. */

    /* Автоматическая очистка запускается не чаще одного раза в сутки. */
    'auto_cleanup_interval_seconds' => 86400,

    /* Ограничение нагрузки одного запуска. */
    'batch_size' => 2000,
    'max_batches' => 5,
];

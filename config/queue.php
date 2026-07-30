<?php

return [
    /* Количество заданий за один запуск worker. */
    'batch_size' => 20,

    /* Сколько секунд running-задание может не обновляться до возврата в retry. */
    'running_timeout_seconds' => 900,

    /* Базовая задержка повторной попытки. Фактическая задержка растёт экспоненциально. */
    'retry_base_seconds' => 15,

    /* Максимальная задержка между попытками. */
    'retry_max_seconds' => 3600,

    /* Максимальный размер текста результата/ошибки, сохраняемого в БД. */
    'max_result_bytes' => 32768,

    /* Health-check: допустимая задержка heartbeat worker. */
    'heartbeat_warning_seconds' => 180,
    'heartbeat_critical_seconds' => 900,

    /* Health-check: возраст готового, но не обработанного задания. */
    'oldest_ready_warning_seconds' => 300,
    'oldest_ready_critical_seconds' => 900,
];

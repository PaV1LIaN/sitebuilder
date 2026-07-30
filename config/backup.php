<?php

return [
    /*
     * Новые копии хранятся вне DOCUMENT_ROOT. Пустое значение означает:
     * dirname(DOCUMENT_ROOT) . '/sitebuilder-private/backups'.
     */
    'absolute_directory' => '',

    /* Старый каталог используется только для переноса копий этапа 12. */
    'relative_directory' => '/upload/sitebuilder/backups',

    /* Ограничения защищают от случайного создания слишком больших пакетов. */
    'max_uncompressed_bytes' => 50 * 1024 * 1024,
    'max_stored_bytes' => 25 * 1024 * 1024,

    /* Ручные копии по умолчанию хранятся 90 дней. */
    'retention_days' => 90,

    /* Не более 200 записей в одном ответе списка. */
    'list_limit' => 100,
];

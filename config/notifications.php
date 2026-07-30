<?php

return [
    /* Внутренние оповещения в таблице system_alert работают всегда. */
    'external_delivery_enabled' => false,

    /* Наружу отправляются только оповещения не ниже этого уровня. */
    'minimum_severity' => 'critical',

    /* Повторная доставка одного активного оповещения не чаще указанного интервала. */
    'cooldown_seconds' => 3600,

    /* ID пользователей Битрикс24 для системных IM-уведомлений. */
    'bitrix_user_ids' => [],

    /* Email-адреса для уведомлений. */
    'email_addresses' => [],

    'subject_prefix' => '[SiteBuilder]',
];

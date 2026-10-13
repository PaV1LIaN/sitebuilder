<?php

/*
 * Server-local overrides. Copy to config/auth.local.php on the portal.
 * auth.local.php is excluded from Git and not overwritten by a normal upload
 * of repository files.
 */
return [
    'admin_user_ids' => [
        // Bitrix user IDs of global SiteBuilder administrators (not portal admins).
    ],

    // Bitrix service guest account ID, if different from config/auth.php.
    // 'guest_user_id' => 0,
];

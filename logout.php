<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT']
    . '/bitrix/modules/main/include/prolog_before.php';

require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/auth.php';

global $USER;

/*
 * Выход разрешаем только POST-запросом с CSRF-токеном.
 */
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && check_bitrix_sessid()
) {
    if ($USER->IsAuthorized()) {
        $USER->Logout();
    }
}

LocalRedirect(
    (string)sitebuilder_auth_config()['login_url']
);

exit;

Кнопка выхода на странице SiteBuilder:

<form
    method="post"
    action="/local/sitebuilder/logout.php"
    style="display:inline"
>
    <?= bitrix_sessid_post() ?>

    <button type="submit">
        Выйти
    </button>
</form>
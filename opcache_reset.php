<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

sitebuilder_require_bitrix_admin();

header('Content-Type: text/plain; charset=UTF-8');

if (function_exists('opcache_reset')) {
    var_dump(opcache_reset());
} else {
    echo "opcache_reset unavailable";
}
<?php
header('Content-Type: text/plain; charset=UTF-8');

if (function_exists('opcache_reset')) {
    var_dump(opcache_reset());
} else {
    echo "opcache_reset unavailable";
}
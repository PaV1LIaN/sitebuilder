<?php

$requestedPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
$prefix = '/local/sitebuilder/s/';

if (!str_starts_with($requestedPath, $prefix) || strlen($requestedPath) > 4096) {
    http_response_code(404);
    exit('Not found');
}

$route = trim(substr($requestedPath, strlen($prefix)), '/');
$parts = $route === '' ? [] : explode('/', $route);

if (empty($parts) || count($parts) > 65) {
    http_response_code(404);
    exit('Not found');
}

$siteSlug = rawurldecode((string)array_shift($parts));

if (
    $siteSlug === ''
    || strlen($siteSlug) > 255
    || str_contains($siteSlug, '/')
    || str_contains($siteSlug, '\\')
    || preg_match('/[\x00-\x1F\x7F?#]/u', $siteSlug)
) {
    http_response_code(404);
    exit('Not found');
}

$pagePath = implode('/', $parts);

$_GET['sbSiteSlug'] = $siteSlug;
$_GET['sbPagePath'] = $pagePath;
$_REQUEST['sbSiteSlug'] = $siteSlug;
$_REQUEST['sbPagePath'] = $pagePath;
$GLOBALS['SB_PRETTY_PUBLIC_ROUTE'] = true;

require __DIR__ . '/public.php';

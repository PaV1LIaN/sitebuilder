<?php

/**
 * Возвращает настройки авторизации SiteBuilder.
 */
function sitebuilder_auth_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configFile = $_SERVER['DOCUMENT_ROOT']
        . '/local/sitebuilder/config/auth.php';

    if (!is_file($configFile)) {
        throw new RuntimeException(
            'Не найден файл конфигурации SiteBuilder: ' . $configFile
        );
    }

    $loadedConfig = require $configFile;

    if (!is_array($loadedConfig)) {
        throw new RuntimeException(
            'Файл конфигурации SiteBuilder должен возвращать массив.'
        );
    }

    $config = array_merge(
        [
            'guest_user_id' => 0,
            'default_after_login' => '/local/sitebuilder/index.php',
            'login_url' => '/local/sitebuilder/login.php',
            'logout_url' => '/local/sitebuilder/logout.php',
            'allowed_return_prefix' => '/local/sitebuilder/',
        ],
        $loadedConfig
    );

    return $config;
}

/**
 * Проверяет и нормализует адрес возврата после авторизации.
 *
 * Разрешаем переход только внутри /local/sitebuilder/.
 */
function sitebuilder_auth_return_url(?string $url): string
{
    $config = sitebuilder_auth_config();
    $defaultUrl = (string)$config['default_after_login'];

    $url = trim((string)$url);

    if ($url === '') {
        return $defaultUrl;
    }

    /*
     * Защита от подстановки заголовков.
     */
    if (
        str_contains($url, "\r")
        || str_contains($url, "\n")
        || str_contains($url, "\0")
    ) {
        return $defaultUrl;
    }

    /*
     * Адрес должен начинаться с одного слеша.
     * //example.com считается внешним адресом.
     */
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
        return $defaultUrl;
    }

    $parts = parse_url($url);

    if ($parts === false) {
        return $defaultUrl;
    }

    /*
     * Запрещаем абсолютные внешние URL.
     */
    if (
        isset($parts['scheme'])
        || isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        return $defaultUrl;
    }

    $path = (string)($parts['path'] ?? '');

    $allowedPrefix = (string)$config['allowed_return_prefix'];

    if (!str_starts_with($path, $allowedPrefix)) {
        return $defaultUrl;
    }

    /*
     * Не разрешаем возвращаться обратно на вход или выход,
     * иначе можно получить цикл перенаправлений.
     */
    $blockedPaths = [
        rtrim((string)$config['login_url'], '/'),
        rtrim((string)$config['logout_url'], '/'),
    ];

    if (in_array(rtrim($path, '/'), $blockedPaths, true)) {
        return $defaultUrl;
    }

    $result = $path;

    if (isset($parts['query']) && $parts['query'] !== '') {
        $result .= '?' . $parts['query'];
    }

    return $result;
}

/**
 * Формирует ссылку на страницу авторизации.
 */
function sitebuilder_auth_login_url(?string $returnUrl = null): string
{
    $config = sitebuilder_auth_config();

    $returnUrl = sitebuilder_auth_return_url($returnUrl);

    return (string)$config['login_url']
        . '?'
        . http_build_query([
            'return' => $returnUrl,
        ]);
}

/**
 * Проверяет, авторизован ли пользователь.
 */
function sitebuilder_is_authorized(): bool
{
    global $USER;

    return is_object($USER) && $USER->IsAuthorized();
}

/**
 * Проверяет, является ли пользователь техническим гостем.
 */
function sitebuilder_is_guest(): bool
{
    global $USER;

    if (!sitebuilder_is_authorized()) {
        return false;
    }

    $config = sitebuilder_auth_config();
    $guestUserId = (int)$config['guest_user_id'];

    return $guestUserId > 0
        && (int)$USER->GetID() === $guestUserId;
}

/**
 * Требует авторизацию на обычной HTML-странице.
 */
function sitebuilder_require_auth(): void
{
    if (sitebuilder_is_authorized()) {
        return;
    }

    $currentUrl = (string)(
        $_SERVER['REQUEST_URI']
        ?? sitebuilder_auth_config()['default_after_login']
    );

    LocalRedirect(sitebuilder_auth_login_url($currentUrl));
    exit;
}

/**
 * Требует авторизацию в AJAX/API-обработчике.
 */
function sitebuilder_require_api_auth(): void
{
    if (sitebuilder_is_authorized()) {
        return;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        [
            'ok' => false,
            'success' => false,
            'error' => 'AUTH_REQUIRED',
            'message' => 'Требуется авторизация.',
            'loginUrl' => sitebuilder_auth_login_url(
                (string)($_SERVER['HTTP_REFERER'] ?? '')
            ),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * Требует авторизацию администратора Битрикс.
 * Используется только для диагностических и миграционных страниц проекта.
 */
function sitebuilder_require_bitrix_admin(): void
{
    global $USER;

    sitebuilder_require_auth();

    if (is_object($USER) && $USER->IsAdmin()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ACCESS_DENIED';
    exit;
}

/**
 * Авторизует технического пользователя.
 *
 * Возвращает:
 * [
 *     'success' => bool,
 *     'message' => string
 * ]
 */
function sitebuilder_authorize_guest(): array
{
    global $USER;

    if (!is_object($USER) || !method_exists($USER, 'Authorize')) {
        return [
            'success' => false,
            'message' => 'Механизм авторизации Битрикс недоступен.',
        ];
    }

    $config = sitebuilder_auth_config();
    $guestUserId = (int)$config['guest_user_id'];

    if ($guestUserId <= 0) {
        return [
            'success' => false,
            'message' => 'В конфигурации не указан ID гостевого пользователя.',
        ];
    }

    $userResult = CUser::GetByID($guestUserId);
    $guestUser = $userResult->Fetch();

    if (!$guestUser) {
        return [
            'success' => false,
            'message' => 'Технический пользователь не найден.',
        ];
    }

    if (($guestUser['ACTIVE'] ?? 'N') !== 'Y') {
        return [
            'success' => false,
            'message' => 'Технический пользователь деактивирован.',
        ];
    }

    /*
     * Защищаемся от случайного назначения гостю
     * группы администраторов Битрикс.
     *
     * Группа с ID 1 — администраторы.
     */
    $guestGroups = array_map(
        'intval',
        CUser::GetUserGroup($guestUserId)
    );

    if (in_array(1, $guestGroups, true)) {
        return [
            'success' => false,
            'message' => 'Гостевой пользователь не должен быть администратором.',
        ];
    }

    /*
     * Второй параметр false означает:
     * не создавать постоянную авторизацию "Запомнить меня".
     */
    $authorized = $USER->Authorize($guestUserId, false, true);

    if (!$authorized) {
        return [
            'success' => false,
            'message' => 'Не удалось выполнить гостевую авторизацию.',
        ];
    }

    return [
        'success' => true,
        'message' => '',
    ];
}

/**
 * Преобразует ошибку CUser::Login в обычный текст.
 */
function sitebuilder_login_error_message(mixed $result): string
{
    if (is_array($result)) {
        return trim(
            (string)(
                $result['MESSAGE']
                ?? $result['message']
                ?? 'Неверный логин или пароль.'
            )
        );
    }

    if (is_string($result) && trim($result) !== '') {
        return trim($result);
    }

    return 'Неверный логин или пароль.';
}

<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT']
    . '/bitrix/modules/main/include/prolog_before.php';

require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/auth.php';

global $USER;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: SAMEORIGIN');

$returnUrl = sitebuilder_auth_return_url(
    (string)($_REQUEST['return'] ?? '')
);

/*
 * Если пользователь уже вошёл в Битрикс,
 * сразу возвращаем его в SiteBuilder.
 */
if ($USER->IsAuthorized()) {
    LocalRedirect($returnUrl);
    exit;
}

$errorMessage = '';
$loginValue = '';

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
) {
    if (!check_bitrix_sessid()) {
        $errorMessage = 'Сессия устарела. Обновите страницу и попробуйте снова.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'guest') {
            $guestResult = sitebuilder_authorize_guest();

            if ($guestResult['success']) {
                LocalRedirect($returnUrl);
                exit;
            }

            $errorMessage = $guestResult['message'];
        } elseif ($action === 'login') {
            $loginValue = trim((string)($_POST['login'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($loginValue === '') {
                $errorMessage = 'Введите логин.';
            } elseif ($password === '') {
                $errorMessage = 'Введите пароль.';
            } else {
                /*
                 * N — не запоминать пользователя после закрытия браузера.
                 */
                $loginResult = $USER->Login(
                    $loginValue,
                    $password,
                    'N'
                );

                if ($loginResult === true) {
                    LocalRedirect($returnUrl);
                    exit;
                }

                $errorMessage = sitebuilder_login_error_message(
                    $loginResult
                );
            }
        } else {
            $errorMessage = 'Неизвестный способ авторизации.';
        }
    }
}

function sitebuilderLoginEscape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Вход в SiteBuilder</title>

    <link rel="stylesheet" href="assets/admin/pages/login.css?v=20260917">
</head>

<body>
<div class="auth-layout">
    <section class="auth-info">
        <div class="auth-logo">
            <span class="auth-logo__icon">S</span>
            <span>SiteBuilder</span>
        </div>

        <h1>Конструктор корпоративных страниц</h1>

        <p>
            Войдите с помощью учётной записи Битрикс24
            или откройте SiteBuilder в гостевом режиме.
        </p>
    </section>

    <main class="auth-card">
        <h2>Вход</h2>

        <p class="auth-card__description">
            Используйте логин и пароль от вашей учётной записи Битрикс.
        </p>

        <?php if ($errorMessage !== ''): ?>
            <div class="auth-error">
                <?= sitebuilderLoginEscape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?= bitrix_sessid_post() ?>

            <input
                type="hidden"
                name="action"
                value="login"
            >

            <input
                type="hidden"
                name="return"
                value="<?= sitebuilderLoginEscape($returnUrl) ?>"
            >

            <div class="field">
                <label for="sitebuilder-login">
                    Логин
                </label>

                <input
                    id="sitebuilder-login"
                    type="text"
                    name="login"
                    value="<?= sitebuilderLoginEscape($loginValue) ?>"
                    autocomplete="username"
                    autofocus
                    required
                >
            </div>

            <div class="field">
                <label for="sitebuilder-password">
                    Пароль
                </label>

                <input
                    id="sitebuilder-password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button
                class="button button--primary"
                type="submit"
            >
                Войти
            </button>
        </form>

        <div class="divider">
            или
        </div>

        <form method="post" action="">
            <?= bitrix_sessid_post() ?>

            <input
                type="hidden"
                name="action"
                value="guest"
            >

            <input
                type="hidden"
                name="return"
                value="<?= sitebuilderLoginEscape($returnUrl) ?>"
            >

            <button
                class="button button--guest"
                type="submit"
            >
                Войти как гость
            </button>
        </form>

        <p class="guest-note">
            Гостевой пользователь получает только те права,
            которые назначены ему в административной части SiteBuilder.
        </p>
    </main>
</div>
</body>
</html>

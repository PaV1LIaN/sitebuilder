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

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
            margin: 0;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;

            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Arial,
                sans-serif;

            color: #1f2937;
            background:
                radial-gradient(
                    circle at top left,
                    rgba(37, 99, 235, 0.16),
                    transparent 36%
                ),
                #f3f6fb;
        }

        .auth-layout {
            width: 100%;
            max-width: 960px;

            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(320px, 420px);

            overflow: hidden;

            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 24px;

            box-shadow:
                0 24px 70px rgba(15, 23, 42, 0.14);
        }

        .auth-info {
            position: relative;
            padding: 56px;

            color: #ffffff;
            background:
                linear-gradient(
                    145deg,
                    #1d4ed8,
                    #2563eb 55%,
                    #3b82f6
                );
        }

        .auth-info::after {
            content: "";

            position: absolute;
            right: -80px;
            bottom: -110px;

            width: 280px;
            height: 280px;

            border: 50px solid rgba(255, 255, 255, 0.09);
            border-radius: 50%;
        }

        .auth-logo {
            position: relative;
            z-index: 1;

            display: inline-flex;
            align-items: center;
            gap: 12px;

            margin-bottom: 64px;

            font-size: 22px;
            font-weight: 700;
        }

        .auth-logo__icon {
            display: flex;
            align-items: center;
            justify-content: center;

            width: 42px;
            height: 42px;

            color: #2563eb;
            background: #ffffff;
            border-radius: 12px;

            font-size: 20px;
            font-weight: 800;
        }

        .auth-info h1 {
            position: relative;
            z-index: 1;

            max-width: 440px;
            margin: 0 0 20px;

            font-size: 42px;
            line-height: 1.12;
        }

        .auth-info p {
            position: relative;
            z-index: 1;

            max-width: 460px;
            margin: 0;

            color: rgba(255, 255, 255, 0.82);

            font-size: 17px;
            line-height: 1.65;
        }

        .auth-card {
            padding: 48px 44px;
        }

        .auth-card h2 {
            margin: 0 0 8px;

            font-size: 28px;
            line-height: 1.25;
        }

        .auth-card__description {
            margin: 0 0 30px;

            color: #6b7280;

            font-size: 14px;
            line-height: 1.5;
        }

        .auth-error {
            margin-bottom: 22px;
            padding: 13px 15px;

            color: #991b1b;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;

            font-size: 14px;
            line-height: 1.45;
        }

        .field {
            margin-bottom: 18px;
        }

        .field label {
            display: block;

            margin-bottom: 8px;

            font-size: 14px;
            font-weight: 600;
        }

        .field input {
            width: 100%;
            height: 48px;

            padding: 0 14px;

            color: #111827;
            background: #ffffff;

            border: 1px solid #d1d5db;
            border-radius: 10px;

            outline: none;

            font-size: 15px;

            transition:
                border-color 0.15s ease,
                box-shadow 0.15s ease;
        }

        .field input:focus {
            border-color: #2563eb;
            box-shadow:
                0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            width: 100%;
            min-height: 48px;

            padding: 12px 18px;

            border: 0;
            border-radius: 10px;

            cursor: pointer;

            font-family: inherit;
            font-size: 15px;
            font-weight: 650;

            transition:
                transform 0.12s ease,
                opacity 0.12s ease,
                background-color 0.12s ease;
        }

        .button:hover {
            opacity: 0.92;
        }

        .button:active {
            transform: translateY(1px);
        }

        .button--primary {
            color: #ffffff;
            background: #2563eb;
        }

        .button--guest {
            color: #1f2937;
            background: #eef2f7;
            border: 1px solid #dce3ec;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 14px;

            margin: 24px 0;

            color: #9ca3af;

            font-size: 12px;
            text-transform: uppercase;
        }

        .divider::before,
        .divider::after {
            content: "";

            flex: 1;

            height: 1px;

            background: #e5e7eb;
        }

        .guest-note {
            margin: 14px 0 0;

            color: #6b7280;

            font-size: 12px;
            line-height: 1.5;
            text-align: center;
        }

        @media (max-width: 780px) {
            body {
                align-items: flex-start;
                padding: 14px;
            }

            .auth-layout {
                grid-template-columns: 1fr;
                border-radius: 18px;
            }

            .auth-info {
                padding: 32px;
            }

            .auth-logo {
                margin-bottom: 32px;
            }

            .auth-info h1 {
                font-size: 30px;
            }

            .auth-card {
                padding: 32px 26px;
            }
        }
    </style>
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

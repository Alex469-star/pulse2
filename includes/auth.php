<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';

function auth_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name((string)config('session_name'));
        session_start();
    }
}

function current_user(): ?array
{
    static $user = null;
    if ($user !== null) return $user;
    if (empty($_SESSION['user_id'])) return null;
    $user = User::findById((int)$_SESSION['user_id']);
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('Сначала войдите в систему', 'info');
        redirect(url('login.php'));
    }
    return $user;
}

/**
 * Требует, чтобы email был подтверждён.
 * Исключения (не требуют подтверждения):
 *  - страница verify-email.php,
 *  - страница logout.php,
 *  - сама login/register.
 */
function require_verified(): array
{
    $user = require_login();

    if ((int)($user['email_verified'] ?? 0) === 0) {
        // Разрешаем доступ только к verify-email.php и logout.php
        $allowed = ['verify-email.php', 'logout.php', 'login.php', 'register.php'];
        $current = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');

        if (!in_array($current, $allowed, true)) {
            flash('Подтвердите email — мы отправили письмо при регистрации.', 'info');
            redirect(url('verify-email.php'));
        }
    }

    return $user;
}

function login_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
    session_regenerate_id(true);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function is_admin(?array $user): bool
{
    return false;
}
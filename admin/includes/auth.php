<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

function admin_session_name(): string
{
    return 'pulse_admin_session';
}

function admin_auth_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(admin_session_name());
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function admin_current(): ?array
{
    admin_auth_start();
    static $cached = null;
    static $loaded = false;
    if ($loaded) return $cached;

    $loaded = true;
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) return $cached = null;

    $stmt = db()->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['admin_id']);
        return $cached = null;
    }
    return $cached = $row;
}

function admin_login(string $email, string $password): array
{
    admin_auth_start();
    $stmt = db()->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $admin = $stmt->fetch();

    // Защита от перебора
    if ($admin && $admin['locked_until'] && strtotime((string)$admin['locked_until']) > time()) {
        return ['ok' => false, 'error' => 'Аккаунт временно заблокирован'];
    }

    if (!$admin || !password_verify($password, (string)$admin['password_hash'])) {
        if ($admin) {
            $failed = (int)$admin['failed_attempts'] + 1;
            $lockedUntil = $failed >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
            db()->prepare('UPDATE admins SET failed_attempts = ?, locked_until = ? WHERE id = ?')
                ->execute([$failed, $lockedUntil, (int)$admin['id']]);
        }
        return ['ok' => false, 'error' => 'Неверный email или пароль'];
    }

    if (!$admin['is_active']) {
        return ['ok' => false, 'error' => 'Аккаунт отключён'];
    }

    // Проверка IP allowlist
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($admin['ip_allowlist'])) {
        $allowed = array_filter(array_map('trim', explode(',', (string)$admin['ip_allowlist'])));
        if (!in_array($ip, $allowed, true)) {
            return ['ok' => false, 'error' => 'IP не разрешён'];
        }
    }

    // Успех
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_last_activity'] = time();

    db()->prepare(
        'UPDATE admins
            SET failed_attempts = 0, locked_until = NULL,
                last_login_at = NOW(), last_login_ip = ?
          WHERE id = ?'
    )->execute([$ip, (int)$admin['id']]);

    admin_audit((int)$admin['id'], 'login', null, null, null);

    return ['ok' => true];
}

function admin_logout(): void
{
    admin_auth_start();
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id) admin_audit($id, 'logout', null, null, null);
    $_SESSION = [];
    session_destroy();
}

function admin_require(string $minRole = 'readonly'): array
{
    $admin = admin_current();
    if (!$admin) {
        header('Location: ' . admin_url('index.php'));
        exit;
    }

    // Таймаут 60 минут без активности
    if (isset($_SESSION['admin_last_activity'])
        && (time() - (int)$_SESSION['admin_last_activity']) > 3600) {
        admin_logout();
        header('Location: ' . admin_url('index.php?timeout=1'));
        exit;
    }
    $_SESSION['admin_last_activity'] = time();

    $rank = ['readonly' => 1, 'moderator' => 2, 'super' => 3];
    if (($rank[$admin['role']] ?? 0) < ($rank[$minRole] ?? 0)) {
        http_response_code(403);
        exit('Недостаточно прав');
    }
    return $admin;
}

function admin_url(string $path = ''): string
{
    return rtrim((string)config('base_url'), '/') . '/admin/' . ltrim($path, '/');
}

function admin_csrf(): string
{
    admin_auth_start();
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

function admin_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(admin_csrf()) . '">';
}

function admin_csrf_check(?string $token): void
{
    admin_auth_start();
    if (!$token || empty($_SESSION['admin_csrf'])
        || !hash_equals((string)$_SESSION['admin_csrf'], $token)) {
        http_response_code(419);
        exit('CSRF token mismatch');
    }
}

function admin_audit(
    ?int $adminId,
    string $action,
    ?string $targetType = null,
    ?int $targetId = null,
    ?array $payload = null
): void {
    try {
        db()->prepare(
            'INSERT INTO admin_audit_log
                (admin_id, action, target_type, target_id, payload, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $adminId,
            $action,
            $targetType,
            $targetId,
            $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (\Throwable $e) {
        // не ломаем логику из-за лога
    }
}
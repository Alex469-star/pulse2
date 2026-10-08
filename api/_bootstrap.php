<?php
declare(strict_types=1);

ini_set('display_errors', '0'); // Не показывать в продакшене
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/database.php';

// ---- Старт сессии (для браузерных запросов) ----
if (session_status() === PHP_SESSION_NONE) {
    session_name((string)config('session_name'));
    session_start();
}

// ---- Заголовки ----
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- Утилиты ----
function json_input(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $raw = file_get_contents('php://input');
    if (!$raw) {
        $cached = $_POST;
        return $cached;
    }
    $data = json_decode($raw, true);
    $cached = is_array($data) ? $data : $_POST;
    return $cached;
}

function json_ok($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Текущий пользователь:
 *  1. По сессии (браузер).
 *  2. По Bearer-токену (мобильное приложение).
 */
function api_user(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) return $user;
    $cached = true;

    // ---- 1. Сессия ----
    if (!empty($_SESSION['user_id'])) {
        try {
            $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$_SESSION['user_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $user = $row;
                return $user;
            }
        } catch (Throwable $e) {
            // пропускаем, пробуем Bearer
        }
    }

    // ---- 2. Bearer-токен ----
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return null;
    }

    $token = trim($m[1]);
    if ($token === '') return null;

    try {
        $stmt = db()->prepare('SELECT * FROM api_tokens');
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            if (password_verify($token, $row['token_hash'])) {
                if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
                    return null;
                }
                db()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')
                    ->execute([$row['id']]);

                $u = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
                $u->execute([$row['user_id']]);
                $user = $u->fetch() ?: null;
                return $user;
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function api_require_user(): array
{
    $u = api_user();
    if (!$u) {
        json_err('Unauthorized', 401);
    }
    return $u;
}

/**
 * CSRF-защита для браузерных запросов.
 * Для Bearer-токенов CSRF не нужен.
 */
function api_check_csrf(): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+.+$/i', $header)) {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$token)) {
        json_err('CSRF token mismatch', 419);
    }
}
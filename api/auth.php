<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/User.php';

// Приложение вызывает этот эндпоинт только через POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$input = json_input();

$email    = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');
$device   = trim((string)($input['device'] ?? '')) ?: null;

if ($email === '' || $password === '') {
    json_err('Email и пароль обязательны', 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_err('Некорректный email', 400);
}

try {
    $user = User::findByEmail($email);
} catch (Throwable $e) {
    json_err('Ошибка БД: ' . $e->getMessage(), 500);
}

if (!$user || !password_verify($password, (string)$user['password_hash'])) {
    json_err('Неверный email или пароль', 401);
}

if ((int)($user['email_verified'] ?? 0) === 0) {
    json_err('Подтвердите email перед входом. Проверьте почту.', 403);
}

// ---- Выдаём токен ----
$token = bin2hex(random_bytes(32));
$hash  = password_hash($token, PASSWORD_DEFAULT);

try {
    $stmt = db()->prepare(
        'INSERT INTO api_tokens (user_id, token_hash, name, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 180 DAY))'
    );
    $stmt->execute([(int)$user['id'], $hash, $device]);
} catch (Throwable $e) {
    json_err('Не удалось создать токен: ' . $e->getMessage(), 500);
}

json_ok([
    'token'           => $token,
    'expires_in_days' => 180,
    'user' => [
        'id'           => (int)$user['id'],
        'email'        => (string)$user['email'],
        'username'     => (string)$user['username'],
        'display_name' => (string)$user['display_name'],
        'avatar_url'   => $user['avatar_url'] ?: null,
    ],
]);
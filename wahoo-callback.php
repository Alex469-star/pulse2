<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

auth_start();
$me = require_login();

$config = config('wahoo');

// ---- 1. Ошибка от Wahoo ----
if (isset($_GET['error'])) {
    flash('Wahoo: ' . ($_GET['error_description'] ?? $_GET['error']), 'error');
    redirect(url('profile-edit.php'));
}

// ---- 2. Проверка state ----
$state = (string)($_GET['state'] ?? '');
$saved = $_SESSION['wahoo_oauth'] ?? null;

if (!$saved || !hash_equals((string)$saved['state'], $state)) {
    flash('Ошибка безопасности OAuth (state mismatch). Попробуйте снова.', 'error');
    unset($_SESSION['wahoo_oauth']);
    redirect(url('profile-edit.php'));
}

if ((int)$saved['user_id'] !== (int)$me['id']) {
    flash('Сессия устарела. Попробуйте снова.', 'error');
    unset($_SESSION['wahoo_oauth']);
    redirect(url('profile-edit.php'));
}

if (time() - (int)$saved['created_at'] > 600) {
    flash('Время ожидания истекло. Попробуйте снова.', 'error');
    unset($_SESSION['wahoo_oauth']);
    redirect(url('profile-edit.php'));
}

$verifier = (string)$saved['verifier'];
$code     = (string)($_GET['code'] ?? '');

if ($code === '') {
    flash('Wahoo не вернул код авторизации.', 'error');
    unset($_SESSION['wahoo_oauth']);
    redirect(url('profile-edit.php'));
}

// ---- 3. Обмен code на токены (PKCE, без client_secret) ----
$tokenPayload = [
    'client_id'     => $config['client_id'],
    'code'          => $code,
    'code_verifier' => $verifier,
    'grant_type'    => 'authorization_code',
    'redirect_uri'  => $config['redirect_uri'],
];

$res = http_request('POST', $config['token_url'], [
    'headers' => [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ],
    'body' => http_build_query($tokenPayload, '', '&', PHP_QUERY_RFC3986),
]);

if ($res['status'] !== 200 || empty($res['json']['access_token'])) {
    $errorMsg = $res['json']['error_description']
        ?? $res['json']['error']
        ?? ('HTTP ' . $res['status']);

    log_to_file('wahoo.log', sprintf(
        'Token exchange failed: status=%d body=%s',
        (int)$res['status'],
        substr((string)$res['body'], 0, 2000)
    ));

    flash('Не удалось получить токены Wahoo: ' . $errorMsg, 'error');
    unset($_SESSION['wahoo_oauth']);
    redirect(url('profile-edit.php'));
}

$tokens    = $res['json'];
$expiresIn = (int)($tokens['expires_in'] ?? 7200);

// ---- 4. Получаем Wahoo user_id (требует user_read) ----
$wahooUserId = null;
$userRes = http_request('GET', $config['api_base'] . '/v1/user', [
    'headers' => [
        'Authorization: Bearer ' . $tokens['access_token'],
        'Accept: application/json',
    ],
]);

if ($userRes['status'] === 200 && isset($userRes['json']['id'])) {
    $wahooUserId = (int)$userRes['json']['id'];
} else {
    log_to_file('wahoo.log', sprintf(
        'Get user failed: status=%d body=%s',
        (int)$userRes['status'],
        substr((string)$userRes['body'], 0, 1000)
    ));
    // Не критично — можно работать и без wahoo_user_id, но webhook'и не будут находить юзера
}

// ---- 5. Сохраняем токены в БД ----
try {
    db()->prepare(
        'UPDATE users
         SET wahoo_user_id       = :wid,
             wahoo_access_token  = :at,
             wahoo_refresh_token = :rt,
             wahoo_code_verifier = :ver,
             wahoo_expires_at    = :exp,
             wahoo_connected_at  = NOW()
         WHERE id = :uid'
    )->execute([
        ':wid' => $wahooUserId,
        ':at'  => $tokens['access_token'],
        ':rt'  => $tokens['refresh_token'] ?? null,
        ':ver' => $verifier,
        ':exp' => date('Y-m-d H:i:s', time() + $expiresIn),
        ':uid' => (int)$me['id'],
    ]);
} catch (Throwable $e) {
    flash('Ошибка сохранения токенов: ' . $e->getMessage(), 'error');
    unset($_SESSION['wahoo_oauth']);
    redirect(url('profile-edit.php'));
}

unset($_SESSION['wahoo_oauth']);

flash('Wahoo успешно подключён! Теперь можно синхронизировать тренировки.', 'success');
redirect(url('wahoo-sync.php'));
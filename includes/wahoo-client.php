<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Обновляет access_token, если он истёк.
 * ВАЖНО: перечитывает свежие токены из БД, чтобы не поймать invalid_grant
 * при последовательных вызовах (Wahoo отзывает старый refresh_token
 * после первого успешного API-запроса с новым токеном).
 *
 * @param array $user строка из users (нужен только id)
 * @return string|null access_token или null при ошибке
 */
function wahoo_refresh_token_for(int $userId): ?string
{
    $config = config('wahoo');

    // Свежие токены из БД
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) return null;
    if (empty($user['wahoo_access_token']) || empty($user['wahoo_refresh_token'])) return null;

    $expiresAt = !empty($user['wahoo_expires_at'])
        ? strtotime((string)$user['wahoo_expires_at'])
        : 0;

    // Токен ещё живой (с запасом 5 минут) — возвращаем как есть
    if ($expiresAt > time() + 300) {
        return (string)$user['wahoo_access_token'];
    }

    // ---- Refresh (PKCE: нужен code_verifier) ----
    $payload = [
        'client_id'     => $config['client_id'],
        'grant_type'    => 'refresh_token',
        'refresh_token' => (string)$user['wahoo_refresh_token'],
        'code_verifier' => (string)($user['wahoo_code_verifier'] ?? ''),
    ];

    $res = http_request('POST', $config['token_url'], [
        'headers' => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        'body' => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
    ]);

    if ($res['status'] !== 200 || empty($res['json']['access_token'])) {
        log_to_file('wahoo.log', sprintf(
            'Token refresh failed for user #%d: status=%d body=%s',
            $userId,
            (int)$res['status'],
            substr((string)$res['body'], 0, 2000)
        ));
        return null;
    }

    $tokens    = $res['json'];
    $expiresIn = (int)($tokens['expires_in'] ?? 7200);

    try {
        db()->prepare(
            'UPDATE users
             SET wahoo_access_token  = :at,
                 wahoo_refresh_token = :rt,
                 wahoo_expires_at    = :exp
             WHERE id = :uid'
        )->execute([
            ':at'  => $tokens['access_token'],
            ':rt'  => $tokens['refresh_token'] ?? $user['wahoo_refresh_token'],
            ':exp' => date('Y-m-d H:i:s', time() + $expiresIn),
            ':uid' => $userId,
        ]);
    } catch (Throwable $e) {
        log_to_file('wahoo.log', 'Token save failed for user #' . $userId . ': ' . $e->getMessage());
        return null;
    }

    return (string)$tokens['access_token'];
}

/**
 * Универсальный вызов Wahoo API.
 *
 * ВАЖНО: принимает user_id, а НЕ массив пользователя — потому что
 * перед каждым запросом нужно тянуть свежие токены из БД. Иначе
 * последовательные запросы ловят invalid_grant: Wahoo отзывает
 * старый refresh_token после первого успешного вызова с новым.
 */
function wahoo_api_call(int $userId, string $method, string $path, array $options = []): array
{
    $config = config('wahoo');
    $token  = wahoo_refresh_token_for($userId);

    if ($token === null) {
        return ['status' => 401, 'body' => '', 'json' => null, 'error' => 'no_token'];
    }

    $headers = array_merge([
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ], $options['headers'] ?? []);

    $body = $options['body'] ?? null;
    if (is_array($body)) {
        $body = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
    }

    return http_request($method, $config['api_base'] . $path, [
        'headers' => $headers,
        'body'    => $body,
    ]);
}

/**
 * Проверяет, подключён ли пользователь к Wahoo.
 */
function wahoo_is_connected(array $user): bool
{
    return !empty($user['wahoo_access_token']) && !empty($user['wahoo_refresh_token']);
}

/**
 * Отключает Wahoo: чистит токены локально и отзывает доступ на стороне Wahoo.
 */
function wahoo_disconnect(array $user): void
{
    if (!empty($user['wahoo_access_token'])) {
        try {
            $config = config('wahoo');
            http_request('DELETE', $config['api_base'] . '/v1/permissions', [
                'headers' => [
                    'Authorization: Bearer ' . $user['wahoo_access_token'],
                    'Accept: application/json',
                ],
            ]);
        } catch (Throwable $e) {
            log_to_file('wahoo.log', 'Revoke failed for user #' . (int)$user['id'] . ': ' . $e->getMessage());
        }
    }

    db()->prepare(
        'UPDATE users
         SET wahoo_user_id       = NULL,
             wahoo_access_token  = NULL,
             wahoo_refresh_token = NULL,
             wahoo_code_verifier = NULL,
             wahoo_expires_at    = NULL,
             wahoo_connected_at  = NULL
         WHERE id = ?'
    )->execute([(int)$user['id']]);
}
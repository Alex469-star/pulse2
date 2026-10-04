<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

auth_start();
$me = require_login();

$config = config('wahoo');

// Генерируем PKCE-пару и state
$verifier  = pkce_generate_verifier();
$challenge = pkce_generate_challenge($verifier);
$state     = bin2hex(random_bytes(24));

// Сохраняем в сессии — нужны будут в callback
$_SESSION['wahoo_oauth'] = [
    'verifier'   => $verifier,
    'state'      => $state,
    'user_id'    => (int)$me['id'],
    'created_at' => time(),
];

// Формируем URL авторизации вручную — http_build_query закодирует пробелы как '+' или '%20',
// но порядок scope у Wahoo требует строго пробел-разделённый список.
$scopes = implode(' ', $config['scopes']);

$params = [
    'client_id'             => $config['client_id'],
    'redirect_uri'          => $config['redirect_uri'],
    'scope'                 => $scopes,
    'response_type'         => 'code',
    'state'                 => $state,
    'code_challenge'        => $challenge,
    'code_challenge_method' => 'S256',
];

// RFC 3986: пробел = %20, а не '+'
$query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

$url = $config['authorize_url'] . '?' . $query;

header('Location: ' . $url);
exit;
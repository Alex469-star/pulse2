<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/User.php';

$me = api_require_user();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stats = User::stats((int)$me['id']);
    json_ok([
        'id' => (int)$me['id'],
        'username' => $me['username'],
        'display_name' => $me['display_name'],
        'bio' => $me['bio'],
        'city' => $me['city'],
        'country' => $me['country'],
        'units' => $me['units'],
        'stats' => $stats,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_input();
    $allowed = ['display_name','bio','city','country','gender','birth_date','weight_kg','height_cm','units','is_public'];
    $fields = array_intersect_key($input, array_flip($allowed));
    if ($fields) User::update((int)$me['id'], $fields);
    json_ok(['updated' => array_keys($fields)]);
}

json_err('Method not allowed', 405);
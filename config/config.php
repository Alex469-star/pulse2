<?php
declare(strict_types=1);

return [
    'app_name'    => 'Pulse',
    'app_url'     => 'https://roadrunnersteam.ru/pulse',
    'base_url'    => 'https://roadrunnersteam.ru/pulse',

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'yat-sport',
        'username' => 'yat-sport',
        'password' => 'gopaSlona_7',
        'charset'  => 'utf8mb4',
    ],

    'mail' => [
    'enabled'     => true,
    'host'        => 'localhost',   // чаще всего так
    'port'        => 465,
    'username'    => 'noreply@roadrunnersteam.ru',
    'password'    => 'syncMaster',
    'encryption'  => 'ssl',
    'from_email'  => 'noreply@roadrunnersteam.ru',
    'from_name'   => 'Pulse',
],

'wahoo' => [
    'client_id'     => 'tiAa2zwHAue82lpPyu_qJBuB3QMOJusZ5IXrlF_dCBs',
    'client_secret' => '',
    'redirect_uri'  => 'https://roadrunnersteam.ru/pulse/wahoo-callback.php',
    'authorize_url' => 'https://api.wahooligan.com/oauth/authorize',
    'token_url'     => 'https://api.wahooligan.com/oauth/token',
    'api_base'      => 'https://api.wahooligan.com',
    // scope: user_read автоматически добавляется Wahoo как default, но включим явно —
    // API вернёт 403 без него. offline_data нужен для webhook'ов.
    'scopes'        => [
        'user_read',
        'workouts_read',
        'power_zones_read',
        'offline_data',
    ],

    'webhook_token' => 'a38cebc4-d26e-4513-8370-db7d244412f1',
],

    'session_name' => 'pulse_sid',
];
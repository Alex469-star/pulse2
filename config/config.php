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
    'host'        => 'smtp.mail.ru',   // чаще всего так
    'port'        => 465,
    'username'    => 'noreply@roadrunnersteam.ru',
    'password'    => 'syncMaster',
    'encryption'  => 'ssl',
    'from_email'  => 'noreply@roadrunnersteam.ru',
    'from_name'   => 'Pulse',
],

    'session_name' => 'pulse_sid',
];
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wahoo-client.php';

auth_start();
$me = require_login();

$stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([(int)$me['id']]);
$user = $stmt->fetch();

if ($user) {
    try {
        wahoo_disconnect($user);
        flash('Wahoo отключён.', 'success');
    } catch (Throwable $e) {
        flash('Ошибка отключения: ' . $e->getMessage(), 'error');
    }
}

redirect(url('wahoo-sync.php'));
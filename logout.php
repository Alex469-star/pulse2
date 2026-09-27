<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
auth_start();
logout_user();
redirect(url('index.php'));
<?php
declare(strict_types=1);
/** @var string $pageTitle */
$admin = admin_current();
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle ?? 'Admin') ?> — Pulse Admin</title>
<link rel="stylesheet" href="<?= e(admin_url('assets/css/admin.css')) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
</head>
<body class="admin-body">
<?php if ($admin): ?>
<aside class="admin-sidebar">
    <div class="admin-sidebar__brand">
        <span class="admin-sidebar__logo">⚡</span>
        <span>Pulse Admin</span>
    </div>

    <nav class="admin-nav">
        <a class="admin-nav__link <?= $current === 'dashboard.php' ? 'is-active' : '' ?>"
           href="<?= e(admin_url('dashboard.php')) ?>"><span>📊</span> Дашборд</a>
        <a class="admin-nav__link <?= $current === 'stats.php' ? 'is-active' : '' ?>"
           href="<?= e(admin_url('stats.php')) ?>"><span>📈</span> Статистика</a>
        <a class="admin-nav__link <?= $current === 'users.php' ? 'is-active' : '' ?>"
           href="<?= e(admin_url('users.php')) ?>"><span>👥</span> Пользователи</a>
        <a class="admin-nav__link <?= $current === 'activities.php' ? 'is-active' : '' ?>"
           href="<?= e(admin_url('activities.php')) ?>"><span>🏃</span> Активности</a>
        <a class="admin-nav__link <?= $current === 'posts.php' ? 'is-active' : '' ?>"
           href="<?= e(admin_url('posts.php')) ?>"><span>📝</span> Посты</a>
        <a class="admin-nav__link <?= $current === 'segments.php' ? 'is-active' : '' ?>"
           href="<?= e(admin_url('segments.php')) ?>"><span>⚡</span> Сегменты</a>
        <a class="admin-nav__link <?= $current === 'clubs.php' ? 'is-active' : '' ?>"
           href="<?= e(admin_url('clubs.php')) ?>"><span>🏁</span> Клубы</a>
        <a class="admin-nav__link <?= $current === 'gear.php' ? 'is-active' : '' ?>"
           href="<?= e(admin_url('gear.php')) ?>"><span>🎒</span> Инвентарь</a>
           <a class="admin-nav__link <?= $current === 'broadcasts.php' ? 'is-active' : '' ?>"
   href="<?= e(admin_url('broadcasts.php')) ?>"><span>🔔</span> Рассылки</a>
           

        <?php if (($admin['role'] ?? '') === 'super'): ?>
            <div class="admin-nav__section">Данные</div>
            <a class="admin-nav__link <?= $current === 'database.php' ? 'is-active' : '' ?>"
               href="<?= e(admin_url('database.php')) ?>"><span>🗄</span> База данных</a>
            <a class="admin-nav__link <?= $current === 'sql.php' ? 'is-active' : '' ?>"
               href="<?= e(admin_url('sql.php')) ?>"><span>⌨</span> SQL-консоль</a>
            <a class="admin-nav__link <?= $current === 'admins.php' ? 'is-active' : '' ?>"
               href="<?= e(admin_url('admins.php')) ?>"><span>🔑</span> Администраторы</a>
        <?php endif; ?>

        <div class="admin-nav__section">Система</div>
        <a class="admin-nav__link <?= $current === 'audit.php' ? 'is-active' : '' ?>"
           href="<?= e(admin_url('audit.php')) ?>"><span>📜</span> Аудит</a>
    </nav>

    <div class="admin-sidebar__foot">
        <div class="admin-sidebar__user">
            <span class="admin-sidebar__user-name"><?= e($admin['display_name']) ?></span>
            <span class="admin-sidebar__user-role"><?= e($admin['role']) ?></span>
        </div>
        <a class="admin-sidebar__logout" href="<?= e(admin_url('logout.php')) ?>">Выйти</a>
    </div>
</aside>

<main class="admin-main">
    <header class="admin-topbar">
        <h1 class="admin-topbar__title"><?= e($pageTitle ?? '') ?></h1>
        <div class="admin-topbar__meta"><?= e(date('d.m.Y H:i')) ?></div>
    </header>

    <div class="admin-content">
<?php else: ?>
    <main class="admin-main admin-main--guest">
        <div class="admin-content">
<?php endif; ?>
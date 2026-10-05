<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

admin_auth_start();

if (admin_current()) {
    header('Location: ' . admin_url('dashboard.php'));
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $res = admin_login((string)$_POST['email'], (string)$_POST['password']);
    if ($res['ok']) {
        header('Location: ' . admin_url('dashboard.php'));
        exit;
    }
    $error = $res['error'];
}

$pageTitle = 'Вход в админку';
require __DIR__ . '/includes/header.php';
?>

<section class="admin-login">
    <div class="admin-login__card">
        <h1 class="admin-login__title">Pulse Admin</h1>
        <p class="admin-login__subtitle">Вход для администраторов</p>

        <?php if ($error): ?>
            <div class="alert alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <?= admin_csrf_field() ?>
            <div class="field">
                <label>Email</label>
                <input type="email" name="email" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label>Пароль</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <button class="btn btn--primary" style="width:100%">Войти</button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
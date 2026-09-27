<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

auth_start();
if (current_user()) redirect(url('feed.php'));

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $user = User::findByEmail($email);
    if ($user && password_verify($password, $user['password_hash'])) {
        login_user((int)$user['id']);

        // Если email не подтверждён — отправляем на страницу подтверждения
        if ((int)($user['email_verified'] ?? 0) === 0) {
            flash('Подтвердите email, чтобы активировать аккаунт.', 'info');
            redirect(url('verify-email.php'));
        }

        flash('С возвращением!', 'success');
        redirect(url('feed.php'));
    }
    $error = 'Неверный email или пароль';
}

$pageTitle = 'Вход';
require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Вход</h1>
        <p class="form-card__subtitle">Нет аккаунта? <a href="<?= e(url('register.php')) ?>">Зарегистрироваться</a></p>

        <?php if ($error): ?>
            <div class="alert alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label>Email</label>
                <input type="email" name="email" value="<?= e($email) ?>" required>
            </div>
            <div class="field">
                <label>Пароль</label>
                <input type="password" name="password" required>
            </div>
            <p style="text-align:right;font-size:13px;margin-bottom:16px">
                <a href="<?= e(url('forgot-password.php')) ?>">Забыли пароль?</a>
            </p>
            <button class="btn btn--primary btn--large" style="width:100%">Войти</button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
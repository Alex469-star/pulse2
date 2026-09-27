<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/models/Token.php';

auth_start();
if (current_user()) redirect(url('feed.php'));

$done = false;
$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $email = trim((string)($_POST['email'] ?? ''));
    $user = User::findByEmail($email);
    if ($user) {
        $token = Token::create((int)$user['id'], 'password_reset', 2);
        $link  = app_url('reset-password.php?token=' . urlencode($token));
        $body  = '<p>Вы запросили сброс пароля. Ссылка действует 2 часа.</p>';
        send_mail($email, 'Сброс пароля Pulse', mail_template('Сброс пароля', $body, 'Сбросить пароль', $link));
    }
    // Всегда показываем успех, чтобы не раскрывать наличие email
    $done = true;
}

$pageTitle = 'Забыли пароль';
require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Забыли пароль?</h1>
        <?php if ($done): ?>
            <div class="alert alert--success">
                Если такой email зарегистрирован — мы отправили ссылку для сброса.
            </div>
        <?php else: ?>
            <p class="form-card__subtitle">Введите email — пришлём ссылку для сброса.</p>
            <form method="post">
                <?= csrf_field() ?>
                <div class="field"><label>Email</label><input type="email" name="email" required></div>
                <button class="btn btn--primary btn--large" style="width:100%">Отправить ссылку</button>
            </form>
        <?php endif; ?>
        <p style="margin-top:16px;text-align:center;font-size:14px"><a href="<?= e(url('login.php')) ?>">Назад ко входу</a></p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
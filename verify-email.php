<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/models/Token.php';

auth_start();
$me = current_user();

$token = (string)($_GET['token'] ?? '');
$ok = false;
$message = '';

// --- Подтверждение по токену ---
if ($token !== '') {
    $userId = Token::consume($token, 'email_verify');
    if ($userId) {
        User::update($userId, ['email_verified' => 1]);
        $ok = true;
        $message = 'Email подтверждён. Спасибо!';
    } else {
        $message = 'Ссылка недействительна или уже использована.';
    }
}

// --- Отправка письма заново ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    csrf_check($_POST['csrf'] ?? null);

    if (!$me) {
        flash('Сначала войдите в систему', 'info');
        redirect(url('login.php'));
    }

    if ((int)($me['email_verified'] ?? 0) === 1) {
        flash('Ваш email уже подтверждён', 'success');
        redirect(url('feed.php'));
    }

    try {
        // Сбрасываем старые токены и создаём новый
        $newToken = Token::create((int)$me['id'], 'email_verify', 48);
        $link = app_url('verify-email.php?token=' . urlencode($newToken));
        $body = '<p>Подтвердите email, чтобы активировать аккаунт. Ссылка действует 48 часов.</p>';
        send_mail(
            (string)$me['email'],
            'Подтвердите email в ' . config('app_name'),
            mail_template('Подтверждение email', $body, 'Подтвердить email', $link)
        );
        $message = 'Письмо отправлено повторно. Проверьте почту.';
        $ok = true;
    } catch (Throwable $e) {
        $message = 'Не удалось отправить письмо: ' . $e->getMessage();
    }
}

$pageTitle = 'Подтверждение email';
require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Подтверждение email</h1>

        <?php if ($me && (int)($me['email_verified'] ?? 0) === 1): ?>
            <div class="alert alert--success">Email подтверждён. Спасибо!</div>
            <a href="<?= e(url('feed.php')) ?>" class="btn btn--primary btn--large" style="width:100%">
                Перейти в ленту
            </a>

        <?php elseif ($token !== '' && $ok): ?>
            <div class="alert alert--success"><?= e($message) ?></div>
            <a href="<?= e(url('feed.php')) ?>" class="btn btn--primary btn--large" style="width:100%">
                Перейти в ленту
            </a>

        <?php elseif ($token !== '' && !$ok): ?>
            <div class="alert alert--error"><?= e($message) ?></div>
            <p class="muted" style="margin:16px 0">
                Возможно, ссылка истекла или уже была использована. Попробуйте запросить письмо заново.
            </p>
            <?php if ($me): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend">
                    <button class="btn btn--primary btn--large" style="width:100%">
                        Отправить письмо повторно
                    </button>
                </form>
            <?php else: ?>
                <a href="<?= e(url('login.php')) ?>" class="btn btn--primary btn--large" style="width:100%">
                    Войти
                </a>
            <?php endif; ?>

        <?php else: ?>
            <?php if ($me): ?>
                <p class="form-card__subtitle">
                    Мы отправили письмо на <strong><?= e((string)$me['email']) ?></strong>.
                    Перейдите по ссылке в письме, чтобы подтвердить email.
                </p>

                <?php if ($message): ?>
                    <div class="alert alert--info"><?= e($message) ?></div>
                <?php endif; ?>

                <form method="post" style="margin-top:8px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend">
                    <button class="btn btn--ghost btn--large" style="width:100%">
                        Отправить письмо повторно
                    </button>
                </form>

                <p class="muted" style="margin-top:16px;font-size:13px;text-align:center">
                    Проверьте папку «Спам», если письмо не пришло.
                </p>
            <?php else: ?>
                <div class="alert alert--info">
                    Чтобы подтвердить email, войдите в аккаунт.
                </div>
                <a href="<?= e(url('login.php')) ?>" class="btn btn--primary btn--large" style="width:100%">
                    Войти
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Token.php';
require_once __DIR__ . '/models/Notification.php';

auth_start();

if (current_user()) {
    redirect(url('feed.php'));
}

$errors = [];
$success = false;
$old = ['email' => '', 'username' => '', 'display_name' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['email']        = trim((string)($_POST['email'] ?? ''));
    $old['username']     = trim((string)($_POST['username'] ?? ''));
    $old['display_name'] = trim((string)($_POST['display_name'] ?? ''));
    $password            = (string)($_POST['password'] ?? '');
    $password2           = (string)($_POST['password2'] ?? '');

    if ($old['email'] === '') {
        $errors['email'] = 'Введите email';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Некорректный email';
    } elseif (User::findByEmail($old['email'])) {
        $errors['email'] = 'Этот email уже зарегистрирован';
    }

    if ($old['username'] === '') {
        $errors['username'] = 'Введите имя пользователя';
    } elseif (!preg_match('/^[a-z0-9_]{3,30}$/i', $old['username'])) {
        $errors['username'] = 'Только латиница, цифры и _ (3–30 символов)';
    } elseif (User::findByUsername($old['username'])) {
        $errors['username'] = 'Это имя пользователя уже занято';
    }

    if ($old['display_name'] === '') {
        $errors['display_name'] = 'Введите отображаемое имя';
    }

    if (strlen($password) < 6) {
        $errors['password'] = 'Минимум 6 символов';
    } elseif ($password !== $password2) {
        $errors['password2'] = 'Пароли не совпадают';
    }

    if (!$errors) {
        try {
            $userId = User::create([
                'email'         => $old['email'],
                'username'      => $old['username'],
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name'  => $old['display_name'],
            ]);

            // Отправляем письмо с токеном
            $token = Token::create($userId, 'email_verify', 48);
            $link  = app_url('verify-email.php?token=' . urlencode($token));
            $body  = '<p>Спасибо за регистрацию в ' . e(config('app_name')) . '!</p>'
                   . '<p>Подтвердите email, чтобы активировать аккаунт. Ссылка действует 48 часов.</p>';

            send_mail(
                $old['email'],
                'Подтвердите email в ' . config('app_name'),
                mail_template('Подтверждение email', $body, 'Подтвердить email', $link)
            );

            // НЕ логиним пользователя
            // login_user($userId);

            $success = true;
        } catch (Throwable $e) {
            $errors['_general'] = 'Не удалось создать аккаунт: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Регистрация';
require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <?php if ($success): ?>
            <h1 class="form-card__title">Проверьте почту</h1>
            <p class="form-card__subtitle">
                Мы отправили письмо с подтверждением на <strong><?= e($old['email']) ?></strong>.
            </p>
            <div class="alert alert--info">
                Перейдите по ссылке в письме, чтобы активировать аккаунт.
                Если письмо не пришло — проверьте папку «Спам».
            </div>
            <a href="<?= e(url('login.php')) ?>" class="btn btn--primary btn--large" style="width:100%">
                Войти
            </a>

        <?php else: ?>
            <h1 class="form-card__title">Создать аккаунт</h1>
            <p class="form-card__subtitle">
                Уже есть аккаунт? <a href="<?= e(url('login.php')) ?>">Войти</a>
            </p>

            <?php if (!empty($errors['_general'])): ?>
                <div class="alert alert--error"><?= e($errors['_general']) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>

                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required autofocus>
                    <?php if (!empty($errors['email'])): ?>
                        <span class="field__error"><?= e($errors['email']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="username">Имя пользователя</label>
                    <input type="text" id="username" name="username" value="<?= e($old['username']) ?>" required>
                    <?php if (!empty($errors['username'])): ?>
                        <span class="field__error"><?= e($errors['username']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="display_name">Отображаемое имя</label>
                    <input type="text" id="display_name" name="display_name" value="<?= e($old['display_name']) ?>" required>
                    <?php if (!empty($errors['display_name'])): ?>
                        <span class="field__error"><?= e($errors['display_name']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="password">Пароль</label>
                    <input type="password" id="password" name="password" required>
                    <?php if (!empty($errors['password'])): ?>
                        <span class="field__error"><?= e($errors['password']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="password2">Повторите пароль</label>
                    <input type="password" id="password2" name="password2" required>
                    <?php if (!empty($errors['password2'])): ?>
                        <span class="field__error"><?= e($errors['password2']) ?></span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn--primary btn--large" style="width:100%">
                    Зарегистрироваться
                </button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
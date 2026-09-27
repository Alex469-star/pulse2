<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Token.php';

auth_start();

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if (strlen($p1) < 6) $errors[] = 'Минимум 6 символов';
    elseif ($p1 !== $p2) $errors[] = 'Пароли не совпадают';

    if (!$errors) {
        $userId = Token::consume($token, 'password_reset');
        if (!$userId) {
            $errors[] = 'Ссылка недействительна или истекла';
        } else {
            User::update($userId, ['password_hash' => password_hash($p1, PASSWORD_DEFAULT)]);
            $done = true;
        }
    }
}

$pageTitle = 'Сброс пароля';
require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Новый пароль</h1>
        <?php foreach ($errors as $err): ?><div class="alert alert--error"><?= e($err) ?></div><?php endforeach; ?>

        <?php if ($done): ?>
            <div class="alert alert--success">Пароль обновлён. <a href="<?= e(url('login.php')) ?>">Войти</a></div>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="field"><label>Новый пароль</label><input type="password" name="password" required></div>
                <div class="field"><label>Повторите</label><input type="password" name="password2" required></div>
                <button class="btn btn--primary btn--large" style="width:100%">Сохранить</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
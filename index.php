<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
auth_start();

if (current_user()) redirect(url('feed.php'));

$loginError = null;
$loginEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_login') {
    csrf_check($_POST['csrf'] ?? null);

    $loginEmail = trim((string)($_POST['email'] ?? ''));
    $password   = (string)($_POST['password'] ?? '');

    if ($loginEmail === '' || $password === '') {
        $loginError = 'Заполните email и пароль';
    } else {
        require_once __DIR__ . '/models/User.php';
        $user = User::findByEmail($loginEmail);

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            $loginError = 'Неверный email или пароль';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            redirect(url('feed.php'));
        }
    }
}

$pageTitle = 'Pulse — социальная платформа для спортсменов';
require __DIR__ . '/includes/header.php';
?>

<!-- ============ HERO НА ВСЮ ШИРИНУ ЭКРАНА ============ -->
<section class="hero-full">
    <div class="hero-full__overlay"></div>

    <div class="hero-full__inner">
        <!-- ЛЕВАЯ КОЛОНКА: текст -->
        <div class="hero-full__text">
            <span class="hero__badge">Новое поколение спортивных сообществ</span>
            <h1 class="hero-full__title">
                Твои тренировки.<br>Твои маршруты.<br>
                <span class="accent">Твоё сообщество.</span>
            </h1>
            <p class="hero-full__subtitle">
                Загружай GPX, TCX и FIT, рисуй маршруты, соревнуйся на сегментах
                и делись результатами с теми, кто тебя понимает.
            </p>
            <ul class="hero-full__list">
                <li>📂 Импорт активностей из любых часов и приложений</li>
                <li>🗺️ Карта, профиль высот, пульс, каденс и мощность</li>
                <li>⚡ Сегменты и честные лидерборды</li>
                <li>👥 Подписки, лайки, комментарии</li>
            </ul>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: форма входа -->
        <div class="hero-full__form">
            <div class="login-card">
                <h2 class="login-card__title">Войти в Pulse</h2>
                <p class="login-card__subtitle">
                    Ещё нет аккаунта?
                    <a href="<?= e(url('register.php')) ?>">Зарегистрироваться</a>
                </p>

                <?php if ($loginError !== null): ?>
                    <div class="alert alert--error"><?= e($loginError) ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="quick_login">

                    <div class="field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email"
                               value="<?= e($loginEmail) ?>"
                               autocomplete="email" required>
                    </div>

                    <div class="field">
                        <label for="password">Пароль</label>
                        <input type="password" id="password" name="password"
                               autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn btn--primary btn--large" style="width:100%">
                        Войти
                    </button>
                </form>

                <div class="login-card__footer">
                    <a href="<?= e(url('forgot-password.php')) ?>">Забыли пароль?</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
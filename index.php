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

// ============================================================
// СТАТИСТИКА ДЛЯ HERO (с кэшем)
// ============================================================
$stats = [
    'users'       => 0,
    'activities'  => 0,
    'distance_km' => 0,
    'clubs'       => 0,
];

try {
    $stats = Cache::remember('landing.stats', function () {
        return [
            'users'       => (int)db()->query('SELECT COUNT(*) FROM users WHERE is_banned = 0')->fetchColumn(),
            'activities'  => (int)db()->query('SELECT COUNT(*) FROM activities')->fetchColumn(),
            'distance_km' => (int)round((float)db()->query('SELECT COALESCE(SUM(distance_m),0) FROM activities')->fetchColumn() / 1000),
            'clubs'       => (int)db()->query('SELECT COUNT(*) FROM clubs WHERE is_banned = 0')->fetchColumn(),
        ];
    }, 600); // 10 минут
} catch (Throwable $e) {
    // Игнорируем — покажем блок без цифр
}

$hasStats = $stats['users'] >= 10;

$pageTitle = 'Pulse — социальная платформа для спортсменов';
require __DIR__ . '/includes/header.php';
?>

<!-- ============ HERO НА ВСЮ ШИРИНУ ЭКРАНА ============ -->
<section class="hero-full">
    <div class="hero-full__bg"
         style="background-image: url('<?= e(url('assets/img/hero-bg.jpg')) ?>');"></div>
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
                Pulse — платформа для бегунов, велосипедистов, лыжников и всех,
                кто любит движение. Загружай тренировки, соревнуйся на сегментах,
                вступай в клубы и находи своих.
            </p>

            <ul class="hero-full__list">
                <li>
                    <span class="hero-full__list-icon">📂</span>
                    <span><strong>Импорт активностей</strong> — GPX, TCX, FIT или вручную</span>
                </li>
                <li>
                    <span class="hero-full__list-icon">🗺️</span>
                    <span><strong>Карта и аналитика</strong> — профиль высот, пульс, каденс, мощность</span>
                </li>
                <li>
                    <span class="hero-full__list-icon">⚡</span>
                    <span><strong>Сегменты и лидерборды</strong> — соревнуйся с другими на любимых участках</span>
                </li>
                <li>
                    <span class="hero-full__list-icon">🏁</span>
                    <span><strong>Клубы и события</strong> — находи единомышленников по городу и виду спорта</span>
                </li>
                <li>
                    <span class="hero-full__list-icon">👟</span>
                    <span><strong>Инвентарь</strong> — учёт пробега кроссовок, велосипеда и лыж</span>
                </li>
            </ul>

            <?php if ($hasStats): ?>
                <div class="hero-full__stats">
                    <div class="hero-stat">
                        <span class="hero-stat__value"><?= number_format($stats['users'], 0, '.', ' ') ?></span>
                        <span class="hero-stat__label">атлетов</span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat__value"><?= number_format($stats['activities'], 0, '.', ' ') ?></span>
                        <span class="hero-stat__label">тренировок</span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat__value"><?= number_format($stats['distance_km'], 0, '.', ' ') ?></span>
                        <span class="hero-stat__label">км пройдено</span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat__value"><?= number_format($stats['clubs'], 0, '.', ' ') ?></span>
                        <span class="hero-stat__label">клубов</span>
                    </div>
                </div>
            <?php endif; ?>
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
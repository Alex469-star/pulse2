<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
auth_start();
if (current_user()) redirect(url('feed.php'));

$pageTitle = 'Социальная платформа для спортсменов';
require __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="hero__content">
        <span class="hero__badge">Новое поколение спортивных сообществ</span>
        <h1 class="hero__title">
            Твои тренировки.<br>Твои маршруты.<br>
            <span class="accent">Твоё сообщество.</span>
        </h1>
        <p class="hero__subtitle">
            Загружай активности, рисуй маршруты, соревнуйся на сегментах
            и делись результатами с теми, кто тебя понимает.
        </p>
        <div class="hero__actions">
            <a href="<?= e(url('register.php')) ?>" class="btn btn--primary">Начать бесплатно</a>
            <a href="#features" class="btn btn--ghost">Узнать больше</a>
        </div>
        <div class="hero__stats">
            <div class="stat"><span class="stat__value">GPX</span><span class="stat__label">TCX · FIT · и другие</span></div>
            <div class="stat"><span class="stat__value">∞</span><span class="stat__label">видов активности</span></div>
            <div class="stat"><span class="stat__value">1</span><span class="stat__label">сообщество</span></div>
        </div>
    </div>
    <div class="hero__visual">
        <div class="mockup">
            <div class="mockup__header">
                <span class="dot dot--red"></span><span class="dot dot--yellow"></span><span class="dot dot--green"></span>
            </div>
            <div class="mockup__body">
                <div class="mockup__map"></div>
                <div class="mockup__card">
                    <div class="mockup__card-title">Утренний забег</div>
                    <div class="mockup__card-meta">10.4 км · 52:18 · 5:01 /км</div>
                    <div class="mockup__card-actions"><span>♥ 128</span><span>💬 14</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="features" class="features">
    <h2 class="section-title">Всё, что нужно спортсмену</h2>
    <p class="section-subtitle">От загрузки файлов до сегментов и лидербордов — в одном месте.</p>
    <div class="features__grid">
        <article class="feature"><div class="feature__icon">📂</div><h3 class="feature__title">Импорт активностей</h3><p class="feature__text">Загружай GPX, TCX, FIT и другие форматы. Мы распарсим, построим карту и посчитаем статистику.</p></article>
        <article class="feature"><div class="feature__icon">🗺️</div><h3 class="feature__title">Свои маршруты</h3><p class="feature__text">Рисуй маршруты прямо на карте, сохраняй, делись с друзьями и экспортируй в GPX.</p></article>
        <article class="feature"><div class="feature__icon">⚡</div><h3 class="feature__title">Сегменты</h3><p class="feature__text">Создавай участки и сравнивай свои результаты с другими в честных лидербордах.</p></article>
        <article class="feature"><div class="feature__icon">👥</div><h3 class="feature__title">Сообщество</h3><p class="feature__text">Подписки, лайки, комментарии. Находи единомышленников по виду спорта и городу.</p></article>
        <article class="feature"><div class="feature__icon">🎒</div><h3 class="feature__title">Инвентарь</h3><p class="feature__text">Веди учёт велосипедов, кроссовок и лыж. Следи за износом и пробегом каждой вещи.</p></article>
        <article class="feature"><div class="feature__icon">🔒</div><h3 class="feature__title">Приватность</h3><p class="feature__text">Скрывай зоны вокруг дома и работы, управляй видимостью активностей и профиля.</p></article>
    </div>
</section>

<section id="activities" class="activities">
    <h2 class="section-title">Для любой активности</h2>
    <div class="activities__list">
        <span class="chip">🏃 Бег</span><span class="chip">🚴 Велосипед</span><span class="chip">🏊 Плавание</span>
        <span class="chip">⛷️ Лыжи</span><span class="chip">🥾 Хайкинг</span><span class="chip">🚶 Ходьба</span>
        <span class="chip">🏋️ Тренажёрный зал</span><span class="chip">🧘 Йога</span><span class="chip">🛶 Гребля</span>
        <span class="chip">⛸️ Коньки</span>
    </div>
</section>

<section class="cta">
    <h2 class="cta__title">Готов начать?</h2>
    <p class="cta__text">Создай аккаунт за минуту и загрузи первую тренировку.</p>
    <a href="<?= e(url('register.php')) ?>" class="btn btn--primary btn--large">Создать аккаунт</a>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
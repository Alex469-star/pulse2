<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
auth_start();

$me = current_user();
$pageTitle = 'О проекте';

require __DIR__ . '/includes/header.php';
?>

<section class="legal-page">
    <header class="legal-page__head">
        <h1 class="legal-page__title">О проекте Pulse</h1>
        <p class="legal-page__lead">
            Pulse — социальная платформа для спортсменов. Мы делаем место, где
            можно вести тренировки, строить маршруты, соревноваться на сегментах
            и делиться результатами с теми, кто тебя понимает.
        </p>
    </header>

    <div class="legal-card">
        <h2>Что такое Pulse</h2>
        <p>
            Pulse — это MVP, написанный на чистом PHP без фреймворков. Он
            позволяет загружать активности в форматах GPX, TCX и FIT,
            автоматически строить карту и графики, вести учёт инвентаря и
            соревноваться с другими спортсменами на общих участках — сегментах.
        </p>
        <p>
            Проект развивается как открытый эксперимент: мы хотим сделать
            спортивную соцсеть, которая не превращается в рекламную ленту,
            не продаёт данные и остаётся удобной для тех, кто тренируется
            каждый день.
        </p>
    </div>

    <div class="legal-card">
        <h2>Что внутри</h2>
        <div class="legal-features">
            <div class="legal-feature">
                <div class="legal-feature__icon">📂</div>
                <div>
                    <div class="legal-feature__title">Импорт активностей</div>
                    <div class="legal-feature__text">
                        Загружайте файлы с часов и приложений — GPX, TCX, FIT.
                        Мы распарсим трек, метрики датчиков (пульс, каденс,
                        мощность, температуру) и построим карту.
                    </div>
                </div>
            </div>
            <div class="legal-feature">
                <div class="legal-feature__icon">🗺️</div>
                <div>
                    <div class="legal-feature__title">Маршруты</div>
                    <div class="legal-feature__text">
                        Рисуйте маршруты прямо на карте, добавляйте путевые
                        точки, делитесь с друзьями и экспортируйте в GPX.
                    </div>
                </div>
            </div>
            <div class="legal-feature">
                <div class="legal-feature__icon">⚡</div>
                <div>
                    <div class="legal-feature__title">Сегменты и лидерборды</div>
                    <div class="legal-feature__text">
                        Создавайте участки и соревнуйтесь на время. Автоматический
                        матчер сам находит прохождения сегментов в загруженных
                        активностях.
                    </div>
                </div>
            </div>
            <div class="legal-feature">
                <div class="legal-feature__icon">👥</div>
                <div>
                    <div class="legal-feature__title">Сообщество</div>
                    <div class="legal-feature__text">
                        Подписки, лайки, комментарии, уведомления. Находите
                        единомышленников по виду спорта и городу.
                    </div>
                </div>
            </div>
            <div class="legal-feature">
                <div class="legal-feature__icon">🎒</div>
                <div>
                    <div class="legal-feature__title">Инвентарь</div>
                    <div class="legal-feature__text">
                        Ведите учёт велосипедов, кроссовок и лыж. Следите
                        за пробегом и износом каждой вещи.
                    </div>
                </div>
            </div>
            <div class="legal-feature">
                <div class="legal-feature__icon">🔒</div>
                <div>
                    <div class="legal-feature__title">Приватность</div>
                    <div class="legal-feature__text">
                        Три уровня видимости активностей и профиля. Скрывайте
                        зоны вокруг дома и работы, управляйте подписчиками.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="legal-card">
        <h2>Как это работает</h2>
        <ol class="legal-steps">
            <li>
                <strong>Зарегистрируйтесь</strong> — на это уйдёт меньше минуты.
                Подтвердите email — и аккаунт готов.
            </li>
            <li>
                <strong>Загрузите тренировку</strong> — GPX/TCX/FIT с часов или
                приложения. Можно сразу выбрать инвентарь и добавить фото.
            </li>
            <li>
                <strong>Смотрите статистику</strong> — карта, дистанция, время,
                темп, набор высоты, пульс, каденс, мощность, графики по
                дистанции.
            </li>
            <li>
                <strong>Соревнуйтесь</strong> — создавайте сегменты, попадайте
                в лидерборды, подписывайтесь на других атлетов.
            </li>
            <li>
                <strong>Общайтесь</strong> — лайкайте, комментируйте, следите
                за новыми тренировками в ленте.
            </li>
        </ol>
    </div>

    <div class="legal-card">
        <h2>Технологии</h2>
        <p>
            Pulse — это чистый PHP 8.1+ и MySQL 8.0+ без фреймворков. Карты —
            OpenStreetMap и Leaflet. Графики — Chart.js. Письма — PHPMailer.
            Проект открыт: код доступен на GitHub, там же можно задать вопросы
            и предложить идеи.
        </p>
    </div>

    <div class="legal-card legal-card--cta">
        <h2>Готовы начать?</h2>
        <p>Создайте аккаунт и загрузите первую тренировку.</p>
        <div class="legal-cta__actions">
            <?php if ($me): ?>
                <a href="<?= e(url('feed.php')) ?>" class="btn btn--primary">Перейти в ленту</a>
                <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--ghost">Загрузить активность</a>
            <?php else: ?>
                <a href="<?= e(url('register.php')) ?>" class="btn btn--primary">Создать аккаунт</a>
                <a href="<?= e(url('login.php')) ?>" class="btn btn--ghost">Войти</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
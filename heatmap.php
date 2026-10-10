<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/heatmap-cities.php';

auth_start();
$me = current_user();

$pageTitle = 'Тепловая карта';

// ---- Координаты города пользователя для автоцентрирования ----
$userCoords = null;
if ($me && !empty($me['city'])) {
    $userCoords = heatmap_city_coords((string)$me['city']);
}

// ---- Подключение ресурсов ----
$extraCss = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    url('assets/css/heatmap.css'),
];

$extraJs = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js',
    url('assets/js/heatmap.js'),
];

require __DIR__ . '/includes/header.php';
?>

<section class="heatmap-page">

    <header class="heatmap-page__head">
        <h1 class="heatmap-page__title">Тепловая карта активности</h1>
        <p class="heatmap-page__subtitle muted">
            Где тренируются атлеты Pulse
        </p>
    </header>

    <div class="heatmap-toolbar">

        <?php if ($me): ?>
            <div class="heatmap-toolbar__group">
                <span class="heatmap-toolbar__label">Показать:</span>
                <button type="button"
                        class="heatmap-tool js-heat-mode is-active"
                        data-mode="global">🌍 Глобальная</button>
                <button type="button"
                        class="heatmap-tool js-heat-mode"
                        data-mode="personal">👤 Личная</button>
            </div>
        <?php endif; ?>

        <div class="heatmap-toolbar__group">
            <span class="heatmap-toolbar__label">Тип:</span>
            <button type="button" class="heatmap-tool js-heat-type is-active" data-type="">Все</button>
            <button type="button" class="heatmap-tool js-heat-type" data-type="run">🏃 Бег</button>
            <button type="button" class="heatmap-tool js-heat-type" data-type="ride">🚴 Вело</button>
            <button type="button" class="heatmap-tool js-heat-type" data-type="walk">🚶 Ходьба</button>
            <button type="button" class="heatmap-tool js-heat-type" data-type="hike">🥾 Хайкинг</button>
            <button type="button" class="heatmap-tool js-heat-type" data-type="ski">⛷️ Лыжи</button>
        </div>

        <div class="heatmap-toolbar__group">
            <span class="heatmap-toolbar__label">Сегменты:</span>
            <button type="button" class="heatmap-tool js-segments-toggle is-active" data-on="1">
                Показать
            </button>
        </div>

        <div class="heatmap-toolbar__group">
            <button type="button" class="heatmap-tool js-heat-locate" title="Найти меня">
                📍 Моё местоположение
            </button>
        </div>

    </div>

    <div id="heatmap-map" class="heatmap-map"></div>

    <div class="heatmap-legend">
        <span class="heatmap-legend__label">Интенсивность:</span>
        <div class="heatmap-legend__bar">
            <span>Редко</span>
            <span>Часто</span>
        </div>
    </div>

    <div class="heatmap-legend heatmap-legend--segments">
        <span class="heatmap-legend__label">Сегменты:</span>
        <div class="heatmap-segments-legend">
            <span><i style="background:#e94f2e"></i> Бег</span>
            <span><i style="background:#2e7de9"></i> Вело</span>
            <span><i style="background:#68b96b"></i> Ходьба</span>
            <span><i style="background:#8a6a3a"></i> Хайкинг</span>
            <span><i style="background:#5e9ee9"></i> Лыжи</span>
            <span><i style="background:#2ec4e9"></i> Плавание</span>
        </div>
    </div>

</section>

<script>
window.__HEATMAP_API__       = <?= json_encode(url('api/heatmap.php')) ?>;
window.__HEATMAP_SEG_API__   = <?= json_encode(url('api/heatmap-segments.php')) ?>;
window.__HEATMAP_MODE__      = 'global';
window.__HEATMAP_ME__        = <?= (int)($me['id'] ?? 0) ?>;
window.__HEATMAP_TYPE__      = '';
window.__HEATMAP_USER_COORDS__ = <?= json_encode($userCoords, JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
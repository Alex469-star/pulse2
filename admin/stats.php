<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('readonly');

// Все запросы через API-эндпоинт для графиков
$pageTitle = 'Статистика';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-charts-grid admin-charts-grid--2">
    <div class="admin-card">
        <h3 class="admin-card__title">Регистрации (30 дней)</h3>
        <div class="admin-chart"><canvas id="stat-reg"></canvas></div>
    </div>
    <div class="admin-card">
        <h3 class="admin-card__title">Активности (30 дней)</h3>
        <div class="admin-chart"><canvas id="stat-act"></canvas></div>
    </div>
    <div class="admin-card">
        <h3 class="admin-card__title">Распределение по типам</h3>
        <div class="admin-chart"><canvas id="stat-types"></canvas></div>
    </div>
    <div class="admin-card">
        <h3 class="admin-card__title">Активности по дням недели</h3>
        <div class="admin-chart"><canvas id="stat-dow"></canvas></div>
    </div>
    <div class="admin-card">
        <h3 class="admin-card__title">Активности по часам</h3>
        <div class="admin-chart"><canvas id="stat-hour"></canvas></div>
    </div>
    <div class="admin-card">
        <h3 class="admin-card__title">Топ-10 пользователей по км</h3>
        <div class="admin-chart"><canvas id="stat-top"></canvas></div>
    </div>
    <div class="admin-card">
        <h3 class="admin-card__title">Дистанция по неделям</h3>
        <div class="admin-chart"><canvas id="stat-weekly"></canvas></div>
    </div>
    <div class="admin-card">
        <h3 class="admin-card__title">Объём по городам</h3>
        <div class="admin-chart"><canvas id="stat-cities"></canvas></div>
    </div>
</div>

<script>
window.__ADMIN_STATS__ = <?= json_encode(admin_url('api/stats.php'), JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
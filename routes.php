<?php
declare(strict_types=1);

// ВРЕМЕННО для отладки — уберите после того, как всё заработает
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Route.php';

auth_start();
$me = current_user();

// ---- Параметры ----
$tab  = (string)($_GET['tab'] ?? 'all');    // all | mine
$type = (string)($_GET['type'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$allowedTabs = ['all', 'mine'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'all';

$allowedTypes = ['', 'run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
if (!in_array($type, $allowedTypes, true)) $type = '';

// Если вкладка "mine" и не залогинен — редирект на "all"
if ($tab === 'mine' && !$me) {
    redirect(url('routes.php'));
}

// ---- Загрузка маршрутов ----
$routes = [];
$error  = null;

try {
    if ($tab === 'mine') {
        $routes = Route::byUserWithTrack((int)$me['id'], $limit, $offset);
    } else {
        $routes = Route::allPublicWithTrack($limit, $offset, $type);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $routes = [];
}

// ---- Упрощаем треки для миниатюр ----
$tracksById = [];
foreach ($routes as $r) {
    if (empty($r['track_json'])) {
        $tracksById[(int)$r['id']] = [];
        continue;
    }
    $raw = json_decode((string)$r['track_json'], true);
    if (!is_array($raw) || count($raw) < 2) {
        $tracksById[(int)$r['id']] = [];
        continue;
    }
    $points = [];
    foreach ($raw as $p) {
        if (isset($p['lat'], $p['lng'])) {
            $points[] = [
                'lat' => round((float)$p['lat'], 5),
                'lng' => round((float)$p['lng'], 5),
            ];
        }
    }
    // Мини-карта — не больше 80 точек
    if (count($points) > 80) {
        $step = (int)ceil(count($points) / 80);
        $simplified = [];
        for ($i = 0; $i < count($points); $i += $step) {
            $simplified[] = $points[$i];
        }
        if (end($simplified) !== end($points)) {
            $simplified[] = end($points);
        }
        $points = $simplified;
    }
    $tracksById[(int)$r['id']] = $points;
}

// ---- Сводная статистика по маршрутам ----
$summary = [
    'total'          => 0,
    'total_distance' => 0,
    'total_elev'     => 0,
];

try {
    if ($tab === 'mine' && $me) {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(distance_m),0) AS dist,
                    COALESCE(SUM(elevation_gain_m),0) AS elev
             FROM routes WHERE user_id = ?'
        );
        $stmt->execute([(int)$me['id']]);
    } else {
        $stmt = db()->query(
            'SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(distance_m),0) AS dist,
                    COALESCE(SUM(elevation_gain_m),0) AS elev
             FROM routes WHERE is_public = 1'
        );
    }
    $r = $stmt->fetch() ?: ['cnt' => 0, 'dist' => 0, 'elev' => 0];
    $summary['total']          = (int)$r['cnt'];
    $summary['total_distance'] = (float)$r['dist'];
    $summary['total_elev']     = (float)$r['elev'];
} catch (Throwable $e) {
    // оставим нули
}

$pageTitle = 'Маршруты';

// Leaflet для миниатюр
$extraCss = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'];
$extraJs  = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

$inlineJs = '
window.__ROUTES_TRACKS__ = ' . json_encode($tracksById, JSON_UNESCAPED_UNICODE) . ';
(function () {
    if (typeof L === "undefined") return;

    var tracks = window.__ROUTES_TRACKS__ || {};

    function initMap(el) {
        if (el.dataset.initialized === "1") return;
        var id = el.dataset.routeId;
        var pts = tracks[id] || [];
        if (!pts.length) return;

        el.dataset.initialized = "1";

        var map = L.map(el, {
            zoomControl: false,
            attributionControl: false,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            dragging: false,
            touchZoom: false,
            boxZoom: false,
            keyboard: false
        });

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19
        }).addTo(map);

        var latlngs = pts.map(function (p) { return [p.lat, p.lng]; });
        var line = L.polyline(latlngs, { color: "#ff5a1f", weight: 4, opacity: 0.95 }).addTo(map);

        L.circleMarker(latlngs[0], {
            radius: 4, color: "#0a7a3a", fillColor: "#0a7a3a", fillOpacity: 1
        }).addTo(map);
        L.circleMarker(latlngs[latlngs.length - 1], {
            radius: 4, color: "#b3261e", fillColor: "#b3261e", fillOpacity: 1
        }).addTo(map);

        map.fitBounds(line.getBounds(), { padding: [8, 8] });
    }

    var maps = document.querySelectorAll(".route-thumb");
    if ("IntersectionObserver" in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    initMap(entry.target);
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: "200px" });
        maps.forEach(function (el) { io.observe(el); });
    } else {
        maps.forEach(initMap);
    }

    // Клик по карте — переход на страницу маршрута
    document.querySelectorAll(".route-thumb").forEach(function (el) {
        el.addEventListener("click", function () {
            var link = el.closest(".route-card__link");
            if (link) window.location.href = link.href;
        });
    });
})();
';

require __DIR__ . '/includes/header.php';

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

function route_type_icon(string $type): string
{
    return match ($type) {
        'run'  => '🏃',
        'ride' => '🚴',
        'swim' => '🏊',
        'ski'  => '⛷️',
        'walk' => '🚶',
        'hike' => '🥾',
        default => '📦',
    };
}
function route_type_label(string $type): string
{
    return match ($type) {
        'run'  => 'Бег',
        'ride' => 'Велосипед',
        'swim' => 'Плавание',
        'ski'  => 'Лыжи',
        'walk' => 'Ходьба',
        'hike' => 'Хайкинг',
        default => 'Другое',
    };
}
?>

<section class="routes-page">
    <!-- Заголовок -->
    <header class="routes-page__head">
        <div>
            <h1 class="section-title" style="text-align:left;margin-bottom:4px">Маршруты</h1>
            <p class="muted">
                <?= $tab === 'mine' ? 'Ваши сохранённые маршруты' : 'Публичные маршруты сообщества' ?>
            </p>
        </div>
        <?php if ($me): ?>
            <a href="<?= e(url('route-create.php')) ?>" class="btn btn--primary">
                + Нарисовать маршрут
            </a>
        <?php endif; ?>
    </header>

    <!-- Вкладки -->
    <div class="routes-tabs">
        <a href="?<?= e(http_build_query(['tab' => 'all', 'type' => $type])) ?>"
           class="routes-tab <?= $tab === 'all' ? 'is-active' : '' ?>">
            🌐 Публичные
        </a>
        <?php if ($me): ?>
            <a href="?<?= e(http_build_query(['tab' => 'mine', 'type' => $type])) ?>"
               class="routes-tab <?= $tab === 'mine' ? 'is-active' : '' ?>">
                👤 Мои
            </a>
        <?php endif; ?>
    </div>

    <!-- Фильтры по типу -->
    <div class="routes-filters">
        <?php
            $typeFilters = [
                ''      => 'Все',
                'run'   => '🏃 Бег',
                'ride'  => '🚴 Вело',
                'swim'  => '🏊 Плавание',
                'ski'   => '⛷️ Лыжи',
                'walk'  => '🚶 Ходьба',
                'hike'  => '🥾 Хайкинг',
            ];
        ?>
        <?php foreach ($typeFilters as $key => $label): ?>
            <?php $qs = http_build_query(['tab' => $tab, 'type' => $key]); ?>
            <a href="?<?= e($qs) ?>" class="routes-filter <?= $type === $key ? 'is-active' : '' ?>">
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Сводка -->
    <?php if ($summary['total'] > 0): ?>
        <div class="routes-summary">
            <div class="routes-summary__cell">
                <span class="routes-summary__value"><?= (int)$summary['total'] ?></span>
                <span class="routes-summary__label">маршрутов</span>
            </div>
            <div class="routes-summary__cell">
                <span class="routes-summary__value"><?= e(format_distance($summary['total_distance'])) ?></span>
                <span class="routes-summary__label">общая длина</span>
            </div>
            <div class="routes-summary__cell">
                <span class="routes-summary__value">
                    <?= $summary['total_elev'] > 0
                        ? number_format($summary['total_elev'], 0, '.', ' ') . ' м'
                        : '—' ?>
                </span>
                <span class="routes-summary__label">набор высоты</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Ошибка -->
    <?php if ($error !== null): ?>
        <div class="alert alert--error">
            <strong>Ошибка загрузки:</strong> <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Список -->
    <?php if (!$routes): ?>
        <div class="empty">
            <?php if ($tab === 'mine'): ?>
                <p>У вас пока нет сохранённых маршрутов.</p>
                <a href="<?= e(url('route-create.php')) ?>" class="btn btn--primary">
                    Нарисовать первый
                </a>
            <?php else: ?>
                <p>Пока нет публичных маршрутов.</p>
                <?php if ($me): ?>
                    <a href="<?= e(url('route-create.php')) ?>" class="btn btn--primary">
                        Нарисовать первый
                    </a>
                <?php else: ?>
                    <a href="<?= e(url('register.php')) ?>" class="btn btn--primary">
                        Зарегистрироваться
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="routes-grid">
            <?php foreach ($routes as $r): ?>
                <?php
                    $hasTrack = !empty($tracksById[(int)$r['id']]);
                    $pointCount = $hasTrack ? count($tracksById[(int)$r['id']]) : 0;
                ?>
                <article class="route-card">
                    <a class="route-card__link" href="<?= e(url('route.php?id=' . (int)$r['id'])) ?>">
                        <!-- Миниатюра карты -->
                        <div class="route-card__thumb-wrap">
                            <?php if ($hasTrack): ?>
                                <div class="route-thumb"
                                     data-route-id="<?= (int)$r['id'] ?>"
                                     data-initialized="0"></div>
                            <?php else: ?>
                                <div class="route-thumb route-thumb--empty">
                                    <span class="route-thumb__empty-icon">🗺️</span>
                                    <span class="muted">Нет трека</span>
                                </div>
                            <?php endif; ?>

                            <span class="route-card__type">
                                <?= e(route_type_icon((string)$r['type'])) ?>
                                <?= e(route_type_label((string)$r['type'])) ?>
                            </span>

                            <?php if (!(int)$r['is_public']): ?>
                                <span class="route-card__visibility" title="Приватный">🔒</span>
                            <?php endif; ?>
                        </div>

                        <!-- Тело карточки -->
                        <div class="route-card__body">
                            <h3 class="route-card__name"><?= e($r['name']) ?></h3>

                            <?php if (!empty($r['description'])): ?>
                                <p class="route-card__desc"><?= e(mb_substr((string)$r['description'], 0, 100)) ?><?= mb_strlen((string)$r['description']) > 100 ? '…' : '' ?></p>
                            <?php endif; ?>

                            <div class="route-card__stats">
                                <div class="route-stat">
                                    <span class="route-stat__value"><?= e(format_distance((float)$r['distance_m'])) ?></span>
                                    <span class="route-stat__label">дистанция</span>
                                </div>
                                <?php if (!empty($r['elevation_gain_m'])): ?>
                                    <div class="route-stat">
                                        <span class="route-stat__value"><?= (int)$r['elevation_gain_m'] ?> м</span>
                                        <span class="route-stat__label">набор</span>
                                    </div>
                                <?php endif; ?>
                                <div class="route-stat">
                                    <span class="route-stat__value"><?= $pointCount ?></span>
                                    <span class="route-stat__label">точек</span>
                                </div>
                            </div>

                            <!-- Автор -->
                            <div class="route-card__footer">
                                <span class="route-card__author">
                                    <?php if (!empty($r['avatar_url'])): ?>
                                        <span class="avatar avatar--sm">
                                            <img src="<?= e($r['avatar_url']) ?>" alt="">
                                        </span>
                                    <?php else: ?>
                                        <span class="avatar avatar--sm">
                                            <?= e(mb_substr((string)($r['display_name'] ?? '?'), 0, 1)) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="route-card__author-name">
                                        @<?= e($r['username'] ?? 'unknown') ?>
                                    </span>
                                </span>
                                <span class="muted">
                                    <?= e(time_ago((string)$r['created_at'])) ?>
                                </span>
                            </div>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Пагинация -->
        <nav class="routes-pagination">
            <?php $prevQs = http_build_query(['tab' => $tab, 'type' => $type, 'page' => $page - 1]); ?>
            <?php $nextQs = http_build_query(['tab' => $tab, 'type' => $type, 'page' => $page + 1]); ?>
            <?php if ($page > 1): ?>
                <a href="?<?= e($prevQs) ?>" class="btn btn--ghost">← Назад</a>
            <?php else: ?><span></span><?php endif; ?>
            <?php if (count($routes) === $limit): ?>
                <a href="?<?= e($nextQs) ?>" class="btn btn--ghost">Дальше →</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
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

$routeId = (int)($_GET['id'] ?? 0);
if ($routeId <= 0) {
    http_response_code(404);
    exit('Маршрут не найден');
}

// ============================================================
// ЭКСПОРТ В GPX — до всей остальной логики
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'gpx') {
    try {
        $route = Route::findById($routeId);
        if (!$route) {
            http_response_code(404);
            exit('Маршрут не найден');
        }

        $ownerId  = (int)$route['user_id'];
        $viewerId = $me ? (int)$me['id'] : null;
        $isPublic = (int)$route['is_public'] === 1;
        $isOwner  = $viewerId !== null && $viewerId === $ownerId;

        if (!$isPublic && !$isOwner) {
            http_response_code(403);
            exit('Доступ запрещён');
        }

        $track = Route::parseTrack($route['track_json'] ?? null);
        if (count($track) < 2) {
            http_response_code(400);
            exit('Трек маршрута пуст');
        }

        // Имя файла: route-{id}-{slug}.gpx
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', (string)$route['name']);
        $slug = trim((string)$slug, '-');
        if ($slug === '') $slug = 'pulse';
        $filename = 'route-' . $routeId . '-' . mb_substr($slug, 0, 40) . '.gpx';

        header('Content-Type: application/gpx+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');

        $nameSafe = htmlspecialchars((string)$route['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<gpx version="1.1" creator="Pulse" xmlns="http://www.topografix.com/GPX/1/1">' . "\n";
        echo '  <metadata>' . "\n";
        echo '    <name>' . $nameSafe . '</name>' . "\n";
        echo '    <time>' . date('c') . '</time>' . "\n";
        echo '  </metadata>' . "\n";
        echo '  <trk>' . "\n";
        echo '    <name>' . $nameSafe . '</name>' . "\n";
        echo '    <trkseg>' . "\n";

        foreach ($track as $p) {
            echo '      <trkpt lat="' . $p['lat'] . '" lon="' . $p['lng'] . '" />' . "\n";
        }

        echo '    </trkseg>' . "\n";
        echo '  </trk>' . "\n";
        echo '</gpx>' . "\n";
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        exit('Ошибка экспорта: ' . $e->getMessage());
    }
}

// ============================================================
// ЗАГРУЗКА МАРШРУТА
// ============================================================
$route = null;
$error = null;

try {
    $route = Route::findById($routeId);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($error !== null) {
    $pageTitle = 'Ошибка';
    require __DIR__ . '/includes/header.php';
    echo '<section class="form-page"><div class="form-card">';
    echo '<h1 class="form-card__title">Ошибка</h1>';
    echo '<div class="alert alert--error">' . e($error) . '</div>';
    echo '<p style="margin-top:16px"><a href="' . e(url('routes.php')) . '" class="btn btn--primary">К маршрутам</a></p>';
    echo '</div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

if (!$route) {
    http_response_code(404);
    $pageTitle = 'Маршрут не найден';
    require __DIR__ . '/includes/header.php';
    echo '<section class="form-page"><div class="form-card">';
    echo '<h1 class="form-card__title">Маршрут не найден</h1>';
    echo '<p>Возможно, он был удалён автором.</p>';
    echo '<p style="margin-top:16px"><a href="' . e(url('routes.php')) . '" class="btn btn--primary">К маршрутам</a></p>';
    echo '</div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ---- Проверка доступа ----
$ownerId  = (int)$route['user_id'];
$viewerId = $me ? (int)$me['id'] : null;
$isOwner  = $viewerId !== null && $viewerId === $ownerId;
$isPublic = (int)$route['is_public'] === 1;

if (!$isPublic && !$isOwner) {
    http_response_code(403);
    $pageTitle = 'Доступ запрещён';
    require __DIR__ . '/includes/header.php';
    echo '<section class="form-page"><div class="form-card">';
    echo '<h1 class="form-card__title">Доступ запрещён</h1>';
    echo '<p>Это приватный маршрут. Его видит только автор.</p>';
    echo '<p style="margin-top:16px"><a href="' . e(url('routes.php')) . '" class="btn btn--primary">К маршрутам</a></p>';
    echo '</div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// POST: удаление / смена видимости (только владелец)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner) {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        try {
            Route::delete($routeId, (int)$me['id']);
            flash('Маршрут удалён', 'success');
            redirect(url('routes.php'));
        } catch (Throwable $e) {
            flash('Не удалось удалить: ' . $e->getMessage(), 'error');
            redirect(url('route.php?id=' . $routeId));
        }
    }

    if ($action === 'toggle_public') {
        try {
            Route::setPublic($routeId, (int)$me['id'], $isPublic ? 0 : 1);
            flash($isPublic ? 'Маршрут скрыт' : 'Маршрут опубликован', 'success');
            redirect(url('route.php?id=' . $routeId));
        } catch (Throwable $e) {
            flash('Не удалось изменить видимость: ' . $e->getMessage(), 'error');
            redirect(url('route.php?id=' . $routeId));
        }
    }
}

// ============================================================
// Парсинг трека и меток — через модель
// ============================================================
$track     = Route::parseTrack($route['track_json'] ?? null);
$waypoints = Route::parseWaypoints($route['waypoints_json'] ?? null);

$pageTitle = $route['name'];

// Leaflet через footer (важно: extraJs подключается ДО inlineJs)
$extraCss = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'];
$extraJs  = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

// JS: карта + трек + метки + автоподгон зума
$inlineJs = '
window.__ROUTE_TRACK__ = ' . json_encode($track, JSON_UNESCAPED_UNICODE) . ';
window.__ROUTE_WAYPOINTS__ = ' . json_encode($waypoints, JSON_UNESCAPED_UNICODE) . ';
(function () {
    if (typeof L === "undefined") {
        console.error("Leaflet не загрузился");
        var el = document.getElementById("route-view-map");
        if (el) el.innerHTML = "<div style=\"padding:40px;text-align:center;color:#b3261e\">Не удалось загрузить карту. Обновите страницу.</div>";
        return;
    }

    var track = window.__ROUTE_TRACK__ || [];
    var waypoints = window.__ROUTE_WAYPOINTS__ || [];
    var mapEl = document.getElementById("route-view-map");
    if (!mapEl) return;

    var map = L.map("route-view-map", { scrollWheelZoom: false })
        .setView([55.751244, 37.618423], 12);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap"
    }).addTo(map);

    var allPoints = track.slice();
    waypoints.forEach(function (w) { allPoints.push({ lat: w.lat, lng: w.lng }); });

    if (allPoints.length < 2) {
        mapEl.innerHTML = "<div style=\"padding:40px;text-align:center;color:#8a93a3\">Трек маршрута пуст</div>";
        return;
    }

    var bounds = L.latLngBounds(allPoints.map(function (p) { return [p.lat, p.lng]; }));

    // ---- Линия трека ----
    if (track.length >= 2) {
        var latlngs = track.map(function (p) { return [p.lat, p.lng]; });
        var line = L.polyline(latlngs, {
            color: "#ff5a1f",
            weight: 5,
            opacity: 0.95
        }).addTo(map);

        // Старт
        L.circleMarker(latlngs[0], {
            radius: 7,
            color: "#0a7a3a",
            fillColor: "#0a7a3a",
            fillOpacity: 1,
            weight: 2
        }).bindPopup("Старт").addTo(map);

        // Финиш
        L.circleMarker(latlngs[latlngs.length - 1], {
            radius: 7,
            color: "#b3261e",
            fillColor: "#b3261e",
            fillOpacity: 1,
            weight: 2
        }).bindPopup("Финиш").addTo(map);
    }

    // ---- Метки с описанием ----
    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/\x27/g, "&#39;");
    }

    waypoints.forEach(function (w, idx) {
        var marker = L.marker([w.lat, w.lng], {
            icon: L.divIcon({
                className: "note-marker",
                html: "📍",
                iconSize: [24, 24],
                iconAnchor: [12, 22]
            })
        }).addTo(map);

        var popupHtml = "<strong>" + escapeHtml(w.name || ("Метка " + (idx + 1))) + "</strong>";
        if (w.note) popupHtml += "<br>" + escapeHtml(w.note);
        marker.bindPopup(popupHtml);
    });

    map.fitBounds(bounds, { padding: [30, 30] });

    // Перелёт к метке при клике в списке
    document.querySelectorAll(".waypoint-item").forEach(function (item) {
        item.addEventListener("click", function () {
            var lat = parseFloat(item.dataset.lat);
            var lng = parseFloat(item.dataset.lng);
            if (isNaN(lat) || isNaN(lng)) return;
            map.setView([lat, lng], Math.max(map.getZoom(), 15), { animate: true });
            // Открываем popup, если маркер есть
            map.eachLayer(function (layer) {
                if (layer instanceof L.Marker) {
                    var ll = layer.getLatLng();
                    if (Math.abs(ll.lat - lat) < 1e-6 && Math.abs(ll.lng - lng) < 1e-6) {
                        layer.openPopup();
                    }
                }
            });
        });
    });
})();
';

require __DIR__ . '/includes/header.php';

/**
 * Иконка и название типа маршрута.
 */
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

<section class="route-view">
    <!-- Заголовок -->
    <header class="route-view__head">
        <div class="route-view__title-block">
            <span class="route-type">
                <?= e(route_type_icon((string)$route['type'])) ?> <?= e(route_type_label((string)$route['type'])) ?>
            </span>
            <h1 class="route-view__name"><?= e($route['name']) ?></h1>
            <p class="muted">
                Создал
                <a href="<?= e(url('profile.php?u=' . urlencode((string)$route['username']))) ?>">
                    <strong>@<?= e($route['username']) ?></strong>
                </a>
                · <?= e(time_ago((string)$route['created_at'])) ?>
                <?php if (!$isPublic): ?> · 🔒 приватный<?php endif; ?>
            </p>
        </div>

        <div class="route-view__actions">
            <a href="<?= e(url('route.php?id=' . $routeId . '&export=gpx')) ?>"
               class="btn btn--ghost btn--sm" title="Скачать GPX">
                ⬇ GPX
            </a>

            <?php if ($isOwner): ?>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_public">
                    <button class="btn btn--ghost btn--sm">
                        <?= $isPublic ? '🔒 Скрыть' : '🌐 Опубликовать' ?>
                    </button>
                </form>

                <form method="post" style="display:inline"
                      onsubmit="return confirm('Удалить маршрут? Это необратимо.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn--ghost btn--sm">🗑 Удалить</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <!-- Статистика -->
    <div class="route-view__stats">
        <div class="stat">
            <span class="stat__value"><?= e(format_distance((float)$route['distance_m'])) ?></span>
            <span class="stat__label">Дистанция</span>
        </div>
        <?php if (!empty($route['elevation_gain_m'])): ?>
            <div class="stat">
                <span class="stat__value"><?= (int)$route['elevation_gain_m'] ?> м</span>
                <span class="stat__label">Набор высоты</span>
            </div>
        <?php endif; ?>
        <div class="stat">
            <span class="stat__value"><?= count($waypoints) ?></span>
            <span class="stat__label">Меток</span>
        </div>
        <div class="stat">
            <span class="stat__value"><?= count($track) ?></span>
            <span class="stat__label">Точек трека</span>
        </div>
    </div>

    <!-- Карта -->
    <div class="route-view__map">
        <div id="route-view-map" class="map"></div>
    </div>

    <!-- Описание -->
    <?php if (!empty($route['description'])): ?>
        <div class="route-view__desc">
            <h2>Описание</h2>
            <div><?= nl2br(e((string)$route['description'])) ?></div>
        </div>
    <?php endif; ?>

    <!-- Метки списком -->
    <?php if ($waypoints): ?>
        <div class="route-view__waypoints">
            <h2>Точки маршрута (<?= count($waypoints) ?>)</h2>
            <p class="muted" style="margin-bottom:14px">Кликните на точку, чтобы перелететь к ней на карте.</p>
            <ol class="waypoint-list">
                <?php foreach ($waypoints as $i => $w): ?>
                    <li class="waypoint-item"
                        data-lat="<?= e((string)$w['lat']) ?>"
                        data-lng="<?= e((string)$w['lng']) ?>">
                        <span class="waypoint-item__num"><?= $i + 1 ?></span>
                        <div class="waypoint-item__body">
                            <div class="waypoint-item__name">
                                <?= $w['name'] !== '' ? e($w['name']) : 'Без названия' ?>
                            </div>
                            <?php if ($w['note'] !== ''): ?>
                                <div class="waypoint-item__note"><?= nl2br(e($w['note'])) ?></div>
                            <?php endif; ?>
                            <div class="waypoint-item__coords muted">
                                <?= number_format((float)$w['lat'], 5) ?>, <?= number_format((float)$w['lng'], 5) ?>
                            </div>
                        </div>
                        <span class="waypoint-item__cta">На карту →</span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    <?php endif; ?>

    <!-- CTA -->
    <div class="route-view__cta">
        <?php if ($me): ?>
            <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--primary">
                🏃 Загрузить активность по этому маршруту
            </a>
        <?php else: ?>
            <a href="<?= e(url('register.php')) ?>" class="btn btn--primary">
                Зарегистрироваться, чтобы сохранять маршруты
            </a>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
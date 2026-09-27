<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Segment.php';

auth_start();
$me = current_user();

// ---- Фильтры ----
$type  = (string)($_GET['type'] ?? '');
$sort  = (string)($_GET['sort'] ?? 'new');
$limit = 50;

$allowedTypes = ['', 'run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
if (!in_array($type, $allowedTypes, true)) $type = '';

$allowedSort = ['new', 'popular', 'longest'];
if (!in_array($sort, $allowedSort, true)) $sort = 'new';

// ---- Загрузка ----
$segments = [];
$error = null;

try {
    $sql = 'SELECT s.*, u.username, u.display_name,
                   (SELECT COUNT(DISTINCT user_id) FROM segment_efforts e WHERE e.segment_id = s.id) AS athletes,
                   (SELECT COUNT(*) FROM segment_efforts e WHERE e.segment_id = s.id) AS efforts
            FROM segments s
            JOIN users u ON u.id = s.creator_id
            WHERE s.is_public = 1';

    $params = [];

    if ($type !== '') {
        $sql .= ' AND s.type = :type';
        $params[':type'] = $type;
    }

    switch ($sort) {
        case 'popular': $sql .= ' ORDER BY athletes DESC, s.created_at DESC'; break;
        case 'longest': $sql .= ' ORDER BY s.distance_m DESC'; break;
        default:        $sql .= ' ORDER BY s.created_at DESC';
    }

    $sql .= ' LIMIT :lim';

    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $segments = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
    $segments = [];
}

// ---- Упрощаем треки для миниатюр ----
$tracksById = [];
foreach ($segments as $s) {
    if (empty($s['track_json'])) {
        $tracksById[(int)$s['id']] = [];
        continue;
    }
    $raw = json_decode((string)$s['track_json'], true);
    if (!is_array($raw) || count($raw) < 2) {
        $tracksById[(int)$s['id']] = [];
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
    // Не больше 80 точек на миниатюру
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
    $tracksById[(int)$s['id']] = $points;
}

$pageTitle = 'Сегменты';

// Leaflet подключаем через footer
$extraCss = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'];
$extraJs  = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

$inlineJs = '
window.__SEGMENTS_TRACKS__ = ' . json_encode($tracksById, JSON_UNESCAPED_UNICODE) . ';
(function () {
    if (typeof L === "undefined") {
        console.error("Leaflet не загрузился");
        return;
    }
    var tracks = window.__SEGMENTS_TRACKS__ || {};

    function initMap(el) {
        if (el.dataset.initialized === "1") return;
        var id = el.dataset.segmentId;
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

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);

        var latlngs = pts.map(function (p) { return [p.lat, p.lng]; });
        var line = L.polyline(latlngs, { color: "#ff5a1f", weight: 4, opacity: 0.95 }).addTo(map);

        L.circleMarker(latlngs[0], {
            radius: 4, color: "#0a7a3a", fillColor: "#0a7a3a", fillOpacity: 1
        }).addTo(map);
        L.circleMarker(latlngs[latlngs.length - 1], {
            radius: 4, color: "#b3261e", fillColor: "#b3261e", fillOpacity: 1
        }).addTo(map);

        map.fitBounds(line.getBounds(), { padding: [10, 10] });
    }

    var maps = document.querySelectorAll(".segment-thumb");
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
})();
';

require __DIR__ . '/includes/header.php';

function segment_type_icon(string $type): string
{
    return match ($type) {
        'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
        'walk' => '🚶', 'hike' => '🥾', default => '📦',
    };
}
function segment_type_label(string $type): string
{
    return match ($type) {
        'run' => 'Бег', 'ride' => 'Вело', 'swim' => 'Плавание', 'ski' => 'Лыжи',
        'walk' => 'Ходьба', 'hike' => 'Хайкинг', default => 'Другое',
    };
}
?>

<section class="segments-page">
    <header class="segments-page__head">
        <div>
            <h1 class="section-title" style="text-align:left;margin-bottom:4px">Сегменты</h1>
            <p class="muted">Участки, где спортсмены соревнуются на время</p>
        </div>
        <?php if ($me): ?>
            <a href="<?= e(url('segment-create.php')) ?>" class="btn btn--primary">+ Создать сегмент</a>
        <?php endif; ?>
    </header>

    <?php if ($error !== null): ?>
        <div class="alert alert--error"><strong>Ошибка загрузки:</strong> <?= e($error) ?></div>
    <?php endif; ?>

    <div class="segments-filters">
        <div class="segments-filters__group">
            <span class="segments-filters__label">Тип:</span>
            <?php
                $typeOptions = [
                    '' => 'Все', 'run' => '🏃 Бег', 'ride' => '🚴 Вело',
                    'swim' => '🏊 Плавание', 'ski' => '⛷️ Лыжи',
                    'walk' => '🚶 Ходьба', 'hike' => '🥾 Хайкинг',
                ];
            ?>
            <?php foreach ($typeOptions as $value => $label): ?>
                <?php $qs = http_build_query(['type' => $value, 'sort' => $sort]); ?>
                <a href="?<?= e($qs) ?>" class="segments-filter <?= $type === $value ? 'is-active' : '' ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="segments-filters__group">
            <span class="segments-filters__label">Сортировка:</span>
            <?php
                $sortOptions = ['new' => 'Новые', 'popular' => 'Популярные', 'longest' => 'Длинные'];
            ?>
            <?php foreach ($sortOptions as $value => $label): ?>
                <?php $qs = http_build_query(['type' => $type, 'sort' => $value]); ?>
                <a href="?<?= e($qs) ?>" class="segments-filter <?= $sort === $value ? 'is-active' : '' ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$segments): ?>
        <div class="empty">
            <p><?= $type !== '' ? 'Сегментов такого типа пока нет.' : 'Пока нет публичных сегментов.' ?></p>
            <?php if ($me): ?>
                <a href="<?= e(url('segment-create.php')) ?>" class="btn btn--primary">Создать первый сегмент</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="muted" style="margin-bottom:16px">Найдено сегментов: <strong><?= count($segments) ?></strong></p>

        <div class="segments-grid">
            <?php foreach ($segments as $s): ?>
                <?php $hasTrack = !empty($tracksById[(int)$s['id']]); ?>
                <a class="segment-card" href="<?= e(url('segment.php?id=' . (int)$s['id'])) ?>">
                    <!-- Миниатюра карты -->
                    <div class="segment-card__thumb-wrap">
                        <?php if ($hasTrack): ?>
                            <div class="segment-thumb"
                                 data-segment-id="<?= (int)$s['id'] ?>"
                                 data-initialized="0"></div>
                        <?php else: ?>
                            <div class="segment-thumb segment-thumb--empty">
                                <span class="segment-thumb__empty-icon">🗺️</span>
                                <span class="muted">Нет трека</span>
                            </div>
                        <?php endif; ?>

                        <span class="segment-card__type-badge">
                            <?= e(segment_type_icon((string)$s['type'])) ?>
                            <?= e(segment_type_label((string)$s['type'])) ?>
                        </span>
                    </div>

                    <!-- Тело -->
                    <div class="segment-card__body">
                        <h3 class="segment-card__title"><?= e($s['name']) ?></h3>

                        <div class="segment-card__stats">
                            <div class="segment-card__stat">
                                <span class="segment-card__value"><?= e(format_distance((float)$s['distance_m'])) ?></span>
                                <span class="segment-card__label">дистанция</span>
                            </div>
                            <?php if (!empty($s['elevation_gain_m'])): ?>
                                <div class="segment-card__stat">
                                    <span class="segment-card__value"><?= (int)$s['elevation_gain_m'] ?> м</span>
                                    <span class="segment-card__label">набор</span>
                                </div>
                            <?php endif; ?>
                            <div class="segment-card__stat">
                                <span class="segment-card__value"><?= (int)$s['efforts'] ?></span>
                                <span class="segment-card__label">попыток</span>
                            </div>
                            <div class="segment-card__stat">
                                <span class="segment-card__value"><?= (int)$s['athletes'] ?></span>
                                <span class="segment-card__label">спортсменов</span>
                            </div>
                        </div>

                        <div class="segment-card__footer">
                            <span class="muted">от @<?= e($s['username']) ?></span>
                            <span class="segment-card__cta">Открыть →</span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
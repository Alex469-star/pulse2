<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/models/Like.php';
require_once __DIR__ . '/models/Comment.php';
require_once __DIR__ . '/models/Segment.php';
require_once __DIR__ . '/models/Follow.php';

auth_start();
$me = current_user();

$activityId = (int)($_GET['id'] ?? 0);
if ($activityId <= 0) {
    http_response_code(404);
    exit('Активность не найдена');
}

// ============================================================
// ЭКСПОРТ GPX
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'gpx') {
    $activity = Activity::findById($activityId);
    if (!$activity) { http_response_code(404); exit('Не найдено'); }

    $isOwner = $me && (int)$me['id'] === (int)$activity['user_id'];
    $visibility = (string)$activity['visibility'];

    if ($visibility === 'private' && !$isOwner) { http_response_code(403); exit('Доступ запрещён'); }
    if ($visibility === 'followers' && !$isOwner) {
        $ok = $me ? Follow::isFollowing((int)$me['id'], (int)$activity['user_id']) : false;
        if (!$ok) { http_response_code(403); exit('Доступ запрещён'); }
    }

    $track = json_decode((string)$activity['track_json'], true) ?: [];
    if (count($track) < 2) { http_response_code(400); exit('Трек пуст'); }

    $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', (string)$activity['title']);
    $slug = trim((string)$slug, '-') ?: 'activity';
    $filename = 'activity-' . $activityId . '-' . mb_substr($slug, 0, 40) . '.gpx';

    header('Content-Type: application/gpx+xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $nameSafe = htmlspecialchars((string)$activity['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<gpx version="1.1" creator="Pulse" xmlns="http://www.topografix.com/GPX/1/1">' . "\n";
    echo '  <metadata><name>' . $nameSafe . '</name><time>' . date('c') . '</time></metadata>' . "\n";
    echo '  <trk><name>' . $nameSafe . '</name><trkseg>' . "\n";
    foreach ($track as $p) {
        if (!isset($p['lat'], $p['lng'])) continue;
        echo '    <trkpt lat="' . (float)$p['lat'] . '" lon="' . (float)$p['lng'] . '">';
        if (!empty($p['t']) && $p['t'] > 0) {
            echo '<time>' . date('c', (int)$p['t']) . '</time>';
        }
        echo '</trkpt>' . "\n";
    }
    echo '  </trkseg></trk></gpx>' . "\n";
    exit;
}

// ============================================================
// ЭКСПОРТ TCX
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'tcx') {
    $activity = Activity::findById($activityId);
    if (!$activity) { http_response_code(404); exit('Не найдено'); }

    $isOwner = $me && (int)$me['id'] === (int)$activity['user_id'];
    $visibility = (string)$activity['visibility'];
    if ($visibility === 'private' && !$isOwner) { http_response_code(403); exit('Доступ запрещён'); }
    if ($visibility === 'followers' && !$isOwner) {
        $ok = $me ? Follow::isFollowing((int)$me['id'], (int)$activity['user_id']) : false;
        if (!$ok) { http_response_code(403); exit('Доступ запрещён'); }
    }

    $track = json_decode((string)$activity['track_json'], true) ?: [];
    if (count($track) < 2) { http_response_code(400); exit('Трек пуст'); }

    $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', (string)$activity['title']);
    $slug = trim((string)$slug, '-') ?: 'activity';
    $filename = 'activity-' . $activityId . '-' . mb_substr($slug, 0, 40) . '.tcx';

    header('Content-Type: application/vnd.garmin.tcx+xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $startTime = date('c', strtotime((string)($activity['started_at'] ?? $activity['created_at'])));

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">' . "\n";
    echo '  <Activities><Activity Sport="' . e((string)$activity['type']) . '">' . "\n";
    echo '    <Id>' . $startTime . '</Id>' . "\n";
    echo '    <Lap StartTime="' . $startTime . '">' . "\n";
    echo '      <TotalTimeSeconds>' . (int)($activity['duration_sec'] ?? 0) . '</TotalTimeSeconds>' . "\n";
    echo '      <DistanceMeters>' . (float)($activity['distance_m'] ?? 0) . '</DistanceMeters>' . "\n";
    echo '      <Track>' . "\n";
    foreach ($track as $p) {
        if (!isset($p['lat'], $p['lng'])) continue;
        echo '        <Trackpoint>' . "\n";
        if (!empty($p['t']) && $p['t'] > 0) {
            echo '          <Time>' . date('c', (int)$p['t']) . '</Time>' . "\n";
        }
        echo '          <Position><LatitudeDegrees>' . (float)$p['lat'] . '</LatitudeDegrees><LongitudeDegrees>' . (float)$p['lng'] . '</LongitudeDegrees></Position>' . "\n";
        if (!empty($p['ele'])) {
            echo '          <AltitudeMeters>' . (float)$p['ele'] . '</AltitudeMeters>' . "\n";
        }
        echo '        </Trackpoint>' . "\n";
    }
    echo '      </Track></Lap></Activity></Activities>' . "\n";
    echo '</TrainingCenterDatabase>' . "\n";
    exit;
}

// ============================================================
// ЗАГРУЗКА АКТИВНОСТИ
// ============================================================
$activity = null;
$error = null;

try {
    $activity = Activity::findById($activityId);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (!$activity && $error === null) {
    http_response_code(404);
    $pageTitle = 'Активность не найдена';
    require __DIR__ . '/includes/header.php';
    echo '<section class="form-page"><div class="form-card">';
    echo '<h1 class="form-card__title">Активность не найдена</h1>';
    echo '<p>Возможно, она была удалена.</p>';
    echo '<p style="margin-top:16px"><a href="' . e(url('feed.php')) . '" class="btn btn--primary">В ленту</a></p>';
    echo '</div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($error !== null) {
    $pageTitle = 'Ошибка';
    require __DIR__ . '/includes/header.php';
    echo '<section class="form-page"><div class="form-card">';
    echo '<h1 class="form-card__title">Ошибка</h1>';
    echo '<div class="alert alert--error">' . e($error) . '</div>';
    echo '</div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$isOwner = $me && (int)$me['id'] === (int)$activity['user_id'];
$visibility = (string)$activity['visibility'];

if ($visibility === 'private' && !$isOwner) {
    http_response_code(403);
    $pageTitle = 'Доступ запрещён';
    require __DIR__ . '/includes/header.php';
    echo '<section class="form-page"><div class="form-card">';
    echo '<h1 class="form-card__title">Доступ запрещён</h1>';
    echo '<p>Это приватная активность.</p>';
    echo '<p style="margin-top:16px"><a href="' . e(url('feed.php')) . '" class="btn btn--primary">В ленту</a></p>';
    echo '</div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($visibility === 'followers' && !$isOwner) {
    $isFollowing = $me ? Follow::isFollowing((int)$me['id'], (int)$activity['user_id']) : false;
    if (!$isFollowing) {
        http_response_code(403);
        $pageTitle = 'Доступ запрещён';
        require __DIR__ . '/includes/header.php';
        echo '<section class="form-page"><div class="form-card">';
        echo '<h1 class="form-card__title">Доступ запрещён</h1>';
        echo '<p>Эта активность доступна только подписчикам автора.</p>';
        echo '<p style="margin-top:16px"><a href="' . e(url('feed.php')) . '" class="btn btn--primary">В ленту</a></p>';
        echo '</div></section>';
        require __DIR__ . '/includes/footer.php';
        exit;
    }
}

// ============================================================
// POST: лайк, комментарий, удаление, изменение видимости
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me) {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'like') {
        try { Like::toggle((int)$me['id'], $activityId); }
        catch (Throwable $e) { flash('Ошибка лайка: ' . $e->getMessage(), 'error'); }
        redirect(url('activity.php?id=' . $activityId));
    }

    if ($action === 'comment') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '') {
            try {
                Comment::add($activityId, (int)$me['id'], $body);
                flash('Комментарий добавлен', 'success');
            } catch (Throwable $e) {
                flash('Ошибка: ' . $e->getMessage(), 'error');
            }
        }
        redirect(url('activity.php?id=' . $activityId . '#comments'));
    }

    if ($action === 'delete_comment' && !empty($_POST['comment_id'])) {
        try {
            $cid = (int)$_POST['comment_id'];
            db()->prepare(
                'DELETE FROM activity_comments
                 WHERE id = ?
                   AND (user_id = ? OR ? = 1)'
            )->execute([$cid, (int)$me['id'], $isOwner ? 1 : 0]);
            flash('Комментарий удалён', 'success');
        } catch (Throwable $e) {
            flash('Ошибка: ' . $e->getMessage(), 'error');
        }
        redirect(url('activity.php?id=' . $activityId . '#comments'));
    }

    if ($action === 'delete' && $isOwner) {
        try {
            Activity::delete($activityId, (int)$me['id']);
            flash('Активность удалена', 'success');
            redirect(url('feed.php'));
        } catch (Throwable $e) {
            flash('Не удалось удалить: ' . $e->getMessage(), 'error');
            redirect(url('activity.php?id=' . $activityId));
        }
    }

    if ($action === 'toggle_visibility' && $isOwner) {
        $newVisibility = $visibility === 'public' ? 'private' : 'public';
        try {
            db()->prepare('UPDATE activities SET visibility = ? WHERE id = ? AND user_id = ?')
                ->execute([$newVisibility, $activityId, (int)$me['id']]);
            flash($newVisibility === 'public' ? 'Активность опубликована' : 'Активность скрыта', 'success');
        } catch (Throwable $e) {
            flash('Ошибка: ' . $e->getMessage(), 'error');
        }
        redirect(url('activity.php?id=' . $activityId));
    }
}

// ---- Данные для страницы ----
$comments = [];
$segments = [];
$likeInfo = ['count' => 0, 'liked' => false];
$gear = null;
$activityPhotos = [];

try { $comments = Comment::forActivity($activityId); } catch (Throwable $e) { $comments = []; }
try { $segments = Segment::effortsForActivity($activityId); } catch (Throwable $e) { $segments = []; }
try { $activityPhotos = Activity::photos($activityId); } catch (Throwable $e) { $activityPhotos = []; }

try {
    $stmt = db()->prepare('SELECT COUNT(*) FROM activity_likes WHERE activity_id = ?');
    $stmt->execute([$activityId]);
    $likeInfo['count'] = (int)$stmt->fetchColumn();

    if ($me) {
        $stmt = db()->prepare('SELECT 1 FROM activity_likes WHERE activity_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$activityId, (int)$me['id']]);
        $likeInfo['liked'] = (bool)$stmt->fetchColumn();
    }
} catch (Throwable $e) {}

if (!empty($activity['gear_id'])) {
    try {
        $stmt = db()->prepare('SELECT * FROM gear WHERE id = ?');
        $stmt->execute([(int)$activity['gear_id']]);
        $gear = $stmt->fetch() ?: null;
    } catch (Throwable $e) {}
}

// ============================================================
// ТРЕК + ДАННЫЕ ДЛЯ ГРАФИКОВ (высоты и скорости)
// ============================================================
$track     = [];   // для карты: [{lat, lng}]
$elevData  = [];   // [{d, ele}] — дистанция (м) и высота (м)
$speedData = [];   // [{d, v}]   — дистанция (м) и скорость (м/с)

if (!empty($activity['track_json'])) {
    $decoded = json_decode((string)$activity['track_json'], true);
    if (is_array($decoded)) {

        // Приводим точки к единому виду
        $points = [];
        foreach ($decoded as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            $points[] = [
                'lat' => (float)$p['lat'],
                'lng' => (float)$p['lng'],
                'ele' => isset($p['ele']) ? (float)$p['ele'] : null,
                't'   => isset($p['t'])   ? (int)$p['t']     : null,
            ];
        }

        // --- Основной трек для карты ---
        foreach ($points as $p) {
            $track[] = ['lat' => $p['lat'], 'lng' => $p['lng']];
        }

        // --- Haversine: дистанция между двумя точками в метрах ---
        $haversine = static function (float $lat1, float $lng1, float $lat2, float $lng2): float {
            $R = 6371000.0;
            $dLat = deg2rad($lat2 - $lat1);
            $dLng = deg2rad($lng2 - $lng1);
            $a = sin($dLat / 2) ** 2
               + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
            return 2 * $R * asin(min(1.0, sqrt($a)));
        };

        $dist = 0.0;
        $prev = null;

        foreach ($points as $i => $p) {
            if ($prev !== null) {
                $dist += $haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
            }
            $prev = $p;

            // Профиль высот
            if ($p['ele'] !== null) {
                $elevData[] = [
                    'd'   => round($dist, 1),
                    'ele' => round($p['ele'], 1),
                ];
            }

            // График скорости
            if ($p['t'] !== null && $i > 0 && $points[$i - 1]['t'] !== null) {
                $dt = $p['t'] - $points[$i - 1]['t'];
                if ($dt > 0) {
                    $seg = $haversine(
                        $points[$i - 1]['lat'], $points[$i - 1]['lng'],
                        $p['lat'], $p['lng']
                    );
                    $speed = $seg / $dt;
                    // Отсекаем шум > 30 м/с (108 км/ч)
                    if ($speed < 30) {
                        $speedData[] = [
                            'd' => round($dist, 1),
                            'v' => round($speed, 2),
                        ];
                    }
                }
            }
        }

        // --- Прореживание для производительности Chart.js ---
        $decimate = static function (array $data, int $maxPoints = 800): array {
            $n = count($data);
            if ($n <= $maxPoints) return $data;
            $step = (int)ceil($n / $maxPoints);
            $out = [];
            for ($i = 0; $i < $n; $i += $step) {
                $out[] = $data[$i];
            }
            if (end($out) !== end($data)) {
                $out[] = end($data);
            }
            return $out;
        };

        $elevData  = $decimate($elevData,  800);
        $speedData = $decimate($speedData, 800);
    }
}

$pageTitle = $activity['title'];

$extraCss = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
];

$extraJs = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
];

$publicUrl = app_url('activity.php?id=' . $activityId);
$shareText = rawurlencode((string)$activity['title'] . ' — Pulse');

// Общая дистанция для синхронизации курсора
$totalDistance = !empty($elevData) ? (float)end($elevData)['d'] : 0.0;

$inlineJs = '
window.__ACTIVITY_TRACK__     = ' . json_encode($track, JSON_UNESCAPED_UNICODE) . ';
window.__ACTIVITY_ELEV__      = ' . json_encode($elevData, JSON_UNESCAPED_UNICODE) . ';
window.__ACTIVITY_SPEED__     = ' . json_encode($speedData, JSON_UNESCAPED_UNICODE) . ';
window.__ACTIVITY_TOTAL_D__   = ' . json_encode($totalDistance) . ';
window.__ACTIVITY_URL__       = ' . json_encode($publicUrl) . ';

/* ============================================================
   КАРТА LEAFLET
   ============================================================ */
(function () {
    if (typeof L === "undefined") return;
    var pts = window.__ACTIVITY_TRACK__ || [];
    var mapEl = document.getElementById("activity-map");
    if (!mapEl) return;
    if (pts.length < 2) {
        mapEl.innerHTML = "<div style=\"padding:40px;text-align:center;color:#8a93a3\">Нет GPS-трека</div>";
        return;
    }
    var map = L.map("activity-map", { scrollWheelZoom: false }).setView([pts[0].lat, pts[0].lng], 14);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19, attribution: "&copy; OpenStreetMap"
    }).addTo(map);
    var latlngs = pts.map(function (p) { return [p.lat, p.lng]; });
    var line = L.polyline(latlngs, { color: "#ff5a1f", weight: 5, opacity: 0.95 }).addTo(map);
    L.circleMarker(latlngs[0], { radius: 7, color: "#0a7a3a", fillColor: "#0a7a3a", fillOpacity: 1, weight: 2 }).bindPopup("Старт").addTo(map);
    L.circleMarker(latlngs[latlngs.length - 1], { radius: 7, color: "#b3261e", fillColor: "#b3261e", fillOpacity: 1, weight: 2 }).bindPopup("Финиш").addTo(map);
    map.fitBounds(line.getBounds(), { padding: [30, 30] });

    // Сохраняем карту в глобальную переменную для синхронизации с графиками
    window.__ACTIVITY_MAP__ = map;
})();

/* ============================================================
   КОПИРОВАНИЕ ССЫЛКИ
   ============================================================ */
document.addEventListener("click", function (e) {
    var btn = e.target.closest(".js-copy-link");
    if (!btn) return;
    e.preventDefault();
    var url = window.__ACTIVITY_URL__;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () {
            var old = btn.textContent;
            btn.textContent = "✓ Скопировано";
            btn.classList.add("is-copied");
            setTimeout(function () { btn.textContent = old; btn.classList.remove("is-copied"); }, 1500);
        });
    } else {
        window.prompt("Скопируйте ссылку:", url);
    }
});

/* ============================================================
   МОДАЛКА ЛАЙКНУВШИХ
   ============================================================ */
(function () {
    "use strict";

    var apiUrl = ' . json_encode(url('api/activity-likers.php')) . ';
    var cache = {};

    function el(id) { return document.getElementById(id); }

    function ensureModal() {
        if (el("likers-modal")) return;

        var modal = document.createElement("div");
        modal.className = "likers-modal";
        modal.id = "likers-modal";
        modal.hidden = true;
        modal.innerHTML =
            "<div class=\"likers-modal__backdrop\" data-close></div>" +
            "<div class=\"likers-modal__panel\" role=\"dialog\" aria-modal=\"true\">" +
                "<div class=\"likers-modal__head\">" +
                    "<h3 class=\"likers-modal__title\">Лайкнули</h3>" +
                    "<button type=\"button\" class=\"likers-modal__close\" data-close aria-label=\"Закрыть\">×</button>" +
                "</div>" +
                "<div class=\"likers-modal__body\" id=\"likers-modal-body\"></div>" +
            "</div>";

        document.body.appendChild(modal);

        modal.addEventListener("click", function (e) {
            if (e.target.closest("[data-close]")) close();
        });
        document.addEventListener("keydown", function (e) {
            if (!modal.hidden && e.key === "Escape") close();
        });
    }

    function open(activityId) {
        ensureModal();
        var modal = el("likers-modal");
        var body = el("likers-modal-body");
        modal.hidden = false;
        document.body.style.overflow = "hidden";

        if (cache[activityId]) {
            body.innerHTML = cache[activityId];
            return;
        }

        body.innerHTML = "<div class=\"likers-modal__loading\">Загрузка…</div>";

        fetch(apiUrl + "?activity_id=" + encodeURIComponent(activityId), {
            credentials: "same-origin",
            headers: { "Accept": "application/json" }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.ok) {
                throw new Error((res && res.error) || "Ошибка");
            }
            var data = res.data || {};
            var likers = data.likers || [];
            var html = renderList(likers);
            cache[activityId] = html;
            body.innerHTML = html;
        })
        .catch(function (err) {
            body.innerHTML = "<div class=\"likers-modal__empty\">Не удалось загрузить список</div>";
            console.error(err);
        });
    }

    function renderList(likers) {
        if (!likers.length) {
            return "<div class=\"likers-modal__empty\">Пока никто не лайкнул</div>";
        }
        var html = "<ul class=\"likers-list\">";
        likers.forEach(function (u) {
            var avatar = u.avatar_url
                ? "<img src=\"" + escapeAttr(u.avatar_url) + "\" alt=\"\">"
                : escapeHtml(u.initial || "?");
            html +=
                "<li class=\"likers-list__item\">" +
                    "<a class=\"likers-list__link\" href=\"" + escapeAttr(u.profile_url) + "\">" +
                        "<span class=\"avatar avatar--sm\">" + avatar + "</span>" +
                        "<span class=\"likers-list__info\">" +
                            "<span class=\"likers-list__name\">" + escapeHtml(u.display_name) + "</span>" +
                            "<span class=\"likers-list__username\">@" + escapeHtml(u.username) + "</span>" +
                        "</span>" +
                    "</a>" +
                "</li>";
        });
        html += "</ul>";
        return html;
    }

    function close() {
        var modal = el("likers-modal");
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = "";
    }

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/\x27/g, "&#039;");
    }
    function escapeAttr(s) { return escapeHtml(s); }

    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-likers-btn");
        if (!btn) return;
        e.preventDefault();
        var aid = parseInt(btn.dataset.activityId, 10);
        if (!aid) return;
        open(aid);
    });
})();

/* ============================================================
   ГРАФИКИ: ПРОФИЛЬ ВЫСОТ И СКОРОСТЬ
   ============================================================ */
(function () {
    if (typeof Chart === "undefined") return;

    var elevData  = window.__ACTIVITY_ELEV__  || [];
    var speedData = window.__ACTIVITY_SPEED__ || [];
    var totalD    = window.__ACTIVITY_TOTAL_D__ || 0;

    Chart.defaults.font.family = "\'Inter\', system-ui, sans-serif";
    Chart.defaults.font.size   = 11;
    Chart.defaults.color       = "#5b6473";

    /* ---------- Профиль высот ---------- */
    if (elevData.length && document.getElementById("elev-chart")) {
        var minEle = Infinity, maxEle = -Infinity, sumGain = 0, prevEle = null;

        elevData.forEach(function (p) {
            if (p.ele < minEle) minEle = p.ele;
            if (p.ele > maxEle) maxEle = p.ele;
            if (prevEle !== null && p.ele > prevEle) sumGain += (p.ele - prevEle);
            prevEle = p.ele;
        });

        var elevStats = document.getElementById("elev-stats");
        if (elevStats) {
            elevStats.innerHTML =
                "<span>мин <strong>" + Math.round(minEle) + " м</strong></span>" +
                "<span>макс <strong>" + Math.round(maxEle) + " м</strong></span>" +
                "<span>набор <strong>" + Math.round(sumGain) + " м</strong></span>";
        }

        var ctxElev = document.getElementById("elev-chart").getContext("2d");
        var gradElev = ctxElev.createLinearGradient(0, 0, 0, 220);
        gradElev.addColorStop(0, "rgba(255,90,31,.35)");
        gradElev.addColorStop(1, "rgba(255,90,31,.02)");

        window.__ELEV_CHART__ = new Chart(ctxElev, {
            type: "line",
            data: {
                labels: elevData.map(function (p) { return p.d; }),
                datasets: [{
                    label: "Высота, м",
                    data: elevData.map(function (p) { return p.ele; }),
                    borderColor: "#ff5a1f",
                    backgroundColor: gradElev,
                    borderWidth: 1.5,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointHoverBackgroundColor: "#ff5a1f",
                    pointHoverBorderColor: "#fff",
                    pointHoverBorderWidth: 2,
                    fill: true,
                    tension: 0.25,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: "rgba(15,20,32,.92)",
                        titleColor: "#fff",
                        bodyColor: "#fff",
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function (items) {
                                var d = items[0].parsed.x;
                                return (d >= 1000
                                    ? (d / 1000).toFixed(2) + " км"
                                    : Math.round(d) + " м");
                            },
                            label: function (item) {
                                return "Высота: " + item.parsed.y.toFixed(0) + " м";
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: "linear",
                        grid: { display: false },
                        ticks: {
                            maxTicksLimit: 6,
                            callback: function (v) {
                                return v >= 1000
                                    ? (v / 1000).toFixed(1) + " км"
                                    : Math.round(v) + " м";
                            }
                        }
                    },
                    y: {
                        grid: { color: "rgba(15,20,32,.06)" },
                        ticks: { maxTicksLimit: 5, callback: function (v) { return v + " м"; } }
                    }
                }
            }
        });
    }

    /* ---------- График скорости ---------- */
    if (speedData.length && document.getElementById("speed-chart")) {
        var sumV = 0, maxV = 0;
        speedData.forEach(function (p) {
            sumV += p.v;
            if (p.v > maxV) maxV = p.v;
        });
        var avgV = sumV / speedData.length;

        var speedStats = document.getElementById("speed-stats");
        if (speedStats) {
            speedStats.innerHTML =
                "<span>сред. <strong>" + (avgV * 3.6).toFixed(1) + " км/ч</strong></span>" +
                "<span>макс <strong>" + (maxV * 3.6).toFixed(1) + " км/ч</strong></span>";
        }

        var ctxSpeed = document.getElementById("speed-chart").getContext("2d");
        var gradSpeed = ctxSpeed.createLinearGradient(0, 0, 0, 220);
        gradSpeed.addColorStop(0, "rgba(31,95,196,.30)");
        gradSpeed.addColorStop(1, "rgba(31,95,196,.02)");

        window.__SPEED_CHART__ = new Chart(ctxSpeed, {
            type: "line",
            data: {
                labels: speedData.map(function (p) { return p.d; }),
                datasets: [{
                    label: "Скорость, км/ч",
                    data: speedData.map(function (p) { return p.v * 3.6; }),
                    borderColor: "#1f5fc4",
                    backgroundColor: gradSpeed,
                    borderWidth: 1.5,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointHoverBackgroundColor: "#1f5fc4",
                    pointHoverBorderColor: "#fff",
                    pointHoverBorderWidth: 2,
                    fill: true,
                    tension: 0.25,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: "rgba(15,20,32,.92)",
                        titleColor: "#fff",
                        bodyColor: "#fff",
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function (items) {
                                var d = items[0].parsed.x;
                                return (d >= 1000
                                    ? (d / 1000).toFixed(2) + " км"
                                    : Math.round(d) + " м");
                            },
                            label: function (item) {
                                return "Скорость: " + item.parsed.y.toFixed(1) + " км/ч";
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: "linear",
                        grid: { display: false },
                        ticks: {
                            maxTicksLimit: 6,
                            callback: function (v) {
                                return v >= 1000
                                    ? (v / 1000).toFixed(1) + " км"
                                    : Math.round(v) + " м";
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: "rgba(15,20,32,.06)" },
                        ticks: { maxTicksLimit: 5, callback: function (v) { return v + " км/ч"; } }
                    }
                }
            }
        });
    }

    /* ---------- Синхронизация курсора графиков с картой ---------- */
    (function () {
        var map = window.__ACTIVITY_MAP__;
        var track = window.__ACTIVITY_TRACK__ || [];
        if (!map || !track.length) return;

        var marker = L.circleMarker([track[0].lat, track[0].lng], {
            radius: 7,
            color: "#1f5fc4",
            fillColor: "#1f5fc4",
            fillOpacity: 1,
            weight: 2,
            opacity: 0
        }).addTo(map);

        // Обратный индекс: для каждой точки графика — индекс в track.
        // Т.к. мы прореживали графики, а не track, используем пропорцию.
        var totalTrackIdx = track.length - 1;

        function showAtDistance(dist) {
            if (totalD <= 0) return;
            var ratio = Math.max(0, Math.min(1, dist / totalD));
            var idx = Math.round(ratio * totalTrackIdx);
            var p = track[idx];
            if (!p) return;
            marker.setLatLng([p.lat, p.lng]);
            marker.setStyle({ opacity: 1 });
        }

        function hide() {
            marker.setStyle({ opacity: 0 });
        }

        function attach(canvas) {
            if (!canvas) return;

            canvas.addEventListener("mousemove", function (e) {
                var chart = Chart.getChart(canvas);
                if (!chart) return;
                var rect = canvas.getBoundingClientRect();
                var x = e.clientX - rect.left;

                var meta = chart.getDatasetMeta(0);
                var elements = meta.data;
                if (!elements.length) return;

                // Ищем ближайшую по X точку
                var closest = 0;
                var minDist = Infinity;
                for (var i = 0; i < elements.length; i++) {
                    var dx = Math.abs(elements[i].x - x);
                    if (dx < minDist) { minDist = dx; closest = i; }
                }

                var label = chart.data.labels[closest];
                if (typeof label === "number") {
                    showAtDistance(label);
                }
            });

            canvas.addEventListener("mouseleave", hide);
            canvas.addEventListener("touchend", hide);
        }

        attach(document.getElementById("elev-chart"));
        attach(document.getElementById("speed-chart"));
    })();
})();

/* ============================================================
   AJAX-ЛАЙК (без перезагрузки страницы)
   ============================================================ */
(function () {
    "use strict";

    var csrf = ' . json_encode(csrf_token()) . ';
    var likeApi = ' . json_encode(url('api/like.php')) . ';

    document.querySelectorAll(".js-like-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var activityId = parseInt(btn.dataset.activityId, 10);
            if (!activityId) return;

            btn.disabled = true;

            fetch(likeApi, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": csrf
                },
                body: JSON.stringify({ activity_id: activityId })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) {
                    throw new Error((res && res.error) || "Ошибка");
                }
                var data = res.data || {};
                var liked = !!data.liked;
                var count = parseInt(data.count, 10) || 0;

                btn.classList.toggle("is-active", liked);
                btn.dataset.liked = liked ? "1" : "0";

                var counter = btn.querySelector(".js-like-count");
                if (counter) counter.textContent = count;

                // Показ/скрытие кнопки "Кто лайкнул"
                var likersBtn = document.querySelector(".js-likers-btn[data-activity-id=\"" + activityId + "\"]");
                if (likersBtn) {
                    likersBtn.style.display = count > 0 ? "" : "none";
                } else if (count > 0) {
                    var actions = btn.parentNode;
                    if (actions) {
                        var newBtn = document.createElement("button");
                        newBtn.type = "button";
                        newBtn.className = "action js-likers-btn";
                        newBtn.dataset.activityId = String(activityId);
                        newBtn.innerHTML = "<span class=\"action__icon\">👥</span><span>Кто лайкнул</span>";
                        var commentsLink = actions.querySelector("a[href=\"#comments\"]");
                        if (commentsLink) {
                            actions.insertBefore(newBtn, commentsLink);
                        } else {
                            actions.appendChild(newBtn);
                        }
                    }
                }
            })
            .catch(function (err) {
                console.error("Like error:", err);
                alert("Не удалось поставить лайк. Попробуйте ещё раз.");
            })
            .finally(function () {
                btn.disabled = false;
            });
        });
    });
})();
';

require __DIR__ . '/includes/header.php';

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================
function activity_icon(string $type): string
{
    return match ($type) {
        'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
        'walk' => '🚶', 'hike' => '🥾', default => '📦',
    };
}
function activity_label(string $type): string
{
    return match ($type) {
        'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
        'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
        default => 'Другое',
    };
}
function format_elapsed(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}
?>

<section class="activity-view">
    <header class="activity-view__head">
        <div class="activity-view__title-block">
            <span class="activity-type activity-type--lg">
                <?= e(activity_icon((string)$activity['type'])) ?>
                <?= e(activity_label((string)$activity['type'])) ?>
            </span>
            <h1 class="activity-view__name"><?= e($activity['title']) ?></h1>
            <p class="muted">
                <a href="<?= e(url('profile.php?u=' . urlencode((string)$activity['username']))) ?>">
                    <strong><?= e($activity['display_name']) ?></strong>
                </a>
                @<?= e($activity['username']) ?>
                · <?= e(time_ago((string)$activity['created_at'])) ?>
                <?php if ($visibility === 'private'): ?> · 🔒 приватная<?php endif; ?>
                <?php if ($visibility === 'followers'): ?> · 👥 для подписчиков<?php endif; ?>
            </p>
        </div>

        <?php if ($isOwner): ?>
            <div class="activity-view__owner-actions">
                <a href="<?= e(url('activity-edit.php?id=' . $activityId)) ?>"
                   class="btn btn--ghost btn--sm"
                   title="Редактировать название, описание, тип и видимость">
                    ✏️ Редактировать
                </a>
            </div>
        <?php endif; ?>
    </header>

    <div class="activity-toolbar">
        <a href="<?= e(url('activity.php?id=' . $activityId . '&export=gpx')) ?>"
           class="toolbar-btn" title="Скачать трек в GPX">⬇ GPX</a>

        <a href="<?= e(url('activity.php?id=' . $activityId . '&export=tcx')) ?>"
           class="toolbar-btn" title="Скачать трек в TCX">⬇ TCX</a>

        <button type="button" class="toolbar-btn js-copy-link" title="Скопировать ссылку">
            🔗 Ссылка
        </button>

        <a href="https://t.me/share/url?url=<?= rawurlencode($publicUrl) ?>&text=<?= $shareText ?>"
           target="_blank" rel="noopener"
           class="toolbar-btn" title="Поделиться в Telegram">📨 Telegram</a>

        <a href="https://vk.com/share.php?url=<?= rawurlencode($publicUrl) ?>&title=<?= rawurlencode((string)$activity['title']) ?>"
           target="_blank" rel="noopener"
           class="toolbar-btn" title="Поделиться во ВКонтакте">📢 ВК</a>

        <a href="https://twitter.com/intent/tweet?url=<?= rawurlencode($publicUrl) ?>&text=<?= $shareText ?>"
           target="_blank" rel="noopener"
           class="toolbar-btn" title="Поделиться в Twitter">🐦 Twitter</a>

        <?php if ($isOwner): ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_visibility">
                <button class="toolbar-btn" title="Изменить видимость">
                    <?= $visibility === 'public' ? '🔒 Скрыть' : '🌐 Опубликовать' ?>
                </button>
            </form>

            <form method="post" style="display:inline"
                  onsubmit="return confirm('Удалить активность? Это необратимо.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="toolbar-btn toolbar-btn--danger" title="Удалить">🗑 Удалить</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="activity-view__stats">
        <div class="stat">
            <span class="stat__value"><?= e(format_distance((float)$activity['distance_m'])) ?></span>
            <span class="stat__label">Дистанция</span>
        </div>
        <div class="stat">
            <span class="stat__value"><?= e(format_duration((int)$activity['duration_sec'])) ?></span>
            <span class="stat__label">Время</span>
        </div>
        <div class="stat">
            <span class="stat__value">
                <?php if ($activity['type'] === 'ride' && $activity['avg_speed_mps']): ?>
                    <?= number_format((float)$activity['avg_speed_mps'] * 3.6, 1, '.', '') ?> км/ч
                <?php else: ?>
                    <?= e(format_pace((float)$activity['distance_m'], (int)$activity['duration_sec'])) ?>
                <?php endif; ?>
            </span>
            <span class="stat__label"><?= $activity['type'] === 'ride' ? 'Средняя' : 'Темп' ?></span>
        </div>
        <div class="stat">
            <span class="stat__value"><?= $activity['elevation_gain_m'] ? (int)$activity['elevation_gain_m'] . ' м' : '—' ?></span>
            <span class="stat__label">Набор</span>
        </div>
    </div>

    <?php if ($track): ?>
        <div class="activity-view__map">
            <div id="activity-map" class="map"></div>
        </div>
    <?php else: ?>
        <div class="activity-view__no-map">
            <span class="muted">Эта активность без GPS-трека</span>
        </div>
    <?php endif; ?>

    <!-- ============ ГРАФИКИ: ПРОФИЛЬ ВЫСОТ И СКОРОСТЬ ============ -->
    <?php if ($elevData || $speedData): ?>
        <div class="activity-charts">

            <?php if ($elevData): ?>
                <div class="chart-card">
                    <div class="chart-card__head">
                        <h2 class="chart-card__title">⛰️ Профиль высот</h2>
                        <div class="chart-card__stats" id="elev-stats"></div>
                    </div>
                    <div class="chart-card__body">
                        <canvas id="elev-chart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($speedData): ?>
                <div class="chart-card">
                    <div class="chart-card__head">
                        <h2 class="chart-card__title">⚡ Скорость</h2>
                        <div class="chart-card__stats" id="speed-stats"></div>
                    </div>
                    <div class="chart-card__body">
                        <canvas id="speed-chart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    <?php endif; ?>

    <?php if (!empty($activity['description'])): ?>
        <div class="activity-view__desc">
            <?= nl2br(e((string)$activity['description'])) ?>
        </div>
    <?php endif; ?>

    <?php if ($gear): ?>
        <div class="activity-view__gear">
            <span class="activity-view__gear-icon">
                <?= $gear['type'] === 'bike' ? '🚴' : ($gear['type'] === 'shoes' ? '👟' : ($gear['type'] === 'skis' ? '⛷️' : '🎒')) ?>
            </span>
            <span>
                <span class="muted">Инвентарь:</span>
                <strong><?= e($gear['name']) ?></strong>
                <?php if (!empty($gear['brand'])): ?> · <?= e($gear['brand']) ?><?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <!-- ФОТОГРАФИИ -->
    <?php if ($activityPhotos): ?>
        <div class="activity-view__photos">
            <h2 class="section-title" style="text-align:left;font-size:20px">
                Фотографии (<?= count($activityPhotos) ?>)
            </h2>
            <div class="activity-gallery" id="activity-gallery">
                <?php foreach ($activityPhotos as $i => $ph): ?>
                    <a href="<?= e($ph['url']) ?>"
                       class="activity-gallery__item"
                       data-index="<?= $i ?>"
                       data-photo-url="<?= e($ph['url']) ?>">
                        <img src="<?= e($ph['url']) ?>" alt="" loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Лайтбокс -->
        <div class="lightbox" id="lightbox" hidden>
            <button class="lightbox__close" id="lightbox-close" aria-label="Закрыть">×</button>
            <button class="lightbox__prev" id="lightbox-prev" aria-label="Предыдущее">‹</button>
            <button class="lightbox__next" id="lightbox-next" aria-label="Следующее">›</button>
            <div class="lightbox__counter" id="lightbox-counter"></div>
            <div class="lightbox__img-wrap">
                <img src="" alt="" id="lightbox-img">
            </div>
        </div>
    <?php endif; ?>

    <?php if ($segments): ?>
        <div class="activity-view__segments">
            <h2 class="section-title" style="text-align:left;font-size:20px">Сегменты на этой активности</h2>
            <div class="segment-rows">
                <?php foreach ($segments as $s): ?>
                    <a class="segment-row" href="<?= e(url('segment.php?id=' . (int)$s['segment_id'])) ?>">
                        <div class="segment-row__body">
                            <div class="segment-row__name">
                                <strong><?= e($s['name']) ?></strong>
                                <?php if (!empty($s['is_auto'])): ?>
                                    <span class="segment-row__badge">авто</span>
                                <?php endif; ?>
                            </div>
                            <div class="segment-row__meta muted">
                                <?= e(format_distance((float)$s['distance_m'])) ?>
                                <?php if (!empty($s['match_quality'])): ?>
                                    · совпадение <?= (int)$s['match_quality'] ?>%
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="segment-row__time"><?= e(format_elapsed((int)$s['elapsed_time_sec'])) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="activity-view__actions">
        <?php if ($me): ?>
            <button type="button"
                    class="action js-like-btn <?= $likeInfo['liked'] ? 'is-active' : '' ?>"
                    data-activity-id="<?= (int)$activityId ?>"
                    data-liked="<?= $likeInfo['liked'] ? '1' : '0' ?>">
                <span class="action__icon">♥</span>
                <span class="js-like-count"><?= (int)$likeInfo['count'] ?></span>
            </button>
        <?php else: ?>
            <span class="action">
                <span class="action__icon">♥</span>
                <span class="js-like-count"><?= (int)$likeInfo['count'] ?></span>
            </span>
        <?php endif; ?>

        <?php if ((int)$likeInfo['count'] > 0): ?>
            <button type="button"
                    class="action js-likers-btn"
                    data-activity-id="<?= (int)$activityId ?>">
                <span class="action__icon">👥</span>
                <span>Кто лайкнул</span>
            </button>
        <?php endif; ?>

        <a class="action" href="#comments">
            <span class="action__icon">💬</span>
            <span><?= count($comments) ?></span>
        </a>

        <a class="action" href="<?= e(url('segment-create.php?activity_id=' . $activityId)) ?>">
            <span class="action__icon">⚡</span>
            <span>Создать сегмент</span>
        </a>
    </div>

    <h2 class="section-title" id="comments" style="text-align:left;font-size:20px">
        Комментарии (<?= count($comments) ?>)
    </h2>

    <div class="comments">
        <?php if (!$comments): ?>
            <div class="empty" style="padding:24px">
                <p class="muted">Пока нет комментариев.</p>
            </div>
        <?php else: ?>
            <?php foreach ($comments as $c): ?>
                <?php $canDelete = $me && ((int)$me['id'] === (int)$c['user_id'] || $isOwner); ?>
                <div class="comment">
                    <span class="avatar avatar--sm">
                        <?php if (!empty($c['avatar_url'])): ?>
                            <img src="<?= e($c['avatar_url']) ?>" alt="">
                        <?php else: ?>
                            <?= e(mb_substr((string)$c['display_name'], 0, 1)) ?>
                        <?php endif; ?>
                    </span>
                    <div class="comment__body">
                        <div class="comment__head">
                            <a href="<?= e(url('profile.php?u=' . urlencode((string)$c['username']))) ?>">
                                <strong><?= e($c['display_name']) ?></strong>
                            </a>
                            <span class="comment__time muted"><?= e(time_ago((string)$c['created_at'])) ?></span>

                            <?php if ($canDelete): ?>
                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('Удалить комментарий?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                                    <button class="comment__delete" title="Удалить">×</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div><?= nl2br(e($c['body'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($me): ?>
        <form method="post" class="comment-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="comment">
            <textarea name="body" rows="2" placeholder="Написать комментарий..." required maxlength="1000"></textarea>
            <button class="btn btn--primary">Отправить</button>
        </form>
    <?php else: ?>
        <div class="activity-view__login-cta">
            <a href="<?= e(url('login.php')) ?>" class="btn btn--primary">Войдите</a>,
            чтобы оставить комментарий.
        </div>
    <?php endif; ?>
</section>

<?php if ($activityPhotos): ?>
<script>
(function () {
    var photos = <?= json_encode(array_map(function ($p) { return $p['url']; }, $activityPhotos)) ?>;
    if (!photos.length) return;

    var lb = document.getElementById('lightbox');
    var lbImg = document.getElementById('lightbox-img');
    var lbCounter = document.getElementById('lightbox-counter');
    var btnClose = document.getElementById('lightbox-close');
    var btnPrev = document.getElementById('lightbox-prev');
    var btnNext = document.getElementById('lightbox-next');
    var current = 0;

    function show(index) {
        if (index < 0) index = photos.length - 1;
        if (index >= photos.length) index = 0;
        current = index;
        lbImg.src = photos[index];
        lbCounter.textContent = (index + 1) + ' / ' + photos.length;
    }

    function open(index) {
        show(index);
        lb.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function close() {
        lb.hidden = true;
        document.body.style.overflow = '';
        lbImg.src = '';
    }

    document.querySelectorAll('.activity-gallery__item').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            open(parseInt(el.dataset.index, 10) || 0);
        });
    });

    btnClose.addEventListener('click', close);

    btnPrev.addEventListener('click', function (e) {
        e.stopPropagation();
        show(current - 1);
    });

    btnNext.addEventListener('click', function (e) {
        e.stopPropagation();
        show(current + 1);
    });

    lb.addEventListener('click', function (e) {
        if (e.target === lb) close();
    });

    document.addEventListener('keydown', function (e) {
        if (lb.hidden) return;
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowLeft') show(current - 1);
        else if (e.key === 'ArrowRight') show(current + 1);
    });

    var touchStartX = 0;
    lb.addEventListener('touchstart', function (e) {
        touchStartX = e.touches[0].clientX;
    }, { passive: true });

    lb.addEventListener('touchend', function (e) {
        var diff = e.changedTouches[0].clientX - touchStartX;
        if (Math.abs(diff) < 50) return;
        if (diff > 0) show(current - 1);
        else show(current + 1);
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/models/Follow.php';
require_once __DIR__ . '/models/Route.php';
require_once __DIR__ . '/models/Segment.php';

auth_start();
$me = current_user();

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

if (!function_exists('profile_month_ru')) {
    function profile_month_ru(int $month): string
    {
        $months = [
            1 => 'январь', 2 => 'февраль', 3 => 'март',
            4 => 'апрель', 5 => 'май',     6 => 'июнь',
            7 => 'июль',   8 => 'август',  9 => 'сентябрь',
            10 => 'октябрь', 11 => 'ноябрь', 12 => 'декабрь',
        ];
        return $months[$month] ?? '';
    }
}

if (!function_exists('profile_month_ru_short')) {
    function profile_month_ru_short(int $month): string
    {
        $months = [
            1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр',
            5 => 'май', 6 => 'июн', 7 => 'июл', 8 => 'авг',
            9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек',
        ];
        return $months[$month] ?? '';
    }
}

if (!function_exists('profile_get_leader_segments')) {
    function profile_get_leader_segments(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $sql = "
            SELECT s.id, s.name, s.type, s.distance_m, s.elevation_gain_m, s.created_at,
                   u.username AS creator_username,
                   (
                       SELECT MIN(e1.elapsed_time_sec)
                       FROM segment_efforts e1
                       WHERE e1.segment_id = s.id AND e1.user_id = :me
                   ) AS my_best
            FROM segments s
            JOIN users u ON u.id = s.creator_id
            WHERE EXISTS (
                SELECT 1 FROM segment_efforts e2
                WHERE e2.segment_id = s.id AND e2.user_id = :me2
            )
            ORDER BY s.created_at DESC
            LIMIT 200
        ";

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':me', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':me2', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $candidates = $stmt->fetchAll();

        if (!$candidates) return [];

        $leaders = [];

        foreach ($candidates as $s) {
            $myBest = (int)$s['my_best'];
            if ($myBest <= 0) continue;

            $check = db()->prepare(
                'SELECT MIN(elapsed_time_sec)
                 FROM segment_efforts
                 WHERE segment_id = ? AND user_id != ?'
            );
            $check->execute([(int)$s['id'], $userId]);
            $othersBest = $check->fetchColumn();

            if ($othersBest !== null && (int)$othersBest < $myBest) {
                continue;
            }

            $secondBest = null;
            $gap = null;

            if ($othersBest !== null) {
                $secondBest = (int)$othersBest;
                $gap = $secondBest - $myBest;
            }

            $cnt = db()->prepare(
                'SELECT COUNT(DISTINCT user_id) FROM segment_efforts WHERE segment_id = ?'
            );
            $cnt->execute([(int)$s['id']]);
            $athletes = (int)$cnt->fetchColumn();

            $leaders[] = [
                'id'                 => (int)$s['id'],
                'name'               => (string)$s['name'],
                'type'               => (string)$s['type'],
                'distance_m'         => (float)$s['distance_m'],
                'elevation_gain_m'   => $s['elevation_gain_m'] !== null ? (float)$s['elevation_gain_m'] : null,
                'creator_username'   => (string)$s['creator_username'],
                'best_time'          => $myBest,
                'second_best'        => $secondBest,
                'gap_sec'            => $gap,
                'athletes'           => $athletes,
            ];

            if (count($leaders) >= $limit) break;
        }

        return $leaders;
    }
}

if (!function_exists('profile_segment_icon')) {
    function profile_segment_icon(string $type): string
    {
        return match ($type) {
            'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
            'walk' => '🚶', 'hike' => '🥾', default => '📦',
        };
    }
}

if (!function_exists('profile_segment_label')) {
    function profile_segment_label(string $type): string
    {
        return match ($type) {
            'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
            'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
            default => 'Другое',
        };
    }
}

if (!function_exists('profile_format_elapsed')) {
    function profile_format_elapsed(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }
}

if (!function_exists('profile_format_gap')) {
    function profile_format_gap(int $gapSec): string
    {
        if ($gapSec <= 0) return '—';
        if ($gapSec < 60) return '+' . $gapSec . ' с';
        $m = intdiv($gapSec, 60);
        $s = $gapSec % 60;
        return '+' . $m . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('activity_icon')) {
    function activity_icon(string $type): string
    {
        return match ($type) {
            'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
            'walk' => '🚶', 'hike' => '🥾', default => '📦',
        };
    }
}

if (!function_exists('activity_label')) {
    function activity_label(string $type): string
    {
        return match ($type) {
            'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
            'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
            default => 'Другое',
        };
    }
}

// ============================================================
// КАКОЙ ПРОФИЛЬ ОТКРЫВАЕМ
// ============================================================
$username = trim((string)($_GET['u'] ?? ''));
if ($username === '' && $me) $username = (string)$me['username'];
if ($username === '') redirect(url('index.php'));

$user  = null;
$error = null;

try {
    $user = User::findByUsername($username);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (!$user) {
    http_response_code(404);
    $pageTitle = 'Пользователь не найден';
    require __DIR__ . '/includes/header.php';
    echo '<section class="form-page"><div class="form-card">';
    echo '<h1 class="form-card__title">Пользователь не найден</h1>';
    echo '<p>Такого профиля не существует или он был удалён.</p>';
    echo '<p style="margin-top:16px"><a href="' . e(url('feed.php')) . '" class="btn btn--primary">В ленту</a></p>';
    echo '</div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$isMe = $me && (int)$me['id'] === (int)$user['id'];

// ---- Wahoo ----
$wahooConnected = false;
$wahooSyncedCount = 0;
if ($isMe) {
    try {
        $stmt = db()->prepare('SELECT wahoo_access_token FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$me['id']]);
        $wahooConnected = (bool)$stmt->fetchColumn();

        if ($wahooConnected) {
            $stmt = db()->prepare('SELECT COUNT(*) FROM wahoo_synced_workouts WHERE user_id = ?');
            $stmt->execute([(int)$me['id']]);
            $wahooSyncedCount = (int)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        $wahooConnected = false;
    }
}

// ---- Приватность ----
$isPublicProfile = (int)$user['is_public'] === 1;

$privateProfile = false;
$activities = [];
$leaderSegments = [];
$followers = [];
$following = [];
$followersCount = 0;
$followingCount = 0;
$totalActivities = 0;
$totalPages = 1;
$pageNum = 1;
$perPage = 20;

// ---- Фильтр по типу ----
$allowedTypes = ['', 'run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
$typeFilter = (string)($_GET['type'] ?? '');
if (!in_array($typeFilter, $allowedTypes, true)) $typeFilter = '';

if (!$isPublicProfile && !$isMe) {
    $privateProfile = true;
    $stats = ['activities' => 0, 'distance_m' => 0, 'followers' => 0, 'following' => 0];
} else {
    $stats = ['activities' => 0, 'distance_m' => 0, 'followers' => 0, 'following' => 0];
    try {
        $stats = User::stats((int)$user['id']);
    } catch (Throwable $e) { /* нули */ }

    try {
        $followers      = Follow::followers((int)$user['id'], 6, 0);
        $following      = Follow::following((int)$user['id'], 6, 0);
        $followersCount = Follow::followersCount((int)$user['id']);
        $followingCount = Follow::followingCount((int)$user['id']);
    } catch (Throwable $e) {
        $followers = $following = [];
        $followersCount = $followingCount = 0;
    }

    // ---- Пагинация активностей (с учётом фильтра) ----
    $pageNum = max(1, (int)($_GET['page'] ?? 1));

    try {
        if ($typeFilter !== '') {
            $stmt = db()->prepare('SELECT COUNT(*) FROM activities WHERE user_id = ? AND type = ?');
            $stmt->execute([(int)$user['id'], $typeFilter]);
            $totalActivities = (int)$stmt->fetchColumn();
        } else {
            $totalActivities = Activity::countByUser((int)$user['id']);
        }
    } catch (Throwable $e) {
        $totalActivities = 0;
    }

    $totalPages = max(1, (int)ceil($totalActivities / $perPage));
    if ($pageNum > $totalPages) $pageNum = $totalPages;
    $offset = ($pageNum - 1) * $perPage;

    try {
        if ($typeFilter !== '') {
            $stmt = db()->prepare(
                'SELECT a.*,
                    (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id) AS likes_count,
                    (SELECT COUNT(*) FROM activity_comments c WHERE c.activity_id = a.id) AS comments_count
                 FROM activities a
                 WHERE a.user_id = ? AND a.type = ?
                 ORDER BY COALESCE(a.started_at, a.created_at) DESC, a.id DESC
                 LIMIT ? OFFSET ?'
            );
            $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $typeFilter);
            $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
            $stmt->bindValue(4, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $activities = $stmt->fetchAll();
        } else {
            $activities = Activity::byUser((int)$user['id'], $perPage, $offset);
        }
    } catch (Throwable $e) { $activities = []; }

    try {
        $leaderSegments = profile_get_leader_segments((int)$user['id'], 20);
    } catch (Throwable $e) {
        $leaderSegments = [];
    }
}

// ---- Подписка ----
$isFollowedByMe = false;
try {
    if ($me && !$isMe) {
        $isFollowedByMe = Follow::isFollowing((int)$me['id'], (int)$user['id']);
    }
} catch (Throwable $e) { $isFollowedByMe = false; }

// ---- POST: подписка/отписка ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me && !$isMe) {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'follow') {
        try {
            Follow::follow((int)$me['id'], (int)$user['id']);
            flash('Вы подписались на @' . $user['username'], 'success');
        } catch (Throwable $e) {
            flash('Ошибка подписки: ' . $e->getMessage(), 'error');
        }
        redirect(url('profile.php?u=' . urlencode((string)$user['username'])));
    }

    if ($action === 'unfollow') {
        try {
            Follow::unfollow((int)$me['id'], (int)$user['id']);
            flash('Вы отписались от @' . $user['username'], 'success');
        } catch (Throwable $e) {
            flash('Ошибка отписки: ' . $e->getMessage(), 'error');
        }
        redirect(url('profile.php?u=' . urlencode((string)$user['username'])));
    }
}

// ============================================================
// АГРЕГИРОВАННАЯ СТАТИСТИКА (ВСЕ АКТИВНОСТИ, НЕ ТОЛЬКО СТРАНИЦА)
// ============================================================
$aggStats = [
    'total'      => 0,
    'total_dist' => 0.0,
    'total_dur'  => 0,
    'total_elev' => 0.0,
    'avg_speed'  => null,
];

$monthlyStats = [];    // ['2026-05' => ['dist' => ..., 'count' => ...], ...]
$typeStats    = [];    // ['run' => ['count' => ..., 'dist' => ...], ...]

if (!$privateProfile) {
    try {
        $stmt = db()->prepare(
            'SELECT type,
                    COUNT(*)                AS cnt,
                    COALESCE(SUM(distance_m), 0)       AS dist,
                    COALESCE(SUM(duration_sec), 0)     AS dur,
                    COALESCE(SUM(elevation_gain_m), 0) AS elev
             FROM activities
             WHERE user_id = ?
             GROUP BY type'
        );
        $stmt->execute([(int)$user['id']]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $type = (string)$row['type'];
            $aggStats['total']      += (int)$row['cnt'];
            $aggStats['total_dist'] += (float)$row['dist'];
            $aggStats['total_dur']  += (int)$row['dur'];
            $aggStats['total_elev'] += (float)$row['elev'];

            $typeStats[$type] = [
                'count' => (int)$row['cnt'],
                'dist'  => (float)$row['dist'],
            ];
        }

        if ($aggStats['total_dur'] > 0 && $aggStats['total_dist'] > 0) {
            $aggStats['avg_speed'] = $aggStats['total_dist'] / $aggStats['total_dur']; // м/с
        }
    } catch (Throwable $e) {
        // нули
    }

    // ---- Разбивка по месяцам (последние 12 месяцев) ----
    try {
        $stmt = db()->prepare(
            "SELECT DATE_FORMAT(COALESCE(started_at, created_at), '%Y-%m') AS ym,
                    COUNT(*)                AS cnt,
                    COALESCE(SUM(distance_m), 0) AS dist
             FROM activities
             WHERE user_id = ?
               AND COALESCE(started_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
             GROUP BY ym
             ORDER BY ym ASC"
        );
        $stmt->execute([(int)$user['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $monthlyStats[(string)$row['ym']] = [
                'count' => (int)$row['cnt'],
                'dist'  => (float)$row['dist'],
            ];
        }
    } catch (Throwable $e) {
        $monthlyStats = [];
    }
}

// ---- Упрощённые треки для карт в профиле ----
$tracksByActivity = [];
if ($activities) {
    foreach ($activities as $a) {
        if (empty($a['track_json'])) {
            $tracksByActivity[(int)$a['id']] = [];
            continue;
        }
        $decoded = json_decode((string)$a['track_json'], true);
        if (!is_array($decoded) || count($decoded) < 2) {
            $tracksByActivity[(int)$a['id']] = [];
            continue;
        }

        $points = [];
        foreach ($decoded as $p) {
            if (isset($p['lat'], $p['lng'])) {
                $points[] = [
                    'lat' => round((float)$p['lat'], 5),
                    'lng' => round((float)$p['lng'], 5),
                ];
            }
        }

        if (count($points) > 200) {
            $step = (int)ceil(count($points) / 200);
            $simplified = [];
            for ($i = 0; $i < count($points); $i += $step) {
                $simplified[] = $points[$i];
            }
            if (end($simplified) !== end($points)) {
                $simplified[] = end($points);
            }
            $points = $simplified;
        }

        $tracksByActivity[(int)$a['id']] = $points;
    }
}

// ---- Фото активностей ----
$photosByActivity = [];
if ($activities) {
    try {
        $ids = array_column($activities, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT * FROM activity_photos
             WHERE activity_id IN ($ph)
             ORDER BY activity_id ASC, order_index ASC, id ASC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $photosByActivity[(int)$row['activity_id']][] = $row;
        }
    } catch (Throwable $e) {
        $photosByActivity = [];
    }
}

// ---- Клубы пользователя ----
$userClubs = [];
try {
    $s = db()->prepare(
        'SELECT c.id, c.name, c.slug, c.avatar_url, c.member_count,
                m.role, m.joined_at
           FROM club_members m
           JOIN clubs c ON c.id = m.club_id
          WHERE m.user_id = ? AND m.status = "active" AND c.is_banned = 0
       ORDER BY FIELD(m.role, "owner","admin","moderator","member"), m.joined_at ASC
          LIMIT 30'
    );
    $s->execute([(int)$user['id']]);
    $userClubs = $s->fetchAll();
} catch (Throwable $e) {
    $userClubs = [];
}

// ---- Данные для графика по месяцам ----
$chartLabels = [];
$chartDist   = [];
$chartCount  = [];

$now = new DateTime('first day of this month');
for ($i = 11; $i >= 0; $i--) {
    $d = clone $now;
    $d->modify("-{$i} month");
    $ym = $d->format('Y-m');
    $chartLabels[] = profile_month_ru_short((int)$d->format('n')) . ' ' . $d->format('y');
    $chartDist[]   = isset($monthlyStats[$ym]) ? round($monthlyStats[$ym]['dist'] / 1000, 1) : 0;
    $chartCount[]  = isset($monthlyStats[$ym]) ? $monthlyStats[$ym]['count'] : 0;
}

$pageTitle = $user['display_name'];

$extraCss = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
];
$extraJs  = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
];

$chartLabelsJson = json_encode($chartLabels, JSON_UNESCAPED_UNICODE);
$chartDistJson   = json_encode($chartDist);
$chartCountJson  = json_encode($chartCount);

$inlineJs = '
window.__PROFILE_TRACKS__ = ' . json_encode($tracksByActivity, JSON_UNESCAPED_UNICODE) . ';
window.__PROFILE_CHART__ = {
    labels: ' . $chartLabelsJson . ',
    dist:   ' . $chartDistJson . ',
    count:  ' . $chartCountJson . '
};

(function () {
    if (typeof L === "undefined") return;
    var tracks = window.__PROFILE_TRACKS__ || {};

    function initMap(el) {
        if (el.dataset.initialized === "1") return;
        var id = el.dataset.activityId;
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
        var latlngs = pts.map(function(p) { return [p.lat, p.lng]; });
        var line = L.polyline(latlngs, { color: "#ff5a1f", weight: 4 }).addTo(map);
        L.circleMarker(latlngs[0], {
            radius: 4, color: "#0a7a3a", fillColor: "#0a7a3a", fillOpacity: 1
        }).addTo(map);
        L.circleMarker(latlngs[latlngs.length - 1], {
            radius: 4, color: "#b3261e", fillColor: "#b3261e", fillOpacity: 1
        }).addTo(map);
        map.fitBounds(line.getBounds(), { padding: [10, 10] });
    }

    var maps = document.querySelectorAll(".profile-map");
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

/* ---- ГРАФИКИ ПРОФИЛЯ ---- */
(function () {
    if (typeof Chart === "undefined") return;
    var data = window.__PROFILE_CHART__ || {};
    if (!data.labels || !data.labels.length) return;

    Chart.defaults.font.family = "\'Inter\', system-ui, sans-serif";
    Chart.defaults.font.size   = 11;
    Chart.defaults.color       = "#5b6473";

    var distCanvas = document.getElementById("profile-chart-dist");
    if (distCanvas) {
        var ctx = distCanvas.getContext("2d");
        var grad = ctx.createLinearGradient(0, 0, 0, 260);
        grad.addColorStop(0, "rgba(255,90,31,.35)");
        grad.addColorStop(1, "rgba(255,90,31,.02)");
        new Chart(ctx, {
            type: "bar",
            data: {
                labels: data.labels,
                datasets: [{
                    label: "Дистанция, км",
                    data: data.dist,
                    backgroundColor: grad,
                    borderColor: "#ff5a1f",
                    borderWidth: 1.5,
                    borderRadius: 6,
                    maxBarThickness: 32,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: "rgba(15,20,32,.92)",
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: function (item) {
                                return item.parsed.y.toFixed(1) + " км";
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: false, font: { size: 10 } } },
                    y: { beginAtZero: true, grid: { color: "rgba(15,20,32,.06)" }, ticks: { callback: function (v) { return v + " км"; }, maxTicksLimit: 6 } }
                }
            }
        });
    }

    var countCanvas = document.getElementById("profile-chart-count");
    if (countCanvas) {
        var ctx2 = countCanvas.getContext("2d");
        var grad2 = ctx2.createLinearGradient(0, 0, 0, 260);
        grad2.addColorStop(0, "rgba(31,95,196,.35)");
        grad2.addColorStop(1, "rgba(31,95,196,.02)");
        new Chart(ctx2, {
            type: "line",
            data: {
                labels: data.labels,
                datasets: [{
                    label: "Активностей",
                    data: data.count,
                    borderColor: "#1f5fc4",
                    backgroundColor: grad2,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: "#1f5fc4",
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: "rgba(15,20,32,.92)",
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: function (item) {
                                return item.parsed.y + " шт";
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: false, font: { size: 10 } } },
                    y: { beginAtZero: true, grid: { color: "rgba(15,20,32,.06)" }, ticks: { precision: 0, maxTicksLimit: 6 } }
                }
            }
        });
    }
})();

/* ---- КАРУСЕЛИ ФОТО В ПРОФИЛЕ ---- */
(function () {
    "use strict";

    function initGallery(el) {
        if (el.dataset.galleryInit === "1") return;
        el.dataset.galleryInit = "1";

        var track = el.querySelector(".feed-gallery__track");
        var prev = el.querySelector(".feed-gallery__nav--prev");
        var next = el.querySelector(".feed-gallery__nav--next");
        var counter = el.querySelector(".feed-gallery__counter");
        var items = track ? track.querySelectorAll(".feed-gallery__item") : [];
        if (!track || !items.length) return;

        function step() {
            var first = items[0];
            var rect = first.getBoundingClientRect();
            var style = window.getComputedStyle(track);
            var gap = parseInt(style.columnGap || style.gap || "0", 10) || 0;
            return rect.width + gap;
        }
        function update() {
            if (!counter) return;
            var s = step();
            var idx = Math.round(track.scrollLeft / s);
            if (idx < 0) idx = 0;
            if (idx > items.length - 1) idx = items.length - 1;
            counter.textContent = (idx + 1) + " / " + items.length;
            if (prev) prev.disabled = track.scrollLeft <= 1;
            if (next) next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
        }
        if (prev) prev.addEventListener("click", function (e) { e.preventDefault(); e.stopPropagation(); track.scrollBy({ left: -step(), behavior: "smooth" }); });
        if (next) next.addEventListener("click", function (e) { e.preventDefault(); e.stopPropagation(); track.scrollBy({ left: step(), behavior: "smooth" }); });
        track.addEventListener("scroll", function () {
            if (track._scrollRAF) cancelAnimationFrame(track._scrollRAF);
            track._scrollRAF = requestAnimationFrame(update);
        }, { passive: true });
        update();
    }

    document.querySelectorAll(".feed-gallery[data-gallery]").forEach(initGallery);
})();

/* ---- ЛАЙТБОКС ---- */
(function () {
    "use strict";
    if (!document.getElementById("feed-lightbox")) {
        var lb = document.createElement("div");
        lb.className = "lightbox";
        lb.id = "feed-lightbox";
        lb.hidden = true;
        lb.innerHTML =
            "<button class=\"lightbox__close\" id=\"feed-lb-close\" aria-label=\"Закрыть\">×</button>" +
            "<button class=\"lightbox__prev\" id=\"feed-lb-prev\" aria-label=\"Предыдущее\">‹</button>" +
            "<button class=\"lightbox__next\" id=\"feed-lb-next\" aria-label=\"Следующее\">›</button>" +
            "<div class=\"lightbox__counter\" id=\"feed-lb-counter\"></div>" +
            "<div class=\"lightbox__img-wrap\"><img src=\"\" alt=\"\" id=\"feed-lb-img\"></div>";
        document.body.appendChild(lb);
    }

    var lbImg = document.getElementById("feed-lb-img");
    var lbCounter = document.getElementById("feed-lb-counter");
    var btnClose = document.getElementById("feed-lb-close");
    var btnPrev = document.getElementById("feed-lb-prev");
    var btnNext = document.getElementById("feed-lb-next");
    var currentList = [];
    var currentIndex = 0;

    function show(index) {
        if (!currentList.length) return;
        if (index < 0) index = currentList.length - 1;
        if (index >= currentList.length) index = 0;
        currentIndex = index;
        lbImg.src = currentList[index];
        lbCounter.textContent = (index + 1) + " / " + currentList.length;
    }
    function open(list, index) {
        currentList = list;
        show(index);
        document.getElementById("feed-lightbox").hidden = false;
        document.body.style.overflow = "hidden";
    }
    function close() {
        document.getElementById("feed-lightbox").hidden = true;
        document.body.style.overflow = "";
        lbImg.src = "";
        currentList = [];
    }

    document.addEventListener("click", function (e) {
        if (e.target.closest(".feed-gallery__nav")) return;
        var item = e.target.closest(".feed-gallery__item");
        if (!item) return;
        e.preventDefault();
        var gallery = item.closest(".feed-gallery");
        var items = gallery ? gallery.querySelectorAll(".feed-gallery__item") : [item];
        var list = [];
        var index = 0;
        items.forEach(function (el, i) {
            list.push(el.dataset.photoUrl || el.getAttribute("href"));
            if (el === item) index = i;
        });
        open(list, index);
    });

    btnClose.addEventListener("click", close);
    btnPrev.addEventListener("click", function (e) { e.stopPropagation(); show(currentIndex - 1); });
    btnNext.addEventListener("click", function (e) { e.stopPropagation(); show(currentIndex + 1); });
    document.getElementById("feed-lightbox").addEventListener("click", function (e) { if (e.target === this) close(); });

    document.addEventListener("keydown", function (e) {
        if (document.getElementById("feed-lightbox").hidden) return;
        if (e.key === "Escape") close();
        else if (e.key === "ArrowLeft") show(currentIndex - 1);
        else if (e.key === "ArrowRight") show(currentIndex + 1);
    });

    var touchStartX = 0;
    document.getElementById("feed-lightbox").addEventListener("touchstart", function (e) {
        touchStartX = e.touches[0].clientX;
    }, { passive: true });
    document.getElementById("feed-lightbox").addEventListener("touchend", function (e) {
        var diff = e.changedTouches[0].clientX - touchStartX;
        if (Math.abs(diff) < 50) return;
        if (diff > 0) show(currentIndex - 1);
        else show(currentIndex + 1);
    });
})();

/* ---- МОДАЛКА ЛАЙКНУВШИХ ---- */
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
            if (!res || !res.ok) throw new Error((res && res.error) || "Ошибка");
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
        if (!likers.length) return "<div class=\"likers-modal__empty\">Пока никто не лайкнул</div>";
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
';

require __DIR__ . '/includes/header.php';
?>

<section class="profile-page">
    <!-- Шапка профиля -->
    <header class="profile-hero">
        <div class="profile-hero__cover"></div>
        <div class="profile-hero__body">
            <div class="profile-hero__avatar">
                <?php if (!empty($user['avatar_url'])): ?>
                    <img src="<?= e($user['avatar_url']) ?>" alt="">
                <?php else: ?>
                    <?= e(mb_substr((string)$user['display_name'], 0, 1)) ?>
                <?php endif; ?>
            </div>

            <div class="profile-hero__top">
                <div>
                    <div class="profile-hero__username">@<?= e($user['username']) ?></div>
                    <h1 class="profile-hero__name"><?= e($user['display_name']) ?></h1>
                    <div class="profile-hero__meta">
                        <?php if (!empty($user['city']) || !empty($user['country'])): ?>
                            <span>📍 <?= e(trim(($user['city'] ?? '') . ', ' . ($user['country'] ?? ''), ', ')) ?></span>
                        <?php endif; ?>
                        <span>📅 С нами с <?= e(profile_month_ru((int)date('n', strtotime((string)$user['created_at']))) . ' ' . date('Y', strtotime((string)$user['created_at']))) ?></span>
                        <?php if (!$isPublicProfile): ?>
                            <span>🔒 Приватный профиль</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="profile-hero__actions">
                    <?php if ($isMe): ?>
                        <a href="<?= e(url('profile-edit.php')) ?>" class="btn btn--ghost btn--sm">⚙️ Настройки</a>
                        <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--primary btn--sm">+ Загрузить</a>
                        <?php if ($wahooConnected): ?>
                            <a href="<?= e(url('wahoo-sync.php')) ?>"
                               class="btn btn--ghost btn--sm"
                               title="Wahoo подключён — открыть синхронизацию">
                                🚴 Wahoo ✓
                            </a>
                        <?php else: ?>
                            <a href="<?= e(url('wahoo-connect.php')) ?>"
                               class="btn btn--ghost btn--sm"
                               title="Подключить аккаунт Wahoo">
                                🚴 Подключить Wahoo
                            </a>
                        <?php endif; ?>
                    <?php elseif ($me): ?>
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $isFollowedByMe ? 'unfollow' : 'follow' ?>">
                            <button class="btn <?= $isFollowedByMe ? 'btn--ghost' : 'btn--primary' ?> btn--sm">
                                <?= $isFollowedByMe ? 'Отписаться' : '+ Подписаться' ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <a href="<?= e(url('register.php')) ?>" class="btn btn--primary btn--sm">Подписаться</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($user['bio'])): ?>
                <p class="profile-hero__bio"><?= nl2br(e((string)$user['bio'])) ?></p>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($privateProfile): ?>
        <div class="empty">
            <p>Это приватный профиль.</p>
            <p class="muted" style="margin-top:8px">
                Подпишитесь, чтобы видеть активности этого спортсмена.
            </p>
        </div>
    <?php else: ?>

        <!-- ============ ОДИН БЛОК СТАТИСТИКИ ============ -->
        <section class="profile-section">
            <div class="profile-stats">

                <a href="<?= e(url('profile.php?u=' . urlencode((string)$user['username']))) ?>"
                   class="profile-stat profile-stat--accent">
                    <div class="profile-stat__value"><?= (int)$stats['activities'] ?></div>
                    <div class="profile-stat__label">Активностей</div>
                </a>

                <div class="profile-stat">
                    <div class="profile-stat__value"><?= e(format_distance((float)$stats['distance_m'])) ?></div>
                    <div class="profile-stat__label">Всего дистанции</div>
                </div>

                <div class="profile-stat">
                    <div class="profile-stat__value">
                        <?= $aggStats['total_dur'] > 0 ? e(format_duration((int)$aggStats['total_dur'])) : '—' ?>
                    </div>
                    <div class="profile-stat__label">Всего времени</div>
                </div>

                <div class="profile-stat">
                    <div class="profile-stat__value">
                        <?= $aggStats['total_elev'] > 0
                            ? number_format((float)$aggStats['total_elev'], 0, '.', ' ') . ' м'
                            : '—' ?>
                    </div>
                    <div class="profile-stat__label">Набор высоты</div>
                </div>

                <div class="profile-stat">
                    <div class="profile-stat__value">
                        <?php if ($aggStats['avg_speed'] !== null): ?>
                            <?= number_format((float)$aggStats['avg_speed'] * 3.6, 1, '.', '') ?> км/ч
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </div>
                    <div class="profile-stat__label">Средняя скорость</div>
                </div>

                <a href="<?= e(url('profile-followers.php?u=' . urlencode((string)$user['username']))) ?>"
                   class="profile-stat profile-stat--link">
                    <div class="profile-stat__value"><?= (int)$stats['followers'] ?></div>
                    <div class="profile-stat__label">Подписчиков</div>
                </a>

                <a href="<?= e(url('profile-following.php?u=' . urlencode((string)$user['username']))) ?>"
                   class="profile-stat profile-stat--link">
                    <div class="profile-stat__value"><?= (int)$stats['following'] ?></div>
                    <div class="profile-stat__label">Подписок</div>
                </a>

            </div>
        </section>

        <!-- ============ РАЗБИВКА ПО ТИПАМ ============ -->
        <?php if ($typeStats): ?>
            <section class="profile-section">
                <div class="profile-section__head">
                    <h2 class="profile-section__title">📊 По типам</h2>
                </div>

                <div class="profile-types">
                    <?php
                        $typeColors = [
                            'run'   => '#ff5a1f',
                            'ride'  => '#1f5fc4',
                            'swim'  => '#0ea5b7',
                            'ski'   => '#7c3aed',
                            'walk'  => '#0a7a3a',
                            'hike'  => '#a36a00',
                            'other' => '#5b6473',
                        ];
                        $totalDist = 0;
                        foreach ($typeStats as $ts) $totalDist += $ts['dist'];
                    ?>
                    <?php foreach ($typeStats as $type => $ts): ?>
                        <?php
                            $color = $typeColors[$type] ?? '#5b6473';
                            $icon  = activity_icon((string)$type);
                            $label = activity_label((string)$type);
                            $share = $totalDist > 0 ? round($ts['dist'] / $totalDist * 100) : 0;
                        ?>
                        <div class="profile-type-row">
                            <div class="profile-type-row__head">
                                <span class="profile-type-row__icon"><?= e($icon) ?></span>
                                <span class="profile-type-row__name"><?= e($label) ?></span>
                                <span class="profile-type-row__count">
                                    <?= (int)$ts['count'] ?> · <?= e(format_distance($ts['dist'])) ?>
                                    · <?= $share ?>%
                                </span>
                            </div>
                            <div class="profile-type-row__bar">
                                <div class="profile-type-row__fill"
                                     style="width: <?= $share ?>%; background: <?= e($color) ?>;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- ============ ГРАФИКИ ============ -->
        <?php if ($aggStats['total'] > 0): ?>
            <section class="profile-section">
                <div class="profile-section__head">
                    <h2 class="profile-section__title">📈 По месяцам</h2>
                    <span class="muted" style="font-size:13px">последние 12 месяцев</span>
                </div>

                <div class="profile-charts">
                    <div class="chart-card">
                        <div class="chart-card__head">
                            <h3 class="chart-card__title">Дистанция по месяцам</h3>
                        </div>
                        <div class="chart-card__body">
                            <canvas id="profile-chart-dist"></canvas>
                        </div>
                    </div>

                    <div class="chart-card">
                        <div class="chart-card__head">
                            <h3 class="chart-card__title">Активности по месяцам</h3>
                        </div>
                        <div class="chart-card__body">
                            <canvas id="profile-chart-count"></canvas>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- ============ КЛУБЫ ============ -->
        <?php if ($userClubs): ?>
            <section class="profile-section">
                <div class="profile-section__head">
                    <h2 class="profile-section__title">
                        🏁 Клубы
                        <span class="profile-section__count"><?= count($userClubs) ?></span>
                    </h2>
                </div>
                <div class="profile-clubs">
                    <?php foreach ($userClubs as $uc): ?>
                        <a class="profile-club-chip"
                           href="<?= e(url('club.php?slug=' . urlencode((string)$uc['slug']))) ?>">
                            <span class="profile-club-chip__avatar">
                                <?php if (!empty($uc['avatar_url'])): ?>
                                    <img src="<?= e($uc['avatar_url']) ?>" alt="">
                                <?php else: ?>
                                    <?= e(mb_substr((string)$uc['name'], 0, 1)) ?>
                                <?php endif; ?>
                            </span>
                            <span class="profile-club-chip__info">
                                <span class="profile-club-chip__name"><?= e($uc['name']) ?></span>
                                <span class="profile-club-chip__meta muted">
                                    <?= (int)$uc['member_count'] ?> участников
                                    <?php if ($uc['role'] === 'owner'): ?> · владелец<?php endif; ?>
                                    <?php if ($uc['role'] === 'admin'): ?> · админ<?php endif; ?>
                                    <?php if ($uc['role'] === 'moderator'): ?> · модератор<?php endif; ?>
                                </span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- ============ ПОДПИСЧИКИ И ПОДПИСКИ ============ -->
        <?php if ($followers || $following): ?>
            <div class="profile-connections">
                <?php if ($followers): ?>
                    <div class="profile-connections__block">
                        <div class="profile-connections__head">
                            <h3 class="profile-connections__title">
                                Подписчики
                                <span class="profile-connections__count"><?= (int)$followersCount ?></span>
                            </h3>
                            <?php if ($followersCount > 6): ?>
                                <a href="<?= e(url('profile-followers.php?u=' . urlencode((string)$user['username']))) ?>"
                                   class="profile-connections__more">Показать всех →</a>
                            <?php endif; ?>
                        </div>
                        <div class="profile-connections__list">
                            <?php foreach ($followers as $f): ?>
                                <a href="<?= e(url('profile.php?u=' . urlencode((string)$f['username']))) ?>"
                                   class="connection-chip">
                                    <span class="avatar avatar--sm">
                                        <?php if (!empty($f['avatar_url'])): ?>
                                            <img src="<?= e($f['avatar_url']) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(mb_substr((string)$f['display_name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="connection-chip__name"><?= e($f['display_name']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($following): ?>
                    <div class="profile-connections__block">
                        <div class="profile-connections__head">
                            <h3 class="profile-connections__title">
                                Подписки
                                <span class="profile-connections__count"><?= (int)$followingCount ?></span>
                            </h3>
                            <?php if ($followingCount > 6): ?>
                                <a href="<?= e(url('profile-following.php?u=' . urlencode((string)$user['username']))) ?>"
                                   class="profile-connections__more">Показать всех →</a>
                            <?php endif; ?>
                        </div>
                        <div class="profile-connections__list">
                            <?php foreach ($following as $f): ?>
                                <a href="<?= e(url('profile.php?u=' . urlencode((string)$f['username']))) ?>"
                                   class="connection-chip">
                                    <span class="avatar avatar--sm">
                                        <?php if (!empty($f['avatar_url'])): ?>
                                            <img src="<?= e($f['avatar_url']) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(mb_substr((string)$f['display_name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="connection-chip__name"><?= e($f['display_name']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ============ СЕГМЕНТЫ, ГДЕ ЛИДЕР ============ -->
        <?php if ($leaderSegments): ?>
            <section class="profile-section profile-section--leaders">
                <div class="profile-section__head">
                    <h2 class="profile-section__title">
                        🏆 <?= $isMe ? 'Сегменты, где вы лидер' : 'Сегменты, где лидер' ?>
                    </h2>
                    <span class="profile-section__count"><?= count($leaderSegments) ?></span>
                </div>

                <div class="leader-segments">
                    <?php foreach ($leaderSegments as $s): ?>
                        <a class="leader-segment" href="<?= e(url('segment.php?id=' . (int)$s['id'])) ?>">
                            <div class="leader-segment__rank">🥇</div>

                            <div class="leader-segment__body">
                                <div class="leader-segment__head">
                                    <span class="leader-segment__type">
                                        <?= e(profile_segment_icon($s['type'])) ?>
                                        <?= e(profile_segment_label($s['type'])) ?>
                                    </span>
                                    <?php if ($s['athletes'] > 1): ?>
                                        <span class="muted">
                                            👥 <?= (int)$s['athletes'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="leader-segment__name"><?= e($s['name']) ?></div>

                                <div class="leader-segment__meta">
                                    <span><?= e(format_distance((float)$s['distance_m'])) ?></span>
                                    <?php if (!empty($s['elevation_gain_m'])): ?>
                                        <span>↑<?= (int)$s['elevation_gain_m'] ?> м</span>
                                    <?php endif; ?>
                                    <span>от @<?= e($s['creator_username']) ?></span>
                                </div>
                            </div>

                            <div class="leader-segment__stats">
                                <div class="leader-segment__time">
                                    <?= e(profile_format_elapsed((int)$s['best_time'])) ?>
                                </div>
                                <?php if ($s['gap_sec'] !== null && $s['gap_sec'] > 0): ?>
                                    <div class="leader-segment__gap" title="Отрыв от второго места">
                                        отрыв <?= e(profile_format_gap((int)$s['gap_sec'])) ?>
                                    </div>
                                <?php elseif ($s['second_best'] === null): ?>
                                    <div class="leader-segment__gap leader-segment__gap--solo">
                                        единственный
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php elseif ($isMe): ?>
            <section class="profile-section">
                <div class="empty">
                    <p>Пока нет сегментов, где вы лидер.</p>
                    <p class="muted" style="margin-top:8px">
                        Проходите сегменты быстрее всех — и они появятся здесь.
                    </p>
                    <a href="<?= e(url('segments.php')) ?>" class="btn btn--primary">Найти сегменты</a>
                </div>
            </section>
        <?php endif; ?>

        <!-- ============ АКТИВНОСТИ ============ -->
        <h2 class="profile-section-title">
            Активности
            <?php if ($totalActivities > 0): ?>
                <span class="muted" style="font-weight:500;font-size:14px">
                    (<?= (int)$totalActivities ?><?= $typeFilter !== '' ? ' · ' . e(activity_label($typeFilter)) : '' ?><?= $totalPages > 1 ? ', стр. ' . (int)$pageNum . ' из ' . (int)$totalPages : '' ?>)
                </span>
            <?php endif; ?>
        </h2>

        <!-- Фильтры -->
        <div class="profile-filters">
            <?php
                $typeFilters = [
                    ''      => 'Все',
                    'run'   => '🏃 Бег',
                    'ride'  => '🚴 Вело',
                    'swim'  => '🏊 Плавание',
                    'ski'   => '⛷️ Лыжи',
                    'walk'  => '🚶 Ходьба',
                    'hike'  => '🥾 Хайкинг',
                    'other' => '📦 Другое',
                ];
            ?>
            <?php foreach ($typeFilters as $key => $label): ?>
                <?php
                    $qs = ['u' => (string)$user['username']];
                    if ($key !== '') $qs['type'] = $key;
                ?>
                <a href="?<?= e(http_build_query($qs)) ?>"
                   class="profile-filter <?= $typeFilter === $key ? 'is-active' : '' ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$activities): ?>
            <div class="empty">
                <p>Пока нет активностей<?= $typeFilter !== '' ? ' этого типа' : '' ?>.</p>
                <?php if ($isMe): ?>
                    <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--primary">Загрузить первую</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="profile-activities">
                <?php foreach ($activities as $a): ?>
                    <?php
                        $hasTrack = !empty($tracksByActivity[(int)$a['id']]);
                        $aid = (int)$a['id'];
                        $actPhotos = $photosByActivity[$aid] ?? [];
                        $likesCount = (int)($a['likes_count'] ?? 0);
                    ?>
                    <article class="profile-activity-card">
                        <div class="profile-activity-card__head">
                            <span class="activity-type">
                                <?= e(activity_icon((string)$a['type'])) ?> <?= e(activity_label((string)$a['type'])) ?>
                            </span>
                            <span class="muted">
                                <?php
                                    $activityWhen = $a['started_at'] ?? $a['created_at'] ?? null;
                                    if ($activityWhen) {
                                        $ts = strtotime((string)$activityWhen);
                                        echo '📅 ' . e(date('d.m.Y H:i', $ts));
                                    }
                                ?>
                            </span>
                        </div>

                        <a href="<?= e(url('activity.php?id=' . $aid)) ?>"
                           class="profile-activity-card__title-link">
                            <h3 class="profile-activity-card__title"><?= e($a['title']) ?></h3>
                        </a>

                        <?php if (!empty($a['description'])): ?>
                            <div class="profile-activity-card__description">
                                <?= nl2br(e((string)$a['description'])) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($hasTrack): ?>
                            <a href="<?= e(url('activity.php?id=' . $aid)) ?>"
                               class="profile-activity-card__map-link">
                                <div class="profile-map"
                                     data-activity-id="<?= $aid ?>"
                                     data-initialized="0"></div>
                            </a>
                        <?php else: ?>
                            <div class="profile-activity-card__no-map">
                                <span class="muted">Нет GPS-трека</span>
                            </div>
                        <?php endif; ?>

                        <?php if ($actPhotos): ?>
                            <div class="feed-gallery" data-gallery>
                                <div class="feed-gallery__track">
                                    <?php foreach ($actPhotos as $ph): ?>
                                        <a href="<?= e($ph['url']) ?>"
                                           class="feed-gallery__item"
                                           data-photo-url="<?= e($ph['url']) ?>">
                                            <img src="<?= e($ph['url']) ?>" alt="" loading="lazy" draggable="false">
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($actPhotos) > 1): ?>
                                    <button type="button" class="feed-gallery__nav feed-gallery__nav--prev" aria-label="Предыдущее">‹</button>
                                    <button type="button" class="feed-gallery__nav feed-gallery__nav--next" aria-label="Следующее">›</button>
                                    <div class="feed-gallery__counter">1 / <?= count($actPhotos) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="profile-activity-card__stats">
                            <div class="stat">
                                <span class="stat__value"><?= e(format_distance((float)$a['distance_m'])) ?></span>
                                <span class="stat__label">Дистанция</span>
                            </div>
                            <div class="stat">
                                <span class="stat__value"><?= e(format_duration((int)$a['duration_sec'])) ?></span>
                                <span class="stat__label">Время</span>
                            </div>
                            <div class="stat">
                                <span class="stat__value">
                                    <?php if ($a['type'] === 'ride' && $a['avg_speed_mps']): ?>
                                        <?= number_format((float)$a['avg_speed_mps'] * 3.6, 1, '.', '') ?> км/ч
                                    <?php else: ?>
                                        <?= e(format_pace((float)$a['distance_m'], (int)$a['duration_sec'])) ?>
                                    <?php endif; ?>
                                </span>
                                <span class="stat__label"><?= $a['type'] === 'ride' ? 'Средняя' : 'Темп' ?></span>
                            </div>
                            <div class="stat">
                                <span class="stat__value">
                                    <?= $a['elevation_gain_m'] ? (int)$a['elevation_gain_m'] . ' м' : '—' ?>
                                </span>
                                <span class="stat__label">Набор</span>
                            </div>
                        </div>

                        <div class="profile-activity-card__actions">
                            <?php if ($likesCount > 0): ?>
                                <button type="button"
                                        class="action js-likers-btn"
                                        data-activity-id="<?= $aid ?>"
                                        data-likers-count="<?= $likesCount ?>">
                                    <span class="action__icon">♥</span>
                                    <span><?= $likesCount ?></span>
                                </button>
                            <?php else: ?>
                                <span class="action">
                                    <span class="action__icon">♥</span>
                                    <span>0</span>
                                </span>
                            <?php endif; ?>
                            <a class="action" href="<?= e(url('activity.php?id=' . $aid . '#comments')) ?>">
                                <span class="action__icon">💬</span>
                                <span><?= (int)($a['comments_count'] ?? 0) ?></span>
                            </a>
                            <a class="action" href="<?= e(url('activity.php?id=' . $aid)) ?>">
                                <span class="action__icon">🔗</span>
                                <span>Открыть</span>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Навигация по страницам активностей">
                    <?php
                        $baseQs = ['u' => (string)$user['username']];
                        if ($typeFilter !== '') $baseQs['type'] = $typeFilter;

                        $window = 2;
                        $start = max(1, $pageNum - $window);
                        $end   = min($totalPages, $pageNum + $window);

                        if ($end - $start < $window * 2) {
                            if ($start === 1) {
                                $end = min($totalPages, $start + $window * 2);
                            } elseif ($end === $totalPages) {
                                $start = max(1, $end - $window * 2);
                            }
                        }
                    ?>

                    <?php if ($pageNum > 1): ?>
                        <a class="pagination__link pagination__link--arrow"
                           href="?<?= e(http_build_query($baseQs + ['page' => 1])) ?>"
                           aria-label="Первая страница">«</a>
                        <a class="pagination__link pagination__link--arrow"
                           href="?<?= e(http_build_query($baseQs + ['page' => $pageNum - 1])) ?>"
                           aria-label="Предыдущая страница">‹</a>
                    <?php else: ?>
                        <span class="pagination__link pagination__link--arrow pagination__link--disabled">«</span>
                        <span class="pagination__link pagination__link--arrow pagination__link--disabled">‹</span>
                    <?php endif; ?>

                    <?php if ($start > 1): ?>
                        <a class="pagination__link"
                           href="?<?= e(http_build_query($baseQs + ['page' => 1])) ?>">1</a>
                        <?php if ($start > 2): ?>
                            <span class="pagination__ellipsis">…</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($p = $start; $p <= $end; $p++): ?>
                        <?php if ($p === $pageNum): ?>
                            <span class="pagination__link pagination__link--active" aria-current="page"><?= $p ?></span>
                        <?php else: ?>
                            <a class="pagination__link"
                               href="?<?= e(http_build_query($baseQs + ['page' => $p])) ?>"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                            <span class="pagination__ellipsis">…</span>
                        <?php endif; ?>
                        <a class="pagination__link"
                           href="?<?= e(http_build_query($baseQs + ['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <?php if ($pageNum < $totalPages): ?>
                        <a class="pagination__link pagination__link--arrow"
                           href="?<?= e(http_build_query($baseQs + ['page' => $pageNum + 1])) ?>"
                           aria-label="Следующая страница">›</a>
                        <a class="pagination__link pagination__link--arrow"
                           href="?<?= e(http_build_query($baseQs + ['page' => $totalPages])) ?>"
                           aria-label="Последняя страница">»</a>
                    <?php else: ?>
                        <span class="pagination__link pagination__link--arrow pagination__link--disabled">›</span>
                        <span class="pagination__link pagination__link--arrow pagination__link--disabled">»</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
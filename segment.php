<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Segment.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/services/SegmentMatcher.php';

auth_start();
$me = current_user();

$segmentId = (int)($_GET['id'] ?? 0);
if ($segmentId <= 0) {
    http_response_code(404);
    exit('Сегмент не найден');
}

$segment = null;
$error = null;

try {
    $segment = Segment::findById($segmentId);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (!$segment && $error === null) {
    http_response_code(404);
    exit('Сегмент не найден');
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

$isCreator = $me && (int)$segment['creator_id'] === (int)$me['id'];

// ============================================================
// POST: удаление, смена видимости, пересчёт
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me) {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'delete' && $isCreator) {
        try {
            Segment::delete($segmentId, (int)$me['id']);
            flash('Сегмент удалён', 'success');
            redirect(url('segments.php'));
        } catch (Throwable $e) {
            flash('Не удалось удалить: ' . $e->getMessage(), 'error');
            redirect(url('segment.php?id=' . $segmentId));
        }
    }

    if ($action === 'rematch') {
        try {
            $result = SegmentMatcher::matchAllUsersForSegment($segmentId);
            $msg = sprintf(
                'Обработано активностей: %d, найдено усилий: %d',
                $result['processed'],
                $result['matched']
            );
            if (!empty($result['errors'])) {
                $msg .= '. Ошибок: ' . count($result['errors']);
            }
            flash($msg, $result['matched'] > 0 ? 'success' : 'info');
        } catch (Throwable $e) {
            flash('Ошибка пересчёта: ' . $e->getMessage(), 'error');
        }
        redirect(url('segment.php?id=' . $segmentId));
    }
}

// ============================================================
// АВТОМАТИЧЕСКИЙ МАТЧИНГ (не чаще раза в 5 минут)
// ============================================================
if ($me) {
    try {
        $autoKey = 'seg_matched_' . $segmentId;
        if (empty($_SESSION[$autoKey]) || (time() - (int)$_SESSION[$autoKey]) > 300) {
            SegmentMatcher::rematchUser((int)$me['id'], $segmentId);
            $_SESSION[$autoKey] = time();
        }
    } catch (Throwable $e) {}
}

// ---- Данные для страницы ----
$leaderboard = [];
$stats = ['efforts' => 0, 'athletes' => 0];
$myRank = null;
$myBest = null;
$elevationProfile = [];

try {
    $leaderboard = Segment::leaderboard($segmentId, 100);
    $stats = Segment::stats($segmentId);
    if ($me) {
        $myRank = Segment::userRank($segmentId, (int)$me['id']);
        $myBest = Segment::userBestEffort($segmentId, (int)$me['id']);
    }
    $elevationProfile = Segment::elevationProfile($segmentId, 800);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$points = Segment::parseTrackJson((string)($segment['track_json'] ?? ''));
$segmentDistance = (float)($segment['distance_m'] ?? 0);

$pageTitle = $segment['name'];

$extraCss = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'];
$extraJs  = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
];

$inlineJs = '
window.__SEGMENT_POINTS__ = ' . json_encode($points, JSON_UNESCAPED_UNICODE) . ';
window.__SEGMENT_ELEV__   = ' . json_encode($elevationProfile, JSON_UNESCAPED_UNICODE) . ';

/* ============================================================
   КАРТА
   ============================================================ */
(function () {
    if (typeof L === "undefined") return;
    var pts = window.__SEGMENT_POINTS__ || [];
    var mapEl = document.getElementById("segment-map");
    if (!mapEl) return;
    var map = L.map("segment-map", { scrollWheelZoom: false }).setView([55.751244, 37.618423], 13);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19, attribution: "&copy; OpenStreetMap"
    }).addTo(map);
    if (!pts.length) {
        mapEl.innerHTML = "<div style=\"padding:40px;text-align:center;color:#8a93a3\">Трек сегмента пуст</div>";
        return;
    }
    var latlngs = pts.map(function(p) { return [p.lat, p.lng]; });
    var line = L.polyline(latlngs, { color: "#ff5a1f", weight: 5, opacity: 0.95 }).addTo(map);
    L.circleMarker(latlngs[0], {
        radius: 7, color: "#0a7a3a", fillColor: "#0a7a3a", fillOpacity: 1, weight: 2
    }).bindPopup("Старт").addTo(map);
    L.circleMarker(latlngs[latlngs.length - 1], {
        radius: 7, color: "#b3261e", fillColor: "#b3261e", fillOpacity: 1, weight: 2
    }).bindPopup("Финиш").addTo(map);
    map.fitBounds(line.getBounds(), { padding: [30, 30] });
})();

/* ============================================================
   ПРОФИЛЬ ВЫСОТ
   ============================================================ */
(function () {
    if (typeof Chart === "undefined") return;
    var data = window.__SEGMENT_ELEV__ || [];
    var canvas = document.getElementById("segment-elev-chart");
    if (!canvas || !data.length) return;

    var minEle = Infinity, maxEle = -Infinity;
    data.forEach(function (p) {
        if (p.ele < minEle) minEle = p.ele;
        if (p.ele > maxEle) maxEle = p.ele;
    });

    var statsEl = document.getElementById("segment-elev-stats");
    if (statsEl) {
        statsEl.innerHTML =
            "<span>мин <strong>" + Math.round(minEle) + " м</strong></span>" +
            "<span>макс <strong>" + Math.round(maxEle) + " м</strong></span>" +
            "<span>перепад <strong>" + Math.round(maxEle - minEle) + " м</strong></span>";
    }

    var ctx = canvas.getContext("2d");
    var grad = ctx.createLinearGradient(0, 0, 0, 240);
    grad.addColorStop(0, "rgba(255,90,31,.45)");
    grad.addColorStop(1, "rgba(255,90,31,.02)");

    new Chart(ctx, {
        type: "line",
        data: {
            labels: data.map(function (p) { return p.d; }),
            datasets: [{
                label: "Высота",
                data: data.map(function (p) { return p.ele; }),
                borderColor: "#ff5a1f",
                backgroundColor: grad,
                borderWidth: 1.5,
                pointRadius: 0,
                pointHoverRadius: 4,
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
                    padding: 10, cornerRadius: 8, displayColors: false,
                    callbacks: {
                        title: function (items) {
                            var d = items[0].parsed.x;
                            return d >= 1000 ? (d / 1000).toFixed(2) + " км" : Math.round(d) + " м";
                        },
                        label: function (item) {
                            return "Высота: " + item.parsed.y.toFixed(0) + " м";
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: "linear", grid: { display: false },
                    ticks: {
                        maxTicksLimit: 6,
                        callback: function (v) {
                            return v >= 1000 ? (v / 1000).toFixed(1) + " км" : Math.round(v) + " м";
                        }
                    }
                },
                y: {
                    grid: { color: "rgba(15,20,32,.06)" },
                    ticks: {
                        maxTicksLimit: 5,
                        callback: function (v) { return v + " м"; }
                    }
                }
            }
        }
    });
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

<section class="segment-view">
    <header class="segment-view__head">
        <div class="segment-view__title-block">
            <span class="segment-type">
                <?= e(segment_type_icon((string)$segment['type'])) ?>
                <?= e(segment_type_label((string)$segment['type'])) ?>
            </span>
            <h1 class="segment-view__name"><?= e($segment['name']) ?></h1>
            <p class="muted">
                Создал
                <a href="<?= e(url('profile.php?u=' . urlencode((string)$segment['username']))) ?>">
                    <strong>@<?= e($segment['username']) ?></strong>
                </a>
                · <?= e(time_ago((string)$segment['created_at'])) ?>
                <?php if (!(int)$segment['is_public']): ?> · 🔒 приватный<?php endif; ?>
            </p>
        </div>

        <div class="segment-view__actions">
            <?php if ($me): ?>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rematch">
                    <button class="btn btn--primary btn--sm">🔄 Пересчитать мои усилия</button>
                </form>
            <?php endif; ?>

            <?php if ($isCreator): ?>
                <a href="<?= e(url('segment-edit.php?id=' . $segmentId)) ?>"
                   class="btn btn--ghost btn--sm">
                    ✏️ Редактировать
                </a>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Удалить сегмент? Это необратимо.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn--ghost btn--sm">🗑 Удалить</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($error !== null): ?>
        <div class="alert alert--error"><strong>Ошибка:</strong> <?= e($error) ?></div>
    <?php endif; ?>

    <div class="segment-view__stats">
        <div class="stat">
            <span class="stat__value"><?= e(format_distance((float)$segment['distance_m'])) ?></span>
            <span class="stat__label">Дистанция</span>
        </div>
        <?php if (!empty($segment['elevation_gain_m'])): ?>
            <div class="stat">
                <span class="stat__value"><?= (int)$segment['elevation_gain_m'] ?> м</span>
                <span class="stat__label">Набор высоты</span>
            </div>
        <?php endif; ?>
        <div class="stat">
            <span class="stat__value"><?= (int)$stats['athletes'] ?></span>
            <span class="stat__label">Спортсменов</span>
        </div>
        <div class="stat">
            <span class="stat__value"><?= (int)$stats['efforts'] ?></span>
            <span class="stat__label">Попыток</span>
        </div>
        <?php if ($myRank !== null): ?>
            <div class="stat stat--accent">
                <span class="stat__value">#<?= (int)$myRank ?></span>
                <span class="stat__label">Ваша позиция</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="segment-view__map">
        <div id="segment-map" class="map"></div>
    </div>

    <?php if ($elevationProfile): ?>
        <div class="chart-card segment-view__elev">
            <div class="chart-card__head">
                <h2 class="chart-card__title">⛰️ Профиль высот на участке</h2>
                <div class="chart-card__stats" id="segment-elev-stats"></div>
            </div>
            <div class="chart-card__body">
                <canvas id="segment-elev-chart"></canvas>
            </div>
        </div>
    <?php endif; ?>

    <div class="segment-view__board">
        <div class="segment-view__board-head">
            <h2>Лидерборд</h2>
            <?php if (count($leaderboard) > 0): ?>
                <span class="muted">Топ <?= count($leaderboard) ?></span>
            <?php endif; ?>
        </div>

        <?php if (!$leaderboard): ?>
            <div class="empty">
                <p>Пока никто не прошёл этот сегмент.</p>
                <?php if ($me): ?>
                    <p class="muted" style="margin-top:8px">
                        Нажмите «🔄 Пересчитать мои усилия» — система попробует найти ваши активности.
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="leaderboard-table-wrap">
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th class="lb-col-rank">Место</th>
                            <th class="lb-col-name">Имя</th>
                            <th class="lb-col-date">Дата</th>
                            <th class="lb-col-speed">Скорость</th>
                            <th class="lb-col-hr">Пульс</th>
                            <th class="lb-col-pwr">Мощность</th>
                            <th class="lb-col-time">Время</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($leaderboard as $i => $row): ?>
                        <?php
                            $rank = $i + 1;
                            $isMe = $me && (int)$row['user_id'] === (int)$me['id'];

                            $rankClass = '';
                            if ($rank === 1) $rankClass = 'is-gold';
                            elseif ($rank === 2) $rankClass = 'is-silver';
                            elseif ($rank === 3) $rankClass = 'is-bronze';

                            $targetUrl = !empty($row['activity_id'])
                                ? url('activity.php?id=' . (int)$row['activity_id'])
                                : url('profile.php?u=' . urlencode((string)$row['username']));

                            $speedKmh = $row['avg_speed_mps'] !== null
                                ? (float)$row['avg_speed_mps'] * 3.6
                                : null;

                            $date = !empty($row['started_at'])
                                ? date('d.m.Y', strtotime((string)$row['started_at']))
                                : '—';
                        ?>
                        <tr class="leaderboard-table__row <?= $rankClass ?> <?= $isMe ? 'is-me' : '' ?>"
                            data-href="<?= e($targetUrl) ?>">
                            <td class="lb-col-rank">
                                <span class="lb-rank"><?= $rank ?></span>
                            </td>
                            <td class="lb-col-name">
                                <a class="lb-user" href="<?= e($targetUrl) ?>">
                                    <span class="avatar avatar--sm">
                                        <?php if (!empty($row['avatar_url'])): ?>
                                            <img src="<?= e($row['avatar_url']) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(mb_substr((string)$row['display_name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="lb-user__name">
                                        <?= e($row['display_name']) ?>
                                        <?php if ($isMe): ?><span class="leaderboard__you">вы</span><?php endif; ?>
                                    </span>
                                </a>
                            </td>
                            <td class="lb-col-date"><?= e($date) ?></td>
                            <td class="lb-col-speed">
                                <?php if ($speedKmh !== null): ?>
                                    <?= number_format($speedKmh, 1, '.', '') ?> <small>км/ч</small>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="lb-col-hr">
                                <?php if (!empty($row['avg_hr'])): ?>
                                    <?= (int)$row['avg_hr'] ?> <small>уд/мин</small>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="lb-col-pwr">
                                <?php if (!empty($row['avg_power_w'])): ?>
                                    <?= (int)$row['avg_power_w'] ?> <small>Вт</small>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="lb-col-time">
                                <a class="lb-time" href="<?= e($targetUrl) ?>">
                                    <?= e(format_elapsed((int)$row['best_time'])) ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($myBest): ?>
        <div class="segment-view__my-effort">
            <h3>Ваше лучшее время на этом сегменте</h3>
            <div class="segment-my-effort">
                <div class="segment-my-effort__time"><?= e(format_elapsed((int)$myBest['elapsed_time_sec'])) ?></div>
                <?php
                    $mySpeedKmh = $segmentDistance > 0 && (int)$myBest['elapsed_time_sec'] > 0
                        ? ($segmentDistance / (int)$myBest['elapsed_time_sec']) * 3.6
                        : null;
                ?>
                <?php if ($mySpeedKmh !== null): ?>
                    <div class="segment-my-effort__speed">
                        <?= number_format($mySpeedKmh, 1, '.', '') ?> км/ч
                    </div>
                <?php endif; ?>
                <div class="segment-my-effort__meta muted">
                    <?php if (!empty($myBest['started_at'])): ?>
                        <?= e(date('d.m.Y', strtotime((string)$myBest['started_at']))) ?>
                    <?php endif; ?>
                    <?php if (!empty($myBest['is_auto'])): ?> · рассчитано автоматически<?php endif; ?>
                </div>
                <a class="btn btn--ghost btn--sm"
                   href="<?= e(url('activity.php?id=' . (int)$myBest['activity_id'])) ?>">
                    Открыть активность →
                </a>
            </div>
        </div>
    <?php endif; ?>
</section>

<script>
/* Клик по строке таблицы (кроме ссылок) открывает активность */
document.querySelectorAll('.leaderboard-table__row[data-href]').forEach(function (row) {
    row.addEventListener('click', function (e) {
        if (e.target.closest('a')) return;
        window.location.href = row.dataset.href;
    });
    row.style.cursor = 'pointer';
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
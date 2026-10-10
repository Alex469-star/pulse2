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
        $parentId = isset($_POST['parent_id']) && (int)$_POST['parent_id'] > 0
            ? (int)$_POST['parent_id']
            : null;
        if ($body !== '') {
            try {
                Comment::add($activityId, (int)$me['id'], $body, $parentId);
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
// РАНГИ И СТАТИСТИКА ПО СЕГМЕНТАМ
// ============================================================
$segmentRanks = [];
$segmentStats = [];
$segmentBestTimes = [];

if ($segments) {
    $segmentIds = array_map(function ($s) { return (int)$s['segment_id']; }, $segments);
    $ownerId = (int)$activity['user_id'];

    try {
        $segmentRanks = Segment::userRanksForSegments($segmentIds, $ownerId);
    } catch (Throwable $e) {
        $segmentRanks = [];
    }

    try {
        $segmentBestTimes = Segment::bestTimesForSegments($segmentIds);
    } catch (Throwable $e) {
        $segmentBestTimes = [];
    }

    try {
        $ph = implode(',', array_fill(0, count($segmentIds), '?'));
        $stmt = db()->prepare(
            "SELECT segment_id,
                    COUNT(*) AS efforts,
                    COUNT(DISTINCT user_id) AS athletes
             FROM segment_efforts
             WHERE segment_id IN ($ph)
             GROUP BY segment_id"
        );
        $stmt->execute($segmentIds);
        foreach ($stmt->fetchAll() as $row) {
            $segmentStats[(int)$row['segment_id']] = [
                'efforts'  => (int)$row['efforts'],
                'athletes' => (int)$row['athletes'],
            ];
        }
    } catch (Throwable $e) {
        $segmentStats = [];
    }
}

// ============================================================
// ХЕЛПЕР: СКОЛЬЗЯЩАЯ МЕДИАНА ДЛЯ ГРАФИКА СКОРОСТИ
// ============================================================
if (!function_exists('smoothSpeed')) {
    function smoothSpeed(array $data, int $window = 5): array
    {
        $n = count($data);
        if ($n < 3 || $window < 3) return $data;

        $half = intdiv($window, 2);
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            $from = max(0, $i - $half);
            $to   = min($n - 1, $i + $half);

            $vals = [];
            for ($j = $from; $j <= $to; $j++) {
                $vals[] = $data[$j]['v'];
            }
            sort($vals);
            $median = $vals[intdiv(count($vals), 2)];

            $out[] = ['d' => $data[$i]['d'], 'v' => $median];
        }

        return $out;
    }
}

// ============================================================
// ТРЕК + ДАННЫЕ ДЛЯ ГРАФИКОВ И СТАТИСТИКИ
// ============================================================
$track     = [];
$elevData  = [];
$speedData = [];
$hrData    = [];
$pwrData   = [];
$cadData   = [];

$sensorAgg = [
    'hr'  => ['min' => null, 'max' => null, 'avg' => null],
    'pwr' => ['min' => null, 'max' => null, 'avg' => null],
    'cad' => ['min' => null, 'max' => null, 'avg' => null],
];

$hasSpeedSource    = false;
$hasDistanceSource = false;

$calcDistanceM       = (float)($activity['distance_m'] ?? 0);
$calcDurationSec     = (int)($activity['duration_sec'] ?? 0);
$calcAvgSpeedMps     = null;
$calcAvgWithStopsMps = null;
$calcStopTimeSec     = 0;

if (!empty($activity['track_json'])) {
    $decoded = json_decode((string)$activity['track_json'], true);
    if (is_array($decoded)) {

        $points = [];
        foreach ($decoded as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            $points[] = [
                'lat'      => (float)$p['lat'],
                'lng'      => (float)$p['lng'],
                'ele'      => isset($p['ele'])      ? (float)$p['ele']      : null,
                't'        => isset($p['t'])        ? (int)$p['t']          : null,
                'hr'       => isset($p['hr'])       ? (int)$p['hr']         : null,
                'cad'      => isset($p['cad'])      ? (int)$p['cad']        : null,
                'pwr'      => isset($p['pwr'])      ? (int)$p['pwr']        : null,
                'temp'     => isset($p['temp'])     ? (float)$p['temp']     : null,
                'speed'    => isset($p['speed'])    ? (float)$p['speed']    : null,
                'distance' => isset($p['distance']) ? (float)$p['distance'] : null,
            ];
        }

        foreach ($points as $p) {
            $track[] = ['lat' => $p['lat'], 'lng' => $p['lng']];
        }

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

        $hrSum = 0;  $hrCnt = 0;
        $pwrSum = 0; $pwrCnt = 0;
        $cadSum = 0; $cadCnt = 0;

        foreach ($points as $p) {
            if ($p['distance'] !== null && $p['distance'] >= 0) { $hasDistanceSource = true; }
            if ($p['speed'] !== null && $p['speed'] >= 0)       { $hasSpeedSource = true; }
        }

        // ---- Расчёт остановок ----
        $movingTimeSec = 0;
        $stopTimeSec   = 0;
        $pauseStart    = null;
        $prevT         = null;

        foreach ($points as $i => $p) {
            if ($p['t'] === null) continue;

            $v = null;
            if ($hasSpeedSource && $p['speed'] !== null && $p['speed'] >= 0) {
                $v = $p['speed'];
            } elseif ($prev !== null && $p['t'] !== null && $prev['t'] !== null && $p['t'] > $prev['t']) {
                $seg = $haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
                $v   = $seg / ($p['t'] - $prev['t']);
            }

            if ($prevT !== null && $p['t'] > $prevT) {
                $dt = $p['t'] - $prevT;

                if ($dt > 300) {
                    $dt = 0;
                }

                if ($v !== null && $v < 0.28) {
                    if ($pauseStart === null) $pauseStart = $prevT;
                    $stopTimeSec += $dt;
                } else {
                    if ($pauseStart !== null) {
                        $pauseLen = $prevT - $pauseStart;
                        if ($pauseLen < 3) {
                            $stopTimeSec -= $pauseLen;
                            $movingTimeSec += $pauseLen;
                        }
                        $pauseStart = null;
                    }
                    $movingTimeSec += $dt;
                }
            }

            $prevT = $p['t'];
            $prev  = $p;
        }

        // ---- Итоговая дистанция ----
        if ($hasDistanceSource) {
            $last = end($points);
            $calcDistanceM = (float)($last['distance'] ?? 0);
        } else {
            $calcDistanceM = 0.0;
            $prevP = null;
            foreach ($points as $p) {
                if ($prevP !== null) {
                    $calcDistanceM += $haversine($prevP['lat'], $prevP['lng'], $p['lat'], $p['lng']);
                }
                $prevP = $p;
            }
        }

        // ---- Итоговое время ----
        $calcDurationSec = $movingTimeSec + $stopTimeSec;

        // ---- Средние скорости ----
        if ($movingTimeSec > 0 && $calcDistanceM > 0) {
            $calcAvgSpeedMps = $calcDistanceM / $movingTimeSec;
        }
        if ($calcDurationSec > 0 && $calcDistanceM > 0) {
            $calcAvgWithStopsMps = $calcDistanceM / $calcDurationSec;
        }
        $calcStopTimeSec = (int)round($stopTimeSec);

        // ---- Графики ----
        $dist = 0.0;
        $prev = null;

        foreach ($points as $i => $p) {
            if ($hasDistanceSource && $p['distance'] !== null && $p['distance'] >= 0) {
                $dist = max($dist, $p['distance']);
            } elseif ($prev !== null) {
                $dist += $haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
            }

            if ($p['ele'] !== null) {
                $elevData[] = ['d' => round($dist, 1), 'ele' => round($p['ele'], 1)];
            }

            if ($hasSpeedSource && $p['speed'] !== null && $p['speed'] >= 0) {
                $speedKmh = $p['speed'] * 3.6;
                if ($speedKmh < 120) {
                    $speedData[] = ['d' => round($dist, 1), 'v' => round($speedKmh, 2)];
                }
            } elseif ($p['t'] !== null && $i > 0 && $points[$i - 1]['t'] !== null) {
                $dt = $p['t'] - $points[$i - 1]['t'];
                if ($dt > 0) {
                    $seg = $haversine(
                        $points[$i - 1]['lat'], $points[$i - 1]['lng'],
                        $p['lat'], $p['lng']
                    );
                    $speed = $seg / $dt;
                    $limitMps = ($activity['type'] === 'ride') ? 22.0 : 13.9;
                    if ($speed < $limitMps) {
                        $speedData[] = ['d' => round($dist, 1), 'v' => round($speed * 3.6, 2)];
                    }
                }
            }

            if ($p['hr'] !== null) {
                $hrData[] = ['d' => round($dist, 1), 'hr' => $p['hr']];
                $hrSum += $p['hr']; $hrCnt++;
                if ($sensorAgg['hr']['min'] === null || $p['hr'] < $sensorAgg['hr']['min']) $sensorAgg['hr']['min'] = $p['hr'];
                if ($sensorAgg['hr']['max'] === null || $p['hr'] > $sensorAgg['hr']['max']) $sensorAgg['hr']['max'] = $p['hr'];
            }

            if ($p['pwr'] !== null) {
                $pwrData[] = ['d' => round($dist, 1), 'pwr' => $p['pwr']];
                $pwrSum += $p['pwr']; $pwrCnt++;
                if ($sensorAgg['pwr']['min'] === null || $p['pwr'] < $sensorAgg['pwr']['min']) $sensorAgg['pwr']['min'] = $p['pwr'];
                if ($sensorAgg['pwr']['max'] === null || $p['pwr'] > $sensorAgg['pwr']['max']) $sensorAgg['pwr']['max'] = $p['pwr'];
            }

            if ($p['cad'] !== null) {
                $cadData[] = ['d' => round($dist, 1), 'cad' => $p['cad']];
                $cadSum += $p['cad']; $cadCnt++;
                if ($sensorAgg['cad']['min'] === null || $p['cad'] < $sensorAgg['cad']['min']) $sensorAgg['cad']['min'] = $p['cad'];
                if ($sensorAgg['cad']['max'] === null || $p['cad'] > $sensorAgg['cad']['max']) $sensorAgg['cad']['max'] = $p['cad'];
            }

            $prev = $p;
        }

        if ($hrCnt)  $sensorAgg['hr']['avg']  = (int)round($hrSum / $hrCnt);
        if ($pwrCnt) $sensorAgg['pwr']['avg'] = (int)round($pwrSum / $pwrCnt);
        if ($cadCnt) $sensorAgg['cad']['avg'] = (int)round($cadSum / $cadCnt);

        if ($speedData && !$hasSpeedSource) {
            $speedData = smoothSpeed($speedData, 5);
        }

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
        $hrData    = $decimate($hrData,    800);
        $pwrData   = $decimate($pwrData,   800);
        $cadData   = $decimate($cadData,   800);
    }
}

$hasSensors = !empty($activity['has_sensors'])
    || $hrData || $pwrData || $cadData;

$pageTitle = $activity['title'];

// ============================================================
// РЕСУРСЫ
// activity-trim.js НЕ подключаем здесь — он должен идти
// после HTML-разметки модалки, в самом низу страницы.
// ============================================================
$extraCss = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    url('assets/css/activity-trim.css'),
];

$extraJs = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
];

$publicUrl = app_url('activity.php?id=' . $activityId);
$shareText = rawurlencode((string)$activity['title'] . ' — Pulse');
$shareTitle = rawurlencode((string)$activity['title']);

$totalDistance = !empty($elevData) ? (float)end($elevData)['d'] : 0.0;

$csrfJson      = json_encode(csrf_token());
$likersApiJson = json_encode(url('api/activity-likers.php'));
$likeApiJson   = json_encode(url('api/like.php'));
$trackJson     = json_encode($track, JSON_UNESCAPED_UNICODE);
$elevJson      = json_encode($elevData, JSON_UNESCAPED_UNICODE);
$speedJson     = json_encode($speedData, JSON_UNESCAPED_UNICODE);
$hrJson        = json_encode($hrData, JSON_UNESCAPED_UNICODE);
$pwrJson       = json_encode($pwrData, JSON_UNESCAPED_UNICODE);
$cadJson       = json_encode($cadData, JSON_UNESCAPED_UNICODE);
$totalDJson    = json_encode($totalDistance);
$publicUrlJson = json_encode($publicUrl);

$inlineJs = '
window.__ACTIVITY_TRACK__   = ' . $trackJson . ';
window.__ACTIVITY_ELEV__    = ' . $elevJson . ';
window.__ACTIVITY_SPEED__   = ' . $speedJson . ';
window.__ACTIVITY_HR__      = ' . $hrJson . ';
window.__ACTIVITY_PWR__     = ' . $pwrJson . ';
window.__ACTIVITY_CAD__     = ' . $cadJson . ';
window.__ACTIVITY_TOTAL_D__ = ' . $totalDJson . ';
window.__ACTIVITY_URL__     = ' . $publicUrlJson . ';

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
            var old = btn.querySelector(".activity-sidebar__label");
            if (old) {
                var text = old.textContent;
                old.textContent = "Скопировано";
                btn.classList.add("is-copied");
                setTimeout(function () { old.textContent = text; btn.classList.remove("is-copied"); }, 1500);
            }
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
    var apiUrl = ' . $likersApiJson . ';
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
        modal.addEventListener("click", function (e) { if (e.target.closest("[data-close]")) close(); });
        document.addEventListener("keydown", function (e) { if (!modal.hidden && e.key === "Escape") close(); });
    }

    function open(activityId) {
        ensureModal();
        var modal = el("likers-modal");
        var body = el("likers-modal-body");
        modal.hidden = false;
        document.body.style.overflow = "hidden";
        if (cache[activityId]) { body.innerHTML = cache[activityId]; return; }
        body.innerHTML = "<div class=\"likers-modal__loading\">Загрузка…</div>";
        fetch(apiUrl + "?activity_id=" + encodeURIComponent(activityId), {
            credentials: "same-origin", headers: { "Accept": "application/json" }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.ok) throw new Error((res && res.error) || "Ошибка");
            var likers = (res.data && res.data.likers) || [];
            var html = renderList(likers);
            cache[activityId] = html;
            body.innerHTML = html;
        })
        .catch(function () { body.innerHTML = "<div class=\"likers-modal__empty\">Не удалось загрузить список</div>"; });
    }

    function renderList(likers) {
        if (!likers.length) return "<div class=\"likers-modal__empty\">Пока никто не лайкнул</div>";
        var html = "<ul class=\"likers-list\">";
        likers.forEach(function (u) {
            var avatar = u.avatar_url ? "<img src=\"" + escapeAttr(u.avatar_url) + "\" alt=\"\">" : escapeHtml(u.initial || "?");
            html += "<li class=\"likers-list__item\"><a class=\"likers-list__link\" href=\"" + escapeAttr(u.profile_url) + "\">" +
                "<span class=\"avatar avatar--sm\">" + avatar + "</span>" +
                "<span class=\"likers-list__info\"><span class=\"likers-list__name\">" + escapeHtml(u.display_name) + "</span>" +
                "<span class=\"likers-list__username\">@" + escapeHtml(u.username) + "</span></span></a></li>";
        });
        return html + "</ul>";
    }

    function close() {
        var modal = el("likers-modal");
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = "";
    }

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/\x27/g, "&#039;");
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
   ГРАФИКИ + СИНХРОНИЗАЦИЯ ТОЧКИ НА КАРТЕ
   ============================================================ */
(function () {
    if (typeof Chart === "undefined") return;

    var elevData  = window.__ACTIVITY_ELEV__  || [];
    var speedData = window.__ACTIVITY_SPEED__ || [];
    var hrData    = window.__ACTIVITY_HR__    || [];
    var pwrData   = window.__ACTIVITY_PWR__   || [];
    var cadData   = window.__ACTIVITY_CAD__   || [];
    var totalD    = window.__ACTIVITY_TOTAL_D__ || 0;

    Chart.defaults.font.family = "\'Inter\', system-ui, sans-serif";
    Chart.defaults.font.size   = 11;
    Chart.defaults.color       = "#5b6473";

    var distanceTick = function (v) {
        return v >= 1000 ? (v / 1000).toFixed(1) + " км" : Math.round(v) + " м";
    };
    var tooltipTitleDistance = function (items) {
        var d = items[0].parsed.x;
        return d >= 1000 ? (d / 1000).toFixed(2) + " км" : Math.round(d) + " м";
    };

    function buildDistanceChart(canvasId, data, opts) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !data.length) return;
        var ctx = canvas.getContext("2d");
        var grad = ctx.createLinearGradient(0, 0, 0, 220);
        grad.addColorStop(0, opts.fillTop);
        grad.addColorStop(1, opts.fillBottom);
        return new Chart(ctx, {
            type: "line",
            data: {
                labels: data.map(function (p) { return p.d; }),
                datasets: [{
                    label: opts.label,
                    data: data.map(function (p) { return p[opts.key]; }),
                    borderColor: opts.color,
                    backgroundColor: grad,
                    borderWidth: 1.5,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointHoverBackgroundColor: opts.color,
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
                        titleColor: "#fff", bodyColor: "#fff",
                        padding: 10, cornerRadius: 8, displayColors: false,
                        callbacks: {
                            title: tooltipTitleDistance,
                            label: function (item) {
                                return opts.label + ": " + item.parsed.y.toFixed(opts.decimals) + " " + opts.unit;
                            }
                        }
                    }
                },
                scales: {
                    x: { type: "linear", grid: { display: false }, ticks: { maxTicksLimit: 6, callback: distanceTick } },
                    y: { beginAtZero: opts.beginAtZero === true, grid: { color: "rgba(15,20,32,.06)" }, ticks: { maxTicksLimit: 5, callback: function (v) { return v + " " + opts.unit; } } }
                }
            }
        });
    }

    function computeStats(data, key) {
        var min = Infinity, max = -Infinity, sum = 0;
        data.forEach(function (p) { var v = p[key]; if (v < min) min = v; if (v > max) max = v; sum += v; });
        return { min: min, max: max, avg: sum / data.length };
    }

    if (elevData.length) {
        var s = computeStats(elevData, "ele");
        var stats = document.getElementById("elev-stats");
        if (stats) stats.innerHTML =
            "<span>мин <strong>" + Math.round(s.min) + " м</strong></span>" +
            "<span>макс <strong>" + Math.round(s.max) + " м</strong></span>" +
            "<span>сред <strong>" + Math.round(s.avg) + " м</strong></span>";
        buildDistanceChart("elev-chart", elevData, {
            key: "ele", label: "Высота", unit: "м", decimals: 0,
            color: "#ff5a1f", fillTop: "rgba(255,90,31,.35)", fillBottom: "rgba(255,90,31,.02)"
        });
    }

    if (speedData.length) {
        var s2 = computeStats(speedData, "v");
        var stats2 = document.getElementById("speed-stats");
        if (stats2) stats2.innerHTML =
            "<span>сред <strong>" + s2.avg.toFixed(1) + " км/ч</strong></span>" +
            "<span>макс <strong>" + s2.max.toFixed(1) + " км/ч</strong></span>";
        buildDistanceChart("speed-chart", speedData, {
            key: "v", label: "Скорость", unit: "км/ч", decimals: 1,
            color: "#1f5fc4", fillTop: "rgba(31,95,196,.30)", fillBottom: "rgba(31,95,196,.02)",
            beginAtZero: true
        });
    }

    if (hrData.length) {
        var s3 = computeStats(hrData, "hr");
        var stats3 = document.getElementById("hr-stats");
        if (stats3) stats3.innerHTML =
            "<span>сред <strong>" + Math.round(s3.avg) + " уд/мин</strong></span>" +
            "<span>макс <strong>" + Math.round(s3.max) + " уд/мин</strong></span>";
        buildDistanceChart("hr-chart", hrData, {
            key: "hr", label: "Пульс", unit: "уд/мин", decimals: 0,
            color: "#e11d48", fillTop: "rgba(225,29,72,.30)", fillBottom: "rgba(225,29,72,.02)"
        });
    }

    if (pwrData.length) {
        var s4 = computeStats(pwrData, "pwr");
        var stats4 = document.getElementById("pwr-stats");
        if (stats4) stats4.innerHTML =
            "<span>сред <strong>" + Math.round(s4.avg) + " Вт</strong></span>" +
            "<span>макс <strong>" + Math.round(s4.max) + " Вт</strong></span>";
        buildDistanceChart("pwr-chart", pwrData, {
            key: "pwr", label: "Мощность", unit: "Вт", decimals: 0,
            color: "#7c3aed", fillTop: "rgba(124,58,237,.30)", fillBottom: "rgba(124,58,237,.02)",
            beginAtZero: true
        });
    }

    if (cadData.length) {
        var s5 = computeStats(cadData, "cad");
        var stats5 = document.getElementById("cad-stats");
        if (stats5) stats5.innerHTML =
            "<span>сред <strong>" + Math.round(s5.avg) + "</strong></span>" +
            "<span>макс <strong>" + Math.round(s5.max) + "</strong></span>";
        buildDistanceChart("cad-chart", cadData, {
            key: "cad", label: "Каденс", unit: "об/мин", decimals: 0,
            color: "#0a7a3a", fillTop: "rgba(10,122,58,.28)", fillBottom: "rgba(10,122,58,.02)",
            beginAtZero: true
        });
    }

    /* ---- Синхронизация: наведение на график → точка на карте ---- */
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

        function attach(canvasId) {
            var canvas = document.getElementById(canvasId);
            if (!canvas) return;

            canvas.addEventListener("mousemove", function (e) {
                var chart = Chart.getChart(canvas);
                if (!chart) return;

                var rect = canvas.getBoundingClientRect();
                var x = e.clientX - rect.left;

                var meta = chart.getDatasetMeta(0);
                var elements = meta.data;
                if (!elements.length) return;

                var closest = 0, minDist = Infinity;
                for (var i = 0; i < elements.length; i++) {
                    var dx = Math.abs(elements[i].x - x);
                    if (dx < minDist) { minDist = dx; closest = i; }
                }

                var label = chart.data.labels[closest];
                if (typeof label === "number") showAtDistance(label);
            });

            canvas.addEventListener("mouseleave", hide);
            canvas.addEventListener("touchend", hide);
        }

        ["elev-chart", "speed-chart", "hr-chart", "pwr-chart", "cad-chart"].forEach(attach);
    })();
})();

/* ============================================================
   AJAX-ЛАЙК
   ============================================================ */
(function () {
    "use strict";
    var csrf = ' . $csrfJson . ';
    var likeApi = ' . $likeApiJson . ';

    document.querySelectorAll(".js-like-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var activityId = parseInt(btn.dataset.activityId, 10);
            if (!activityId) return;
            btn.disabled = true;
            fetch(likeApi, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                body: JSON.stringify({ activity_id: activityId })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) throw new Error((res && res.error) || "Ошибка");
                var data = res.data || {};
                btn.classList.toggle("is-active", !!data.liked);
                var counter = btn.querySelector(".js-like-count");
                if (counter) counter.textContent = parseInt(data.count, 10) || 0;
                var likersBtn = document.querySelector(".js-likers-btn[data-activity-id=\"" + activityId + "\"]");
                if (likersBtn) likersBtn.style.display = (parseInt(data.count,10) || 0) > 0 ? "" : "none";
            })
            .catch(function (err) { console.error("Like error:", err); })
            .finally(function () { btn.disabled = false; });
        });
    });
})();
';

require __DIR__ . '/includes/header.php';

function activity_icon(string $type): string {
    return match ($type) {
        'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
        'walk' => '🚶', 'hike' => '🥾', default => '📦',
    };
}
function activity_label(string $type): string {
    return match ($type) {
        'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
        'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
        default => 'Другое',
    };
}
function format_elapsed(int $seconds): string {
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}
function format_gap_from_leader(?int $gapSec): string {
    if ($gapSec === null || $gapSec <= 0) return '';
    if ($gapSec < 60) return '+' . $gapSec . ' сек';
    $m = intdiv($gapSec, 60);
    $s = $gapSec % 60;
    return '+' . $m . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
}
if (!function_exists('format_speed_kmh')) {
    function format_speed_kmh(?float $mps): string {
        if ($mps === null || $mps <= 0) return '—';
        return number_format($mps * 3.6, 1, '.', '');
    }
}

$aggHrAvg  = $activity['avg_hr']      ?? $sensorAgg['hr']['avg']  ?? null;
$aggHrMax  = $activity['max_hr']      ?? $sensorAgg['hr']['max']  ?? null;
$aggCadAvg = $activity['avg_cadence'] ?? $sensorAgg['cad']['avg'] ?? null;
$aggCadMax = $activity['max_cadence'] ?? $sensorAgg['cad']['max'] ?? null;
$aggPwrAvg = $activity['avg_power_w'] ?? $sensorAgg['pwr']['avg'] ?? null;
$aggPwrMax = $activity['max_power_w'] ?? $sensorAgg['pwr']['max'] ?? null;
$aggTemp   = $activity['avg_temp_c']  ?? null;
?>

<div class="activity-layout">

    <!-- ============ САЙДБАР ============ -->
    <aside class="activity-sidebar">
        <div class="activity-sidebar__block activity-sidebar__block--author">
            <a class="activity-sidebar__author" href="<?= e(url('profile.php?u=' . urlencode((string)$activity['username']))) ?>">
                <span class="avatar avatar--lg">
                    <?php if (!empty($activity['avatar_url'])): ?>
                        <img src="<?= e($activity['avatar_url']) ?>" alt="">
                    <?php else: ?>
                        <?= e(mb_substr((string)$activity['display_name'], 0, 1)) ?>
                    <?php endif; ?>
                </span>
                <span class="activity-sidebar__author-info">
                    <span class="activity-sidebar__author-name"><?= e($activity['display_name']) ?></span>
                    <span class="activity-sidebar__author-meta">@<?= e($activity['username']) ?></span>
                </span>
            </a>
            <div class="activity-sidebar__meta-row">
                <span><?= e(time_ago((string)$activity['created_at'])) ?></span>
                <?php if ($visibility === 'private'): ?>
                    <span class="badge badge--private">🔒 Приватная</span>
                <?php elseif ($visibility === 'followers'): ?>
                    <span class="badge badge--followers">👥 Для подписчиков</span>
                <?php else: ?>
                    <span class="badge badge--public">🌐 Публичная</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="activity-sidebar__block">
            <h3 class="activity-sidebar__title">Действия</h3>
            <nav class="activity-sidebar__nav">
                <a class="activity-sidebar__link" href="<?= e(url('activity.php?id=' . $activityId . '&export=gpx')) ?>">
                    <span class="activity-sidebar__icon">⬇</span>
                    <span class="activity-sidebar__label">Скачать GPX</span>
                </a>
                <a class="activity-sidebar__link" href="<?= e(url('activity.php?id=' . $activityId . '&export=tcx')) ?>">
                    <span class="activity-sidebar__icon">⬇</span>
                    <span class="activity-sidebar__label">Скачать TCX</span>
                </a>
                <button type="button" class="activity-sidebar__link js-copy-link">
                    <span class="activity-sidebar__icon">🔗</span>
                    <span class="activity-sidebar__label">Скопировать ссылку</span>
                </button>
                <a class="activity-sidebar__link" target="_blank" rel="noopener"
                   href="https://t.me/share/url?url=<?= rawurlencode($publicUrl) ?>&text=<?= $shareText ?>">
                    <span class="activity-sidebar__icon">📨</span>
                    <span class="activity-sidebar__label">Telegram</span>
                </a>
                <a class="activity-sidebar__link" target="_blank" rel="noopener"
                   href="https://vk.com/share.php?url=<?= rawurlencode($publicUrl) ?>&title=<?= $shareTitle ?>">
                    <span class="activity-sidebar__icon">📢</span>
                    <span class="activity-sidebar__label">ВКонтакте</span>
                </a>
                <a class="activity-sidebar__link" target="_blank" rel="noopener"
                   href="https://twitter.com/intent/tweet?url=<?= rawurlencode($publicUrl) ?>&text=<?= $shareText ?>">
                    <span class="activity-sidebar__icon">🐦</span>
                    <span class="activity-sidebar__label">Twitter</span>
                </a>
                <a class="activity-sidebar__link" href="<?= e(url('segment-create.php?activity_id=' . $activityId)) ?>">
                    <span class="activity-sidebar__icon">⚡</span>
                    <span class="activity-sidebar__label">Создать сегмент</span>
                </a>
            </nav>
        </div>

        <?php if ($isOwner): ?>
            <div class="activity-sidebar__block">
                <h3 class="activity-sidebar__title">Управление</h3>
                <nav class="activity-sidebar__nav">
                    <a class="activity-sidebar__link" href="<?= e(url('activity-edit.php?id=' . $activityId)) ?>">
                        <span class="activity-sidebar__icon">✏️</span>
                        <span class="activity-sidebar__label">Редактировать</span>
                    </a>

                    <?php if ($track): ?>
                        <button type="button" class="activity-sidebar__link js-trim-open">
                            <span class="activity-sidebar__icon">✂️</span>
                            <span class="activity-sidebar__label">Обрезать трек</span>
                        </button>
                    <?php endif; ?>

                    <form method="post" class="activity-sidebar__form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_visibility">
                        <button class="activity-sidebar__link" type="submit">
                            <span class="activity-sidebar__icon"><?= $visibility === 'public' ? '🔒' : '🌐' ?></span>
                            <span class="activity-sidebar__label"><?= $visibility === 'public' ? 'Скрыть' : 'Опубликовать' ?></span>
                        </button>
                    </form>
                    <form method="post" class="activity-sidebar__form"
                          onsubmit="return confirm('Удалить активность? Это необратимо.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <button class="activity-sidebar__link activity-sidebar__link--danger" type="submit">
                            <span class="activity-sidebar__icon">🗑</span>
                            <span class="activity-sidebar__label">Удалить</span>
                        </button>
                    </form>
                </nav>
            </div>
        <?php endif; ?>

        <?php if ($gear): ?>
            <div class="activity-sidebar__block">
                <h3 class="activity-sidebar__title">Инвентарь</h3>
                <div class="activity-sidebar__gear">
                    <span class="activity-sidebar__gear-icon">
                        <?= $gear['type'] === 'bike' ? '🚴' : ($gear['type'] === 'shoes' ? '👟' : ($gear['type'] === 'skis' ? '⛷️' : '🎒')) ?>
                    </span>
                    <div>
                        <div class="activity-sidebar__gear-name"><?= e($gear['name']) ?></div>
                        <?php if (!empty($gear['brand'])): ?>
                            <div class="muted"><?= e($gear['brand']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </aside>

    <!-- ============ ОСНОВНОЙ КОНТЕНТ ============ -->
    <main class="activity-main">

        <header class="activity-hero">
            <div class="activity-hero__type">
                <span class="activity-type activity-type--lg">
                    <?= e(activity_icon((string)$activity['type'])) ?>
                    <?= e(activity_label((string)$activity['type'])) ?>
                </span>
            </div>
            <h1 class="activity-hero__title"><?= e($activity['title']) ?></h1>
            <div class="activity-hero__meta muted">
                <?= e(date('d.m.Y H:i', strtotime((string)($activity['started_at'] ?? $activity['created_at'])))) ?>
            </div>
        </header>

        <?php if (!empty($activity['description'])): ?>
            <div class="activity-card">
                <h2 class="activity-card__title">Описание</h2>
                <div class="activity-card__text"><?= nl2br(e((string)$activity['description'])) ?></div>
            </div>
        <?php endif; ?>

        <div class="activity-stats-grid">
            <div class="activity-stat-card">
                <div class="activity-stat-card__label">Дистанция</div>
                <div class="activity-stat-card__value"><?= e(format_distance($calcDistanceM)) ?></div>
            </div>
            <div class="activity-stat-card">
                <div class="activity-stat-card__label">Время</div>
                <div class="activity-stat-card__value"><?= e(format_duration($calcDurationSec)) ?></div>
            </div>
            <div class="activity-stat-card">
                <div class="activity-stat-card__label">Средняя</div>
                <div class="activity-stat-card__value">
                    <?php if ($calcAvgSpeedMps !== null): ?>
                        <?= e(format_speed_kmh($calcAvgSpeedMps)) ?> <small>км/ч</small>
                    <?php elseif ($activity['type'] === 'ride' && $activity['avg_speed_mps']): ?>
                        <?= number_format((float)$activity['avg_speed_mps'] * 3.6, 1, '.', '') ?> <small>км/ч</small>
                    <?php elseif ($activity['type'] !== 'ride'): ?>
                        <?= e(format_pace($calcDistanceM, $calcDurationSec)) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
            </div>
            <div class="activity-stat-card">
                <div class="activity-stat-card__label">Набор высоты</div>
                <div class="activity-stat-card__value"><?= $activity['elevation_gain_m'] ? (int)$activity['elevation_gain_m'] . ' <small>м</small>' : '—' ?></div>
            </div>
        </div>

        <?php if ($calcDurationSec > 0 && ($calcStopTimeSec > 0 || $calcAvgWithStopsMps !== null)): ?>
            <div class="activity-stats-grid activity-stats-grid--sensors">
                <div class="activity-stat-card activity-stat-card--sensor">
                    <div class="activity-stat-card__icon">⏱</div>
                    <div class="activity-stat-card__label">Средняя с остановками</div>
                    <div class="activity-stat-card__value">
                        <?= $calcAvgWithStopsMps !== null ? e(format_speed_kmh($calcAvgWithStopsMps)) : '—' ?>
                        <small>км/ч</small>
                    </div>
                </div>
                <div class="activity-stat-card activity-stat-card--sensor">
                    <div class="activity-stat-card__icon">⏸</div>
                    <div class="activity-stat-card__label">Время остановок</div>
                    <div class="activity-stat-card__value">
                        <?= e(format_duration($calcStopTimeSec)) ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($hasSensors): ?>
            <div class="activity-stats-grid activity-stats-grid--sensors">
                <?php if ($aggHrAvg !== null): ?>
                    <div class="activity-stat-card activity-stat-card--sensor">
                        <div class="activity-stat-card__icon">❤️</div>
                        <div class="activity-stat-card__label">Пульс</div>
                        <div class="activity-stat-card__value">
                            <?= (int)$aggHrAvg ?><?= $aggHrMax !== null ? ' / ' . (int)$aggHrMax : '' ?>
                            <small>уд/мин</small>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($aggCadAvg !== null): ?>
                    <div class="activity-stat-card activity-stat-card--sensor">
                        <div class="activity-stat-card__icon">🔄</div>
                        <div class="activity-stat-card__label">Каденс</div>
                        <div class="activity-stat-card__value">
                            <?= (int)$aggCadAvg ?><?= $aggCadMax !== null ? ' / ' . (int)$aggCadMax : '' ?>
                            <small>об/мин</small>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($aggPwrAvg !== null): ?>
                    <div class="activity-stat-card activity-stat-card--sensor">
                        <div class="activity-stat-card__icon">⚡</div>
                        <div class="activity-stat-card__label">Мощность</div>
                        <div class="activity-stat-card__value">
                            <?= (int)$aggPwrAvg ?><?= $aggPwrMax !== null ? ' / ' . (int)$aggPwrMax : '' ?>
                            <small>Вт</small>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($aggTemp !== null): ?>
                    <div class="activity-stat-card activity-stat-card--sensor">
                        <div class="activity-stat-card__icon">🌡️</div>
                        <div class="activity-stat-card__label">Температура</div>
                        <div class="activity-stat-card__value"><?= number_format((float)$aggTemp, 1) ?><small>°C</small></div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($activityPhotos || $isOwner): ?>
            <div class="activity-card">
                <div class="activity-card__head-row">
                    <h2 class="activity-card__title">Фотографии (<?= count($activityPhotos) ?>)</h2>
                    <?php if ($isOwner): ?>
                        <label class="btn btn--ghost btn--sm activity-photo-add-label">
                            📷 Добавить фото
                            <input type="file"
                                   id="activity-photo-input"
                                   accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
                                   multiple
                                   hidden>
                        </label>
                    <?php endif; ?>
                </div>

                <?php if ($activityPhotos): ?>
                    <div class="activity-gallery" id="activity-gallery">
                        <?php foreach ($activityPhotos as $i => $ph): ?>
                            <div class="activity-gallery__wrap" data-photo-id="<?= (int)$ph['id'] ?>">
                                <button type="button"
                                        class="activity-gallery__item"
                                        data-index="<?= $i ?>"
                                        data-photo-url="<?= e($ph['url']) ?>"
                                        aria-label="Открыть фото <?= $i + 1 ?> из <?= count($activityPhotos) ?>">
                                    <img src="<?= e($ph['url']) ?>" alt="" loading="lazy">
                                </button>
                                <?php if ($isOwner): ?>
                                    <button type="button"
                                            class="activity-gallery__delete js-photo-delete"
                                            data-photo-id="<?= (int)$ph['id'] ?>"
                                            title="Удалить фото">×</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted">Фотографий пока нет.</p>
                <?php endif; ?>

                <div class="activity-photo-progress" id="activity-photo-progress" hidden>
                    <div class="activity-photo-progress__bar" id="activity-photo-progress-bar"></div>
                </div>
                <div class="activity-photo-status muted" id="activity-photo-status" hidden></div>
            </div>
        <?php endif; ?>

        <?php if ($track): ?>
            <div class="activity-card">
                <div id="activity-map" class="map"></div>
            </div>
        <?php else: ?>
            <div class="activity-card activity-card--empty">
                <span class="muted">Эта активность без GPS-трека</span>
            </div>
        <?php endif; ?>

        <?php if ($elevData || $speedData || $hrData || $pwrData || $cadData): ?>
            <div class="activity-charts">
                <?php if ($elevData): ?>
                    <div class="chart-card">
                        <div class="chart-card__head">
                            <h2 class="chart-card__title">⛰️ Профиль высот</h2>
                            <div class="chart-card__stats" id="elev-stats"></div>
                        </div>
                        <div class="chart-card__body"><canvas id="elev-chart"></canvas></div>
                    </div>
                <?php endif; ?>
                <?php if ($speedData): ?>
                    <div class="chart-card">
                        <div class="chart-card__head">
                            <h2 class="chart-card__title">⚡ Скорость</h2>
                            <div class="chart-card__stats" id="speed-stats"></div>
                        </div>
                        <div class="chart-card__body"><canvas id="speed-chart"></canvas></div>
                    </div>
                <?php endif; ?>
                <?php if ($hrData): ?>
                    <div class="chart-card">
                        <div class="chart-card__head">
                            <h2 class="chart-card__title">❤️ Пульс</h2>
                            <div class="chart-card__stats" id="hr-stats"></div>
                        </div>
                        <div class="chart-card__body"><canvas id="hr-chart"></canvas></div>
                    </div>
                <?php endif; ?>
                <?php if ($pwrData): ?>
                    <div class="chart-card">
                        <div class="chart-card__head">
                            <h2 class="chart-card__title">⚡ Мощность</h2>
                            <div class="chart-card__stats" id="pwr-stats"></div>
                        </div>
                        <div class="chart-card__body"><canvas id="pwr-chart"></canvas></div>
                    </div>
                <?php endif; ?>
                <?php if ($cadData): ?>
                    <div class="chart-card">
                        <div class="chart-card__head">
                            <h2 class="chart-card__title">🔄 Каденс</h2>
                            <div class="chart-card__stats" id="cad-stats"></div>
                        </div>
                        <div class="chart-card__body"><canvas id="cad-chart"></canvas></div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($activityPhotos): ?>
            <div class="lightbox" id="lightbox" hidden>
                <button type="button" class="lightbox__close" id="lightbox-close" aria-label="Закрыть">×</button>
                <button type="button" class="lightbox__prev" id="lightbox-prev" aria-label="Предыдущее">‹</button>
                <button type="button" class="lightbox__next" id="lightbox-next" aria-label="Следующее">›</button>
                <div class="lightbox__counter" id="lightbox-counter"></div>
                <div class="lightbox__img-wrap"><img src="" alt="" id="lightbox-img"></div>
            </div>
        <?php endif; ?>

        <?php if ($segments): ?>
            <div class="activity-card">
                <h2 class="activity-card__title">Сегменты на этой активности</h2>
                <div class="segment-rows">
                    <?php foreach ($segments as $s): ?>
                        <?php
                            $segId         = (int)$s['segment_id'];
                            $ownerRank     = $segmentRanks[$segId] ?? null;
                            $segStat       = $segmentStats[$segId] ?? ['efforts' => 0, 'athletes' => 0];
                            $bestTime      = $segmentBestTimes[$segId] ?? null;
                            $ownerTime     = (int)$s['elapsed_time_sec'];
                            $ownerIsLeader = ($ownerRank === 1);

                            $gapSec = null;
                            if (!$ownerIsLeader && $bestTime !== null && $ownerTime > $bestTime) {
                                $gapSec = $ownerTime - $bestTime;
                            }
                            $gapText = format_gap_from_leader($gapSec);

                            $segmentDistanceM = (float)$s['distance_m'];
                            $avgSpeedMps = ($segmentDistanceM > 0 && $ownerTime > 0)
                                ? $segmentDistanceM / $ownerTime
                                : null;
                            $avgSpeedKmh = $avgSpeedMps !== null ? $avgSpeedMps * 3.6 : null;
                        ?>
                        <a class="segment-row <?= $ownerIsLeader ? 'segment-row--leader' : '' ?>"
                           href="<?= e(url('segment.php?id=' . $segId)) ?>">
                            <div class="segment-row__body">
                                <div class="segment-row__name">
                                    <strong><?= e($s['name']) ?></strong>
                                    <?php if ($ownerIsLeader): ?>
                                        <span class="segment-row__crown" title="Лидер этого сегмента">👑</span>
                                    <?php endif; ?>
                                    <?php if (!empty($s['is_auto'])): ?>
                                        <span class="segment-row__badge">авто</span>
                                    <?php endif; ?>
                                </div>
                                <div class="segment-row__meta muted">
                                    <?= e(format_distance((float)$s['distance_m'])) ?>
                                    <?php if (!empty($s['match_quality'])): ?>
                                        · совпадение <?= (int)$s['match_quality'] ?>%
                                    <?php endif; ?>
                                    <?php if ($ownerRank !== null): ?>
                                        · <?= $ownerIsLeader ? '🥇 1-е место' : ($ownerRank === 2 ? '🥈 2-е место' : ($ownerRank === 3 ? '🥉 3-е место' : 'позиция #' . $ownerRank)) ?>
                                        из <?= (int)$segStat['athletes'] ?>
                                    <?php endif; ?>
                                </div>

                                <?php if ($gapText !== ''): ?>
                                    <div class="segment-row__gap" title="Отставание владельца от первого места">
                                        <?= e($gapText) ?> до 1-го места
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="segment-row__metrics">
                                <div class="segment-row__time"><?= e(format_elapsed($ownerTime)) ?></div>
                                <?php if ($avgSpeedKmh !== null): ?>
                                    <div class="segment-row__speed"><?= number_format($avgSpeedKmh, 1, '.', '') ?> км/ч</div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="activity-card">
            <div class="activity-view__actions">
                <?php if ($me): ?>
                    <button type="button"
                            class="action js-like-btn <?= $likeInfo['liked'] ? 'is-active' : '' ?>"
                            data-activity-id="<?= (int)$activityId ?>">
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
                    <button type="button" class="action js-likers-btn" data-activity-id="<?= (int)$activityId ?>">
                        <span class="action__icon">👥</span>
                        <span>Кто лайкнул</span>
                    </button>
                <?php endif; ?>

                <a class="action" href="#comments">
                    <span class="action__icon">💬</span>
                    <span><?= count($comments) ?></span>
                </a>
            </div>
        </div>

        <div class="activity-card" id="comments">
            <h2 class="activity-card__title">Комментарии (<?= count($comments) ?>)</h2>

            <?php
            if (!function_exists('render_activity_comment')) {
                function render_activity_comment(array $c, array $tree, bool $isOwner, ?array $me, int $depth = 0): void
                {
                    $canDelete = $me && ((int)$me['id'] === (int)$c['user_id'] || $isOwner);
                    $children  = $tree[(int)$c['id']] ?? [];
                    ?>
                    <div class="comment <?= $depth > 0 ? 'comment--reply' : '' ?>"
                         id="comment-<?= (int)$c['id'] ?>"
                         data-comment-id="<?= (int)$c['id'] ?>">
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
                                <?php if ($me): ?>
                                    <button type="button"
                                            class="comment__reply js-reply-btn"
                                            data-comment-id="<?= (int)$c['id'] ?>"
                                            data-display-name="<?= e($c['display_name']) ?>"
                                            title="Ответить">↩ Ответить</button>
                                <?php endif; ?>
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

                            <?php if ($children): ?>
                                <div class="comment__children">
                                    <?php foreach ($children as $child): ?>
                                        <?php render_activity_comment($child, $tree, $isOwner, $me, $depth + 1); ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
            }

            $tree = [];
            foreach ($comments as $c) {
                $pid = isset($c['parent_id']) && $c['parent_id'] !== null ? (int)$c['parent_id'] : 0;
                $tree[$pid][] = $c;
            }
            $roots = $tree[0] ?? [];
            ?>

            <div class="comments">
                <?php if (!$roots): ?>
                    <div class="empty" style="padding:24px">
                        <p class="muted">Пока нет комментариев.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($roots as $c): ?>
                        <?php render_activity_comment($c, $tree, $isOwner, $me); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($me): ?>
                <form method="post" class="comment-form" id="activity-comment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="comment">
                    <input type="hidden" name="parent_id" id="comment-parent-id" value="">
                    <div class="comment-form__reply-hint" id="comment-reply-hint" hidden>
                        Ответ на <strong id="comment-reply-name"></strong>
                        <button type="button" class="comment-form__reply-cancel" id="comment-reply-cancel" aria-label="Отменить ответ">×</button>
                    </div>
                    <textarea name="body" rows="2" placeholder="Написать комментарий..." required maxlength="1000"></textarea>
                    <button class="btn btn--primary">Отправить</button>
                </form>
            <?php else: ?>
                <div class="activity-view__login-cta">
                    <a href="<?= e(url('login.php')) ?>" class="btn btn--primary">Войдите</a>,
                    чтобы оставить комментарий.
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ============================================================
     МОДАЛКА ОБРЕЗКИ ТРЕКА (только владельцу, если есть трек)
     Важно: сначала передаём данные в JS, потом разметка, потом скрипт.
     ============================================================ -->
<?php if ($isOwner && $track): ?>
<script>
window.__ACTIVITY_TRIM__ = {
    activityId: <?= (int)$activityId ?>,
    apiUrl: <?= json_encode(url('api/activity-trim.php')) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
};
window.__ACTIVITY_TRACK__ = <?= json_encode($track, JSON_UNESCAPED_UNICODE) ?>;
</script>

<div class="trim-modal" id="trim-modal" hidden>
    <div class="trim-modal__backdrop" data-trim-close></div>
    <div class="trim-modal__panel">

        <div class="trim-modal__head">
            <h2 class="trim-modal__title">✂️ Обрезать трек</h2>
            <button type="button" class="trim-modal__close" data-trim-close aria-label="Закрыть">×</button>
        </div>

        <div class="trim-modal__map">
            <div id="trim-map"></div>
        </div>

        <div class="trim-modal__chart">
            <canvas id="trim-chart"></canvas>
        </div>

        <div class="trim-modal__controls">
            <div class="trim-slider">
                <div class="trim-slider__track">
                    <div class="trim-slider__fill" id="trim-fill"></div>
                </div>
                <input type="range" id="trim-start" class="trim-slider__input trim-slider__input--start"
                       min="0" max="<?= count($track) - 1 ?>" value="0" step="1">
                <input type="range" id="trim-end" class="trim-slider__input trim-slider__input--end"
                       min="0" max="<?= count($track) - 1 ?>" value="<?= count($track) - 1 ?>" step="1">
            </div>

            <div class="trim-info">
                <div class="trim-info__col">
                    <div class="trim-info__label">Начало</div>
                    <div class="trim-info__value" id="trim-start-label">точка 1</div>
                </div>
                <div class="trim-info__col">
                    <div class="trim-info__label">Конец</div>
                    <div class="trim-info__value" id="trim-end-label">точка <?= count($track) ?></div>
                </div>
            </div>

            <div class="trim-stats">
                <div class="trim-stat">
                    <div class="trim-stat__value" id="trim-dist">—</div>
                    <div class="trim-stat__label">Дистанция</div>
                </div>
                <div class="trim-stat">
                    <div class="trim-stat__value" id="trim-time">—</div>
                    <div class="trim-stat__label">Время</div>
                </div>
                <div class="trim-stat">
                    <div class="trim-stat__value" id="trim-speed">—</div>
                    <div class="trim-stat__label">Средняя</div>
                </div>
                <div class="trim-stat">
                    <div class="trim-stat__value" id="trim-elev">—</div>
                    <div class="trim-stat__label">Набор</div>
                </div>
            </div>
        </div>

        <div class="trim-modal__footer">
            <button type="button" class="btn btn--ghost" data-trim-close>Отмена</button>
            <button type="button" class="btn btn--primary" id="trim-save">💾 Сохранить</button>
        </div>
    </div>
</div>

<script src="<?= e(url('assets/js/activity-trim.js')) ?>"></script>
<?php endif; ?>

<!-- ============================================================
     ЛАЙТБОКС ФОТО
     ============================================================ -->
<?php if ($activityPhotos): ?>
<script>
(function () {
    "use strict";

    var photos = <?= json_encode(array_map(function ($p) { return $p['url']; }, $activityPhotos)) ?>;
    if (!photos.length) return;

    var lb         = document.getElementById('lightbox');
    var lbImg      = document.getElementById('lightbox-img');
    var lbCounter  = document.getElementById('lightbox-counter');
    var btnClose   = document.getElementById('lightbox-close');
    var btnPrev    = document.getElementById('lightbox-prev');
    var btnNext    = document.getElementById('lightbox-next');

    if (!lb || !lbImg || !btnClose || !btnPrev || !btnNext) return;

    var current = 0;
    var isOpen  = false;

    function show(index) {
        if (index < 0) index = photos.length - 1;
        if (index >= photos.length) index = 0;
        current = index;
        lbImg.src = photos[index];
        if (lbCounter) {
            lbCounter.textContent = (index + 1) + ' / ' + photos.length;
        }
    }

    function open(index) {
        if (isOpen) return;
        isOpen = true;
        show(index);
        lb.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function close() {
        if (!isOpen) return;
        isOpen = false;
        lb.hidden = true;
        document.body.style.overflow = '';
        lbImg.src = '';
    }

    document.addEventListener('click', function (e) {
        var item = e.target.closest('.activity-gallery__item');
        if (!item) return;
        e.preventDefault();
        e.stopPropagation();
        var idx = parseInt(item.dataset.index, 10) || 0;
        open(idx);
    });

    btnClose.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        close();
    });

    btnPrev.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        show(current - 1);
    });

    btnNext.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        show(current + 1);
    });

    lb.addEventListener('click', function (e) {
        if (e.target === lb) close();
    });

    document.addEventListener('keydown', function (e) {
        if (!isOpen) return;
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

<script>
/* ============================================================
   ОТВЕТЫ НА КОММЕНТАРИИ
   ============================================================ */
(function () {
    "use strict";
    var form = document.getElementById("activity-comment-form");
    if (!form) return;

    var parentInput = document.getElementById("comment-parent-id");
    var hint        = document.getElementById("comment-reply-hint");
    var hintName    = document.getElementById("comment-reply-name");
    var cancelBtn   = document.getElementById("comment-reply-cancel");
    var textarea    = form.querySelector("textarea[name=body]");

    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-reply-btn");
        if (!btn) return;
        e.preventDefault();
        parentInput.value = btn.dataset.commentId;
        hintName.textContent = btn.dataset.displayName || "";
        hint.hidden = false;
        textarea.focus();
        textarea.placeholder = "Ответ " + (btn.dataset.displayName || "") + "...";
    });

    if (cancelBtn) {
        cancelBtn.addEventListener("click", function () {
            parentInput.value = "";
            hint.hidden = true;
            textarea.placeholder = "Написать комментарий...";
        });
    }
})();

/* ============================================================
   ЗАГРУЗКА ФОТО
   ============================================================ */
(function () {
    "use strict";
    var input = document.getElementById("activity-photo-input");
    if (!input) return;

    var progress    = document.getElementById("activity-photo-progress");
    var progressBar = document.getElementById("activity-photo-progress-bar");
    var statusEl    = document.getElementById("activity-photo-status");

    var csrf       = <?= json_encode(csrf_token()) ?>;
    var apiUrl     = <?= json_encode(url('api/add-activity-photo.php')) ?>;
    var activityId = <?= (int)$activityId ?>;

    input.addEventListener("change", function () {
        var files = Array.from(input.files || []);
        if (!files.length) return;

        if (progress) progress.hidden = false;
        if (statusEl) { statusEl.hidden = false; statusEl.textContent = "Загрузка 0 / " + files.length; }
        if (progressBar) progressBar.style.width = "0%";

        var done = 0, okCount = 0, failCount = 0;

        function uploadNext(i) {
            if (i >= files.length) {
                if (statusEl) statusEl.textContent = "Готово: " + okCount + " загружено" + (failCount ? ", " + failCount + " с ошибкой" : "");
                if (progressBar) progressBar.style.width = "100%";
                setTimeout(function () { window.location.reload(); }, 600);
                return;
            }
            var fd = new FormData();
            fd.append("activity_id", activityId);
            fd.append("csrf", csrf);
            fd.append("file", files[i]);

            var xhr = new XMLHttpRequest();
            xhr.open("POST", apiUrl, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader("X-CSRF-Token", csrf);
            xhr.setRequestHeader("Accept", "application/json");

            xhr.upload.addEventListener("progress", function (e) {
                if (!e.lengthComputable || !progressBar) return;
                var overall = (i + e.loaded / e.total) / files.length;
                progressBar.style.width = Math.round(overall * 100) + "%";
            });
            xhr.addEventListener("load", function () {
                var res = null;
                try { res = JSON.parse(xhr.responseText); } catch (err) {}
                if (xhr.status >= 200 && xhr.status < 300 && res && res.ok) okCount++;
                else { failCount++; console.warn("Upload failed:", res && res.error); }
                done++;
                if (statusEl) statusEl.textContent = "Загрузка " + done + " / " + files.length;
                uploadNext(i + 1);
            });
            xhr.addEventListener("error", function () {
                failCount++; done++;
                if (statusEl) statusEl.textContent = "Ошибка сети: " + done + " / " + files.length;
                uploadNext(i + 1);
            });
            xhr.send(fd);
        }
        uploadNext(0);
        input.value = "";
    });
})();

/* ============================================================
   УДАЛЕНИЕ ФОТО
   ============================================================ */
(function () {
    "use strict";
    var apiUrl     = <?= json_encode(url('api/activity-photo-delete.php')) ?>;
    var csrf       = <?= json_encode(csrf_token()) ?>;
    var activityId = <?= (int)$activityId ?>;

    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-photo-delete");
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        if (!confirm("Удалить это фото?")) return;

        fetch(apiUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf, "Accept": "application/json" },
            body: JSON.stringify({ photo_id: btn.dataset.photoId, activity_id: activityId })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.ok) { alert((res && res.error) || "Ошибка удаления"); return; }
            var wrap = btn.closest(".activity-gallery__wrap");
            if (wrap) wrap.remove();
        })
        .catch(function () { alert("Ошибка сети"); });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
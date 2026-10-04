<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/wahoo-client.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../services/FitParser.php';
require_once __DIR__ . '/../services/SegmentMatcher.php';

api_check_csrf();
$me = api_require_user();
$userId = (int)$me['id'];

$stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !wahoo_is_connected($user)) {
    json_err('Wahoo не подключён', 400);
}

$perPage = 10;
$page    = max(1, (int)($_POST['page'] ?? 1));

$res = wahoo_api_call($userId, 'GET', '/v1/workouts?page=' . $page . '&per_page=' . $perPage);

if ($res['status'] === 401) {
    json_err('Токен Wahoo истёк и не обновился. Переподключите Wahoo.', 401);
}
if ($res['status'] === 403) {
    json_err('403 Forbidden от Wahoo. Переподключите Wahoo.', 403);
}
if ($res['status'] === 429) {
    json_err('429 Too Many Requests. Подождите 5 минут.', 429);
}
if ($res['status'] !== 200 || !is_array($res['json'])) {
    json_err('Ошибка Wahoo API: HTTP ' . $res['status'], 502);
}

$workouts = $res['json']['workouts'] ?? [];
if (!is_array($workouts)) $workouts = [];

$created = 0;
$skipped = 0;
$errors  = 0;
$noTrack = 0;
$matched = 0;

foreach ($workouts as $w) {
    $wahooWorkoutId = (int)($w['id'] ?? 0);
    if ($wahooWorkoutId <= 0) continue;

    $check = db()->prepare(
        'SELECT activity_id FROM wahoo_synced_workouts
         WHERE user_id = ? AND wahoo_workout_id = ? LIMIT 1'
    );
    $check->execute([$userId, $wahooWorkoutId]);
    if ($check->fetchColumn()) {
        $skipped++;
        continue;
    }

    $detail = wahoo_api_call($userId, 'GET', '/v1/workouts/' . $wahooWorkoutId);

    if ($detail['status'] === 429) {
        log_to_file('wahoo.log', 'Hit 429 on detail #' . $wahooWorkoutId . ', stopping');
        break;
    }

    if ($detail['status'] !== 200 || !is_array($detail['json'])) {
        log_to_file('wahoo.log', sprintf(
            'Get workout detail failed for #%d: status=%d',
            $wahooWorkoutId,
            (int)$detail['status']
        ));
        $errors++;
        continue;
    }

    $detailData = $detail['json'];
    $summary    = $detailData['workout_summary'] ?? [];
    if (!is_array($summary)) $summary = [];

    $wahooTypeId = (int)($detailData['workout_type_id'] ?? 255);
    $pulseType = match (true) {
        in_array($wahooTypeId, [1, 3, 4, 5, 67, 71], true) => 'run',
        in_array($wahooTypeId, [0, 11, 12, 13, 14, 15, 16, 17, 49, 61, 64, 68, 70], true) => 'ride',
        in_array($wahooTypeId, [25, 26], true) => 'swim',
        in_array($wahooTypeId, [28, 29, 30], true) => 'ski',
        in_array($wahooTypeId, [6, 7, 8, 56], true) => 'walk',
        in_array($wahooTypeId, [9, 10], true) => 'hike',
        default => 'other',
    };

    $startedAt = $detailData['starts'] ?? null;
    if ($startedAt) {
        $startedAt = date('Y-m-d H:i:s', strtotime((string)$startedAt));
    }

    $durationSec = null;
    if (isset($summary['duration_total_accum'])) {
        $durationSec = (int)round((float)$summary['duration_total_accum']);
    } elseif (isset($detailData['minutes'])) {
        $durationSec = (int)$detailData['minutes'] * 60;
    }

    $distanceM  = isset($summary['distance_accum']) ? (float)$summary['distance_accum'] : null;
    $elevationM = isset($summary['ascent_accum'])   ? (float)$summary['ascent_accum']   : null;
    $avgSpeed   = isset($summary['speed_avg'])      ? (float)$summary['speed_avg']      : null;
    $avgHr      = isset($summary['heart_rate_avg']) ? (int)round((float)$summary['heart_rate_avg']) : null;
    $avgCad     = isset($summary['cadence_avg'])    ? (int)round((float)$summary['cadence_avg'])    : null;
    $avgPwr     = isset($summary['power_avg'])      ? (int)round((float)$summary['power_avg'])      : null;
    $calories   = isset($summary['calories_accum']) ? (int)round((float)$summary['calories_accum']) : null;

    $trackJson = null;
    $fitUrl    = $summary['file']['url'] ?? null;

    if (empty($fitUrl)) {
        $noTrack++;
    } else {
        $fitData = @file_get_contents($fitUrl);

        if ($fitData === false || strlen($fitData) === 0) {
            $noTrack++;
        } else {
            $tmpFit = sys_get_temp_dir() . '/wahoo_' . $wahooWorkoutId . '_' . bin2hex(random_bytes(4)) . '.fit';

            if (@file_put_contents($tmpFit, $fitData) !== false) {
                try {
                    $parsed = FitParser::parse($tmpFit);

                    if (!empty($parsed['points'])) {
                        $trackJson = json_encode($parsed['points'], JSON_UNESCAPED_UNICODE);
                    } else {
                        $noTrack++;
                    }

                    $durationSec = $durationSec ?? ($parsed['duration_sec'] ?? null);
                    $distanceM   = $distanceM   ?? ($parsed['distance_m']   ?? null);
                    $elevationM  = $elevationM  ?? ($parsed['elevation_gain_m'] ?? null);
                    $avgSpeed    = $avgSpeed    ?? ($parsed['avg_speed_mps'] ?? null);
                    $avgHr       = $avgHr       ?? ($parsed['avg_hr']        ?? null);
                    $avgCad      = $avgCad      ?? ($parsed['avg_cadence']   ?? null);
                    $avgPwr      = $avgPwr      ?? ($parsed['avg_power_w']   ?? null);
                } catch (Throwable $e) {
                    log_to_file('wahoo.log', 'FIT parse failed for #' . $wahooWorkoutId . ': ' . $e->getMessage());
                    $noTrack++;
                } finally {
                    @unlink($tmpFit);
                }
            } else {
                $noTrack++;
            }
        }
    }

    $title = trim((string)($detailData['name'] ?? ''));
    if ($title === '') $title = 'Wahoo workout #' . $wahooWorkoutId;

    try {
        $activityId = Activity::create($userId, [
            'type'             => $pulseType,
            'title'            => $title,
            'description'      => 'Синхронизировано из Wahoo',
            'started_at'       => $startedAt,
            'duration_sec'     => $durationSec,
            'distance_m'       => $distanceM,
            'elevation_gain_m' => $elevationM,
            'avg_speed_mps'    => $avgSpeed,
            'max_speed_mps'    => null,
            'calories'         => $calories,
            'gear_id'          => null,
            'track_json'       => $trackJson,
            'visibility'       => 'public',
            'avg_hr'           => $avgHr,
            'max_hr'           => null,
            'avg_cadence'      => $avgCad,
            'max_cadence'      => null,
            'avg_power_w'      => $avgPwr,
            'max_power_w'      => null,
            'avg_temp_c'       => null,
            'has_sensors'      => ($avgHr !== null || $avgPwr !== null || $avgCad !== null) ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        log_to_file('wahoo.log', 'Activity create failed for #' . $wahooWorkoutId . ': ' . $e->getMessage());
        $errors++;
        continue;
    }

    // ---- Матчинг по всем публичным сегментам (включая чужие) ----
    if ($trackJson !== null) {
        try {
            $m = SegmentMatcher::matchAllForActivity($activityId);
            $matched += (int)($m['matched'] ?? 0);
        } catch (Throwable $e) {
            log_to_file('segment-match.log', 'Wahoo match failed for #' . $activityId . ': ' . $e->getMessage());
        }
    }

    try {
        db()->prepare(
            'INSERT INTO wahoo_synced_workouts (user_id, wahoo_workout_id, activity_id)
             VALUES (?, ?, ?)'
        )->execute([$userId, $wahooWorkoutId, $activityId]);
    } catch (Throwable $e) {
        try { Activity::delete($activityId, $userId); } catch (Throwable $e2) {}
        $errors++;
        continue;
    }

    $created++;
}

json_ok([
    'created'   => $created,
    'skipped'   => $skipped,
    'errors'    => $errors,
    'no_track'  => $noTrack,
    'matched'   => $matched,
    'page'      => $page,
    'has_more'  => count($workouts) >= $perPage,
]);
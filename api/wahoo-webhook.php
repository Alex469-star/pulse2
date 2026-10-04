<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../services/FitParser.php';
require_once __DIR__ . '/../services/SegmentMatcher.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

log_to_file('wahoo-webhook.log', 'Payload: ' . substr($raw, 0, 4000));

$type = $payload['event_type'] ?? '';

if ($type !== 'workout_summary') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => 'not_workout_summary']);
    exit;
}

$webhookToken = (string)($payload['webhook_token'] ?? '');
$expected = (string)config('wahoo_webhook_token');
if ($expected !== '' && !hash_equals($expected, $webhookToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_token']);
    exit;
}

$wahooUserId = (int)($payload['user']['id'] ?? 0);
$summary     = $payload['workout_summary'] ?? null;

if ($wahooUserId <= 0 || !is_array($summary)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

try {
    $stmt = db()->prepare('SELECT * FROM users WHERE wahoo_user_id = ? LIMIT 1');
    $stmt->execute([$wahooUserId]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

if (!$user) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => 'user_not_linked']);
    exit;
}

$workout   = $summary['workout'] ?? [];
$workoutId = (int)($workout['id'] ?? 0);

if ($workoutId <= 0) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => 'no_workout_id']);
    exit;
}

try {
    $chk = db()->prepare(
        'SELECT activity_id FROM wahoo_synced_workouts
         WHERE user_id = ? AND wahoo_workout_id = ? LIMIT 1'
    );
    $chk->execute([(int)$user['id'], $workoutId]);
    if ($chk->fetchColumn()) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'skipped' => 'already_synced']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

$startedAt = $workout['starts'] ?? null;
if ($startedAt) {
    $startedAt = date('Y-m-d H:i:s', strtotime((string)$startedAt));
}

$durationSec = isset($summary['duration_total_accum']) ? (int)round((float)$summary['duration_total_accum']) : null;
$distanceM   = isset($summary['distance_accum'])        ? (float)$summary['distance_accum'] : null;
$elevationM  = isset($summary['ascent_accum'])          ? (float)$summary['ascent_accum']   : null;
$avgSpeed    = isset($summary['speed_avg'])             ? (float)$summary['speed_avg']      : null;
$avgHr       = isset($summary['heart_rate_avg'])        ? (int)round((float)$summary['heart_rate_avg']) : null;
$avgCad      = isset($summary['cadence_avg'])           ? (int)round((float)$summary['cadence_avg'])    : null;
$avgPwr      = isset($summary['power_avg'])             ? (int)round((float)$summary['power_avg'])      : null;
$calories    = isset($summary['calories_accum'])        ? (int)round((float)$summary['calories_accum']) : null;

$trackJson = null;
$fitUrl    = $summary['file']['url'] ?? null;

if (!empty($fitUrl)) {
    $fitData = @file_get_contents($fitUrl);

    if ($fitData !== false && strlen($fitData) > 0) {
        $tmpFit = sys_get_temp_dir() . '/wahoo_wh_' . $workoutId . '_' . bin2hex(random_bytes(4)) . '.fit';

        if (@file_put_contents($tmpFit, $fitData) !== false) {
            try {
                $parsed = FitParser::parse($tmpFit);

                if (!empty($parsed['points'])) {
                    $trackJson = json_encode($parsed['points'], JSON_UNESCAPED_UNICODE);
                }

                $durationSec = $durationSec ?? ($parsed['duration_sec'] ?? null);
                $distanceM   = $distanceM   ?? ($parsed['distance_m']   ?? null);
                $elevationM  = $elevationM  ?? ($parsed['elevation_gain_m'] ?? null);
                $avgSpeed    = $avgSpeed    ?? ($parsed['avg_speed_mps'] ?? null);
                $avgHr       = $avgHr       ?? ($parsed['avg_hr']        ?? null);
                $avgCad      = $avgCad      ?? ($parsed['avg_cadence']   ?? null);
                $avgPwr      = $avgPwr      ?? ($parsed['avg_power_w']   ?? null);
            } catch (Throwable $e) {
                log_to_file('wahoo-webhook.log', 'FIT parse failed for #' . $workoutId . ': ' . $e->getMessage());
            } finally {
                @unlink($tmpFit);
            }
        }
    }
}

$wahooTypeId = (int)($workout['workout_type_id'] ?? 255);
$pulseType = match (true) {
    in_array($wahooTypeId, [1, 3, 4, 5, 67, 71], true) => 'run',
    in_array($wahooTypeId, [0, 11, 12, 13, 14, 15, 16, 17, 49, 61, 64, 68, 70], true) => 'ride',
    in_array($wahooTypeId, [25, 26], true) => 'swim',
    in_array($wahooTypeId, [28, 29, 30], true) => 'ski',
    in_array($wahooTypeId, [6, 7, 8, 56], true) => 'walk',
    in_array($wahooTypeId, [9, 10], true) => 'hike',
    default => 'other',
};

$title = trim((string)($workout['name'] ?? ''));
if ($title === '') $title = 'Wahoo workout #' . $workoutId;

try {
    $activityId = Activity::create((int)$user['id'], [
        'type'             => $pulseType,
        'title'            => $title,
        'description'      => 'Синхронизировано из Wahoo (webhook)',
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
    log_to_file('wahoo-webhook.log', 'Save failed for #' . $workoutId . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit;
}

// ---- Матчинг по всем публичным сегментам ----
if ($trackJson !== null) {
    try {
        SegmentMatcher::matchAllForActivity($activityId);
    } catch (Throwable $e) {
        log_to_file('wahoo-webhook.log', 'Segment match failed for #' . $activityId . ': ' . $e->getMessage());
    }
}

try {
    db()->prepare(
        'INSERT INTO wahoo_synced_workouts (user_id, wahoo_workout_id, activity_id)
         VALUES (?, ?, ?)'
    )->execute([(int)$user['id'], $workoutId, $activityId]);

    http_response_code(200);
    echo json_encode(['ok' => true, 'activity_id' => $activityId]);
} catch (Throwable $e) {
    log_to_file('wahoo-webhook.log', 'Sync record failed for #' . $workoutId . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'sync_record_failed']);
}
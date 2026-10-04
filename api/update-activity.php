<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../models/Gear.php';

api_check_csrf();
$me = api_require_user();

$activityId = (int)($_POST['activity_id'] ?? 0);
if ($activityId <= 0) {
    json_err('Не указан ID активности', 400);
}

$activity = Activity::findById($activityId);
if (!$activity || (int)$activity['user_id'] !== (int)$me['id']) {
    json_err('Активность не найдена или недоступна', 403);
}

$title       = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$type        = (string)($_POST['type'] ?? 'run');
$visibility  = (string)($_POST['visibility'] ?? 'public');
$gearId      = (string)($_POST['gear_id'] ?? '');

if ($title === '') {
    json_err('Введите название', 400);
}
if (mb_strlen($title) > 190) {
    json_err('Максимум 190 символов в названии', 400);
}
if (mb_strlen($description) > 2000) {
    json_err('Максимум 2000 символов в описании', 400);
}

$allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
if (!in_array($type, $allowedTypes, true)) $type = 'run';

$allowedVis = ['public','followers','private'];
if (!in_array($visibility, $allowedVis, true)) $visibility = 'public';

// ---- Дата/время ----
$startedDate = (string)($_POST['started_date'] ?? '');
$startedTime = (string)($_POST['started_time'] ?? '00:00');
$startedAt = null;
if ($startedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startedDate)) {
    if (!preg_match('/^\d{2}:\d{2}$/', $startedTime)) $startedTime = '00:00';
    $ts = strtotime($startedDate . ' ' . $startedTime . ':00');
    if ($ts !== false && $ts <= time() + 86400) {
        $startedAt = date('Y-m-d H:i:s', $ts);
    }
}

// ---- Числовые поля ----
function num_or_null($v, float $min, float $max): ?float
{
    if ($v === null || $v === '') return null;
    if (!is_numeric($v)) return null;
    $x = (float)$v;
    if ($x < $min || $x > $max) return null;
    return $x;
}

$distanceKmF = num_or_null($_POST['distance_km'] ?? '', 0, 1000);
$distanceM   = $distanceKmF !== null ? $distanceKmF * 1000 : null;

$elevationMf = num_or_null($_POST['elevation_gain_m'] ?? '', 0, 10000);
$caloriesI   = num_or_null($_POST['calories'] ?? '', 0, 10000);
$avgHrI      = num_or_null($_POST['avg_hr'] ?? '', 0, 250);
$maxHrI      = num_or_null($_POST['max_hr'] ?? '', 0, 250);
$avgCadI     = num_or_null($_POST['avg_cadence'] ?? '', 0, 300);
$avgPwrI     = num_or_null($_POST['avg_power_w'] ?? '', 0, 2500);

// ---- Длительность ----
$durationSec = null;
$durationHms = trim((string)($_POST['duration_hms'] ?? ''));
if ($durationHms !== '') {
    $parts = array_map('intval', explode(':', $durationHms));
    if (count($parts) === 2) {
        $durationSec = $parts[0] * 60 + $parts[1];
    } elseif (count($parts) === 3) {
        $durationSec = $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
    }
    if ($durationSec !== null && ($durationSec <= 0 || $durationSec > 86400 * 7)) {
        $durationSec = null;
    }
}

// ---- Средняя скорость ----
$avgSpeed = null;
if ($distanceM !== null && $durationSec !== null && $durationSec > 0) {
    $avgSpeed = $distanceM / $durationSec;
}

// ---- Инвентарь ----
$gearIdToSave = null;
if ($gearId !== '') {
    $g = Gear::findById((int)$gearId);
    if ($g && (int)$g['user_id'] === (int)$me['id']) {
        $gearIdToSave = (int)$g['id'];
    }
}

$hasSensors = ($avgHrI !== null || $maxHrI !== null || $avgCadI !== null || $avgPwrI !== null) ? 1 : 0;

// ---- Обновляем ----
try {
    db()->prepare(
        'UPDATE activities
         SET title = :title,
             description = :description,
             type = :type,
             visibility = :visibility,
             gear_id = :gear_id,
             started_at = :started_at,
             duration_sec = :duration_sec,
             distance_m = :distance_m,
             elevation_gain_m = :elevation_gain_m,
             avg_speed_mps = :avg_speed_mps,
             calories = :calories,
             avg_hr = :avg_hr,
             max_hr = :max_hr,
             avg_cadence = :avg_cadence,
             avg_power_w = :avg_power_w,
             has_sensors = :has_sensors
         WHERE id = :id AND user_id = :uid'
    )->execute([
        ':title'             => $title,
        ':description'       => $description !== '' ? $description : null,
        ':type'              => $type,
        ':visibility'        => $visibility,
        ':gear_id'           => $gearIdToSave,
        ':started_at'        => $startedAt ?? $activity['started_at'],
        ':duration_sec'      => $durationSec !== null ? (int)$durationSec : null,
        ':distance_m'        => $distanceM,
        ':elevation_gain_m'  => $elevationMf,
        ':avg_speed_mps'     => $avgSpeed,
        ':calories'          => $caloriesI !== null ? (int)$caloriesI : null,
        ':avg_hr'            => $avgHrI !== null ? (int)$avgHrI : null,
        ':max_hr'            => $maxHrI !== null ? (int)$maxHrI : null,
        ':avg_cadence'       => $avgCadI !== null ? (int)$avgCadI : null,
        ':avg_power_w'       => $avgPwrI !== null ? (int)$avgPwrI : null,
        ':has_sensors'       => $hasSensors,
        ':id'                => $activityId,
        ':uid'               => (int)$me['id'],
    ]);
} catch (Throwable $ex) {
    json_err('Не удалось сохранить: ' . $ex->getMessage(), 500);
}

json_ok([
    'activity_id' => $activityId,
    'title'       => $title,
]);
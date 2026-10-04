<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../models/Gear.php';

api_check_csrf();
$me = api_require_user();

$title       = trim((string)($_POST['title'] ?? ''));
$type        = (string)($_POST['type'] ?? 'run');
$visibility  = (string)($_POST['visibility'] ?? 'public');
$startedDate = (string)($_POST['started_date'] ?? '');
$startedTime = (string)($_POST['started_time'] ?? '00:00');
$durationHms = trim((string)($_POST['duration_hms'] ?? ''));
$distanceKm  = (string)($_POST['distance_km'] ?? '');
$elevationM  = (string)($_POST['elevation_gain_m'] ?? '');
$calories    = (string)($_POST['calories'] ?? '');
$avgHr       = (string)($_POST['avg_hr'] ?? '');
$maxHr       = (string)($_POST['max_hr'] ?? '');
$avgCad      = (string)($_POST['avg_cadence'] ?? '');
$avgPwr      = (string)($_POST['avg_power_w'] ?? '');
$description = trim((string)($_POST['description'] ?? ''));
$gearId      = (int)($_POST['gear_id'] ?? 0);

// ---- Валидация ----
if ($title === '') {
    json_err('Введите название', 400);
}
if (mb_strlen($title) > 190) {
    json_err('Название — максимум 190 символов', 400);
}

$allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
if (!in_array($type, $allowedTypes, true)) $type = 'run';

$allowedVis = ['public','followers','private'];
if (!in_array($visibility, $allowedVis, true)) $visibility = 'public';

if ($startedDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startedDate)) {
    json_err('Укажите дату тренировки', 400);
}
if (!preg_match('/^\d{2}:\d{2}$/', $startedTime)) {
    $startedTime = '00:00';
}

$startedAt = $startedDate . ' ' . $startedTime . ':00';
$ts = strtotime($startedAt);
if ($ts === false || $ts > time() + 86400) {
    json_err('Дата не может быть в будущем', 400);
}
$startedAt = date('Y-m-d H:i:s', $ts);

// ---- Парсинг длительности ----
$durationSec = null;
if ($durationHms !== '') {
    $parts = array_map('intval', explode(':', $durationHms));
    if (count($parts) === 2) {
        $durationSec = $parts[0] * 60 + $parts[1];
    } elseif (count($parts) === 3) {
        $durationSec = $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
    }
    if ($durationSec !== null && $durationSec <= 0) $durationSec = null;
    if ($durationSec !== null && $durationSec > 86400 * 7) {
        json_err('Длительность не может быть больше 7 дней', 400);
    }
}

// ---- Числовые поля ----
function manual_num(?string $s, float $min, float $max): ?float
{
    if ($s === null || $s === '') return null;
    if (!is_numeric($s)) return null;
    $v = (float)$s;
    if ($v < $min || $v > $max) return null;
    return $v;
}

$distanceM = null;
$distanceKmF = manual_num($distanceKm, 0, 1000);
if ($distanceKmF !== null) $distanceM = $distanceKmF * 1000;

$elevationMf = manual_num($elevationM, 0, 10000);
$caloriesI   = manual_num($calories, 0, 10000);
$avgHrI      = manual_num($avgHr, 0, 250);
$maxHrI      = manual_num($maxHr, 0, 250);
$avgCadI     = manual_num($avgCad, 0, 300);
$avgPwrI     = manual_num($avgPwr, 0, 2500);

// Средняя скорость — если есть дистанция и время
$avgSpeed = null;
if ($distanceM !== null && $durationSec !== null && $durationSec > 0) {
    $avgSpeed = $distanceM / $durationSec;
}

// Инвентарь
$gear = null;
if ($gearId > 0) {
    $g = Gear::findById($gearId);
    if ($g && (int)$g['user_id'] === (int)$me['id']) {
        $gear = $gearId;
    }
}

// has_sensors — есть ли хоть какие-то метрики с датчиков
$hasSensors = ($avgHrI !== null || $maxHrI !== null || $avgCadI !== null || $avgPwrI !== null) ? 1 : 0;

// ---- Создаём ----
try {
    $activityId = Activity::create((int)$me['id'], [
        'type'             => $type,
        'title'            => $title,
        'description'      => $description !== '' ? $description : null,
        'started_at'       => $startedAt,
        'duration_sec'     => $durationSec !== null ? (int)$durationSec : null,
        'distance_m'       => $distanceM,
        'elevation_gain_m' => $elevationMf,
        'avg_speed_mps'    => $avgSpeed,
        'max_speed_mps'    => null,
        'calories'         => $caloriesI !== null ? (int)$caloriesI : null,
        'gear_id'          => $gear,
        'track_json'       => null,
        'visibility'       => $visibility,
        'avg_hr'           => $avgHrI !== null ? (int)$avgHrI : null,
        'max_hr'           => $maxHrI !== null ? (int)$maxHrI : null,
        'avg_cadence'      => $avgCadI !== null ? (int)$avgCadI : null,
        'max_cadence'      => null,
        'avg_power_w'      => $avgPwrI !== null ? (int)$avgPwrI : null,
        'max_power_w'      => null,
        'avg_temp_c'       => null,
        'has_sensors'      => $hasSensors,
    ]);
} catch (Throwable $ex) {
    json_err('Ошибка сохранения: ' . $ex->getMessage(), 500);
}

json_ok([
    'activity_id' => $activityId,
    'title'       => $title,
]);
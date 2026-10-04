<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../models/Gear.php';
require_once __DIR__ . '/../services/GpxParser.php';
require_once __DIR__ . '/../services/TcxParser.php';
require_once __DIR__ . '/../services/FitParser.php';
require_once __DIR__ . '/../services/SegmentMatcher.php';

api_check_csrf();
$me = api_require_user();

$allowedExt = ['gpx', 'tcx', 'fit'];
$maxSize    = 25 * 1024 * 1024;

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_err('Файл не был загружен', 400);
}

$file = $_FILES['file'];
$originalName = (string)($file['name'] ?? 'file');

if ((int)$file['size'] > $maxSize) {
    json_err('Файл больше 25 МБ', 400);
}

$ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    json_err('Недопустимое расширение: ' . $ext, 400);
}

if (!is_uploaded_file($file['tmp_name'])) {
    json_err('Файл не был загружен через HTTP', 400);
}

$title      = trim((string)($_POST['title'] ?? ''));
$type       = (string)($_POST['type'] ?? 'run');
$visibility = (string)($_POST['visibility'] ?? 'public');
$gearId     = (int)($_POST['gear_id'] ?? 0);
$isMulti    = !empty($_POST['is_multi']);

$allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
if (!in_array($type, $allowedTypes, true)) $type = 'run';

$allowedVisibility = ['public','followers','private'];
if (!in_array($visibility, $allowedVisibility, true)) $visibility = 'public';

$gear = null;
if ($gearId > 0) {
    $g = Gear::findById($gearId);
    if ($g && (int)$g['user_id'] === (int)$me['id']) {
        $gear = $gearId;
    }
}

try {
    $parsed = match ($ext) {
        'gpx' => GpxParser::parse($file['tmp_name']),
        'tcx' => TcxParser::parse($file['tmp_name']),
        'fit' => FitParser::parse($file['tmp_name']),
        default => throw new RuntimeException('Неподдерживаемый формат'),
    };
} catch (Throwable $ex) {
    json_err($ex->getMessage(), 400);
}

$points = $parsed['points'];
if (count($points) > 20000) {
    $step = (int)ceil(count($points) / 20000);
    $reduced = [];
    for ($i = 0; $i < count($points); $i += $step) {
        $reduced[] = $points[$i];
    }
    if (end($reduced) !== end($points)) {
        $reduced[] = end($points);
    }
    $points = $reduced;
}

$fileBase = pathinfo($originalName, PATHINFO_FILENAME);
$finalTitle = $title !== ''
    ? ($isMulti ? $title . ' — ' . $fileBase : $title)
    : $fileBase;
$finalTitle = mb_substr($finalTitle, 0, 190);

try {
    $activityId = Activity::create((int)$me['id'], [
        'type'             => $type,
        'title'            => $finalTitle,
        'description'      => null,
        'started_at'       => $parsed['started_at'],
        'duration_sec'     => $parsed['duration_sec'],
        'distance_m'       => $parsed['distance_m'],
        'elevation_gain_m' => $parsed['elevation_gain_m'],
        'avg_speed_mps'    => $parsed['avg_speed_mps'],
        'max_speed_mps'    => $parsed['max_speed_mps'],
        'gear_id'          => $gear,
        'track_json'       => json_encode($points, JSON_UNESCAPED_UNICODE),
        'visibility'       => $visibility,
        'avg_hr'           => $parsed['avg_hr']      ?? null,
        'max_hr'           => $parsed['max_hr']      ?? null,
        'avg_cadence'      => $parsed['avg_cadence'] ?? null,
        'max_cadence'      => $parsed['max_cadence'] ?? null,
        'avg_power_w'      => $parsed['avg_power_w'] ?? null,
        'max_power_w'      => $parsed['max_power_w'] ?? null,
        'avg_temp_c'       => $parsed['avg_temp_c']  ?? null,
        'has_sensors'      => $parsed['has_sensors'] ?? 0,
    ]);
} catch (Throwable $ex) {
    json_err('Ошибка сохранения: ' . $ex->getMessage(), 500);
}

// ---- Автоматический матчинг сегментов ----
$matched = 0;
try {
    $m = SegmentMatcher::matchAllForActivity($activityId);
    $matched = (int)($m['matched'] ?? 0);
} catch (Throwable $ex) {
    log_to_file('segment-match.log', sprintf(
        'Match failed for activity #%d: %s',
        $activityId,
        $ex->getMessage()
    ));
}

json_ok([
    'activity_id' => $activityId,
    'title'       => $finalTitle,
    'summary'     => [
        'distance_m'       => $parsed['distance_m'],
        'duration_sec'     => $parsed['duration_sec'],
        'elevation_gain_m' => $parsed['elevation_gain_m'],
        'points'           => count($points),
        'has_sensors'      => (int)($parsed['has_sensors'] ?? 0),
        'matched'          => $matched,
    ],
]);
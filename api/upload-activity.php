<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../models/Gear.php';
require_once __DIR__ . '/../services/GpxParser.php';
require_once __DIR__ . '/../services/TcxParser.php';
require_once __DIR__ . '/../services/FitParser.php';
require_once __DIR__ . '/../services/SegmentMatcher.php';
require_once __DIR__ . '/../services/ActivityTypeDetector.php';

api_check_csrf();
$me = api_require_user();

// ============================================================
// ТАЙМИНГ (временно, для отладки)
// ============================================================
$__t = [];
$__t0 = microtime(true);
$__mark = static function (string $name) use (&$__t, $__t0): void {
    $__t[$name] = microtime(true) - $__t0;
};
$__dump = static function (string $label, array $extra = []) use (&$__t, $__t0): void {
    $total = microtime(true) - $__t0;
    $parts = [];
    $prev = 0.0;
    foreach ($__t as $k => $v) {
        $parts[] = sprintf('%s=%.3f', $k, $v - $prev);
        $prev = $v;
    }
    $parts[] = sprintf('TOTAL=%.3f', $total);
    $line = $label . ' | ' . implode(' ', $parts);
    if ($extra) {
        $line .= ' | ' . json_encode($extra, JSON_UNESCAPED_UNICODE);
    }
    log_to_file('timing.log', $line);
};
// ============================================================

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

$__mark('start');

$title      = trim((string)($_POST['title'] ?? ''));
$rawType    = (string)($_POST['type'] ?? 'auto');
$visibility = (string)($_POST['visibility'] ?? 'public');
$gearId     = (int)($_POST['gear_id'] ?? 0);
$isMulti    = !empty($_POST['is_multi']);

$allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
$manualType = in_array($rawType, $allowedTypes, true) ? $rawType : null;

$allowedVisibility = ['public','followers','private'];
if (!in_array($visibility, $allowedVisibility, true)) $visibility = 'public';

$gear = null;
if ($gearId > 0) {
    $g = Gear::findById($gearId);
    if ($g && (int)$g['user_id'] === (int)$me['id']) {
        $gear = $gearId;
    }
}

$__mark('prepare');

// ---- Парсинг ----
try {
    $parsed = match ($ext) {
        'gpx' => GpxParser::parse($file['tmp_name']),
        'tcx' => TcxParser::parse($file['tmp_name']),
        'fit' => FitParser::parse($file['tmp_name']),
        default => throw new RuntimeException('Неподдерживаемый формат'),
    };
} catch (Throwable $ex) {
    $__mark('parse_fail');
    $__dump('FAIL', [
        'file'  => $originalName,
        'size'  => (int)$file['size'],
        'ext'   => $ext,
        'error' => $ex->getMessage(),
    ]);
    json_err($ex->getMessage(), 400);
}

$__mark('parse');

// ---- Автодетект типа ----
$detectStart = microtime(true);
if ($manualType === null) {
    $type = ActivityTypeDetector::detect(
        $parsed['source_type'] ?? null,
        $parsed,
        $originalName
    );
} else {
    $type = $manualType;
}
$detectMs = (microtime(true) - $detectStart) * 1000;

$__mark('detect');

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

$__mark('reduce');

$fileBase = pathinfo($originalName, PATHINFO_FILENAME);
$finalTitle = $title !== ''
    ? ($isMulti ? $title . ' — ' . $fileBase : $title)
    : $fileBase;
$finalTitle = mb_substr($finalTitle, 0, 190);

// ---- Отпечаток и хэш ----
$fingerprint = Activity::fingerprint([
    'type'         => $type,
    'started_at'   => $parsed['started_at'],
    'distance_m'   => $parsed['distance_m'],
    'duration_sec' => $parsed['duration_sec'],
]);

$__mark('fingerprint');

$trackJsonEncoded = json_encode($points, JSON_UNESCAPED_UNICODE);
$trackHash = Activity::trackHash($trackJsonEncoded);

$__mark('trackhash');

// ---- Проверка на дубликат ----
$duplicate = Activity::findDuplicate((int)$me['id'], $fingerprint, $trackHash);
if ($duplicate !== null) {
    $dup = $duplicate['activity'];
    $reason = $duplicate['reason'];
    $when = $dup['started_at']
        ? date('d.m.Y H:i', strtotime((string)$dup['started_at']))
        : '—';

    $msg = 'Такая активность уже загружена (#'
         . (int)$dup['id'] . ' от ' . $when
         . ($reason === 'track_hash' ? ', трек совпадает' : '')
         . ')';

    $__mark('duplicate');
    $__dump('DUPLICATE', [
        'file'      => $originalName,
        'size'      => (int)$file['size'],
        'type'      => $type,
        'detect_ms' => round($detectMs, 2),
        'points'    => count($points),
        'track_kb'  => round(strlen($trackJsonEncoded) / 1024),
    ]);

    json_err($msg, 409, [
        'duplicate_id'  => (int)$dup['id'],
        'duplicate_url' => url('activity.php?id=' . (int)$dup['id']),
    ]);
}

$__mark('duplicate_check');

// ---- Сохранение ----
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
        'track_json'       => $trackJsonEncoded,
        'visibility'       => $visibility,
        'fingerprint'      => $fingerprint,
        'track_hash'       => $trackHash,
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
    $__mark('create_fail');
    $__dump('CREATE_FAIL', [
        'file'     => $originalName,
        'size'     => (int)$file['size'],
        'type'     => $type,
        'points'   => count($points),
        'track_kb' => round(strlen($trackJsonEncoded) / 1024),
        'error'    => $ex->getMessage(),
    ]);
    json_err('Ошибка сохранения: ' . $ex->getMessage(), 500);
}

$__mark('create');



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

$__mark('segments');

$__dump('OK', [
    'file'      => $originalName,
    'size'      => (int)$file['size'],
    'ext'       => $ext,
    'type'      => $type,
    'manual'    => $manualType !== null ? $manualType : 'auto',
    'detect_ms' => round($detectMs, 2),
    'points'    => count($points),
    'track_kb'  => round(strlen($trackJsonEncoded) / 1024),
    'matched'   => $matched,
]);

json_ok([
    'activity_id' => $activityId,
    'title'       => $finalTitle,
    'detected_type' => $type,
    'summary'     => [
        'distance_m'       => $parsed['distance_m'],
        'duration_sec'     => $parsed['duration_sec'],
        'elevation_gain_m' => $parsed['elevation_gain_m'],
        'points'           => count($points),
        'has_sensors'      => (int)($parsed['has_sensors'] ?? 0),
        'matched'          => $matched,
    ],
]);
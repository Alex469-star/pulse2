<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../services/GpxParser.php';
require_once __DIR__ . '/../services/TcxParser.php';

// Поддерживаем только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

// Авторизация: Bearer или сессия
$me = api_require_user();

$errors  = [];
$activityId = null;

// ---- Параметры ----
$title       = trim((string)($_POST['title'] ?? ''));
$type        = (string)($_POST['type'] ?? 'run');
$visibility  = (string)($_POST['visibility'] ?? 'public');
$description = trim((string)($_POST['description'] ?? '')) ?: null;

$allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
if (!in_array($type, $allowedTypes, true)) $type = 'run';

$allowedVis = ['public','followers','private'];
if (!in_array($visibility, $allowedVis, true)) $visibility = 'public';

// ---- Файл ----
if (empty($_FILES['file']['tmp_name'])) {
    json_err('Файл активности обязателен (поле "file")', 400);
}

$file = $_FILES['file'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_err('Ошибка загрузки файла: код ' . (int)$file['error'], 400);
}

if (($file['size'] ?? 0) > 25 * 1024 * 1024) {
    json_err('Файл больше 25 МБ', 400);
}

$originalName = (string)($file['name'] ?? 'activity');
$ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($ext, ['gpx', 'tcx'], true)) {
    json_err('Поддерживаются только GPX и TCX', 400);
}

if (!is_uploaded_file($file['tmp_name'])) {
    json_err('Файл не был загружен через HTTP', 400);
}

// ---- Парсинг ----
try {
    $parsed = match ($ext) {
        'gpx' => GpxParser::parse($file['tmp_name']),
        'tcx' => TcxParser::parse($file['tmp_name']),
    };
} catch (Throwable $e) {
    json_err('Ошибка парсинга: ' . $e->getMessage(), 400);
}

if (empty($parsed['points']) || count($parsed['points']) < 2) {
    json_err('В файле нет GPS-точек', 400);
}

// ---- Уменьшаем трек, если слишком много точек ----
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

// ---- Название по умолчанию ----
if ($title === '') {
    $title = pathinfo($originalName, PATHINFO_FILENAME);
    $title = mb_substr($title, 0, 190);
}

// ---- Сохраняем ----
try {
    $activityId = Activity::create((int)$me['id'], [
        'type'             => $type,
        'title'            => $title,
        'description'      => $description,
        'started_at'       => $parsed['started_at'],
        'duration_sec'     => $parsed['duration_sec'],
        'distance_m'       => $parsed['distance_m'],
        'elevation_gain_m' => $parsed['elevation_gain_m'],
        'avg_speed_mps'    => $parsed['avg_speed_mps'],
        'max_speed_mps'    => $parsed['max_speed_mps'],
        'gear_id'          => null,
        'track_json'       => json_encode($points, JSON_UNESCAPED_UNICODE),
        'visibility'       => $visibility,
    ]);
} catch (Throwable $e) {
    json_err('Не удалось сохранить: ' . $e->getMessage(), 500);
}

// ---- Ответ ----
json_ok([
    'activity_id' => $activityId,
    'url'         => app_url('activity.php?id=' . $activityId),
    'summary' => [
        'title'            => $title,
        'type'             => $type,
        'distance_m'       => $parsed['distance_m'],
        'duration_sec'     => $parsed['duration_sec'],
        'elevation_gain_m' => $parsed['elevation_gain_m'],
        'avg_speed_mps'    => $parsed['avg_speed_mps'],
        'max_speed_mps'    => $parsed['max_speed_mps'],
        'started_at'       => $parsed['started_at'],
        'points'           => count($points),
    ],
], 201);
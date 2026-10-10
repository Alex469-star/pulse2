<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';

$me = api_require_user();
$userId = (int)$me['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

api_check_csrf();

$input = json_input();
if (!is_array($input)) $input = $_POST;

$activityId = (int)($input['activity_id'] ?? 0);
$startIndex = (int)($input['start_index'] ?? 0);
$endIndex   = (int)($input['end_index']   ?? 0);

if ($activityId <= 0) {
    json_err('activity_id обязателен', 400);
}

$activity = Activity::findById($activityId);
if (!$activity) {
    json_err('Активность не найдена', 404);
}
if ((int)$activity['user_id'] !== $userId) {
    json_err('Нет прав на обрезку', 403);
}

$track = json_decode((string)$activity['track_json'], true);
if (!is_array($track) || count($track) < 2) {
    json_err('У активности нет трека', 400);
}

$total = count($track);

// Санитизация диапазона
$startIndex = max(0, min($startIndex, $total - 2));
$endIndex   = max($startIndex + 1, min($endIndex, $total - 1));

if ($endIndex - $startIndex < 1) {
    json_err('Диапазон слишком узкий', 400);
}

// Обрезаем
$newTrack = array_slice($track, $startIndex, $endIndex - $startIndex + 1);

// Пересчитываем метрики
$metrics = Activity::recalcFromTrack($newTrack, (string)$activity['type']);

// Сдвигаем started_at, если есть t
$newStartedAt = $activity['started_at'];
if (!empty($newTrack[0]['t']) && (int)$newTrack[0]['t'] > 0) {
    $newStartedAt = date('Y-m-d H:i:s', (int)$newTrack[0]['t']);
}

// Пересчитываем track_hash
$newTrackJson = json_encode($newTrack, JSON_UNESCAPED_UNICODE);
$newTrackHash = Activity::trackHash($newTrackJson);

// Калории пропорционально времени, если были
$newCalories = $activity['calories'];
if ($newCalories !== null && (int)$activity['duration_sec'] > 0 && $metrics['duration_sec'] > 0) {
    $ratio = $metrics['duration_sec'] / (int)$activity['duration_sec'];
    $newCalories = (int)round((int)$newCalories * $ratio);
}

$update = array_merge($metrics, [
    'track_json' => $newTrackJson,
    'track_hash' => $newTrackHash,
    'started_at' => $newStartedAt,
    'calories'   => $newCalories,
]);

$ok = Activity::update($activityId, $userId, $update);
if (!$ok) {
    json_err('Не удалось сохранить', 500);
}

json_ok([
    'activity_id' => $activityId,
    'metrics'     => $metrics,
    'total'       => $total,
    'start_index' => $startIndex,
    'end_index'   => $endIndex,
    'new_points'  => count($newTrack),
]);
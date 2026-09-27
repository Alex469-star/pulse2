<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';

api_check_csrf();
$me = api_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

$input = json_input();
$photoId    = (int)($input['photo_id'] ?? 0);
$activityId = (int)($input['activity_id'] ?? 0);

if ($photoId <= 0 || $activityId <= 0) {
    json_err('photo_id and activity_id required', 400);
}

try {
    $ok = Activity::deletePhoto($photoId, $activityId, (int)$me['id']);
    if (!$ok) json_err('Не удалось удалить', 403);
    json_ok(['deleted' => true]);
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../includes/ImageUploader.php';

api_check_csrf();
$me = api_require_user();

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_err('Файл не был загружен', 400);
}

$activityId = (int)($_POST['activity_id'] ?? 0);
if ($activityId <= 0) {
    json_err('Не указан ID активности', 400);
}

$activity = Activity::findById($activityId);
if (!$activity || (int)$activity['user_id'] !== (int)$me['id']) {
    json_err('Активность не найдена', 404);
}

// Лимит 10 фото
$existing = Activity::photoCount($activityId);
if ($existing >= 10) {
    json_err('Достигнут лимит 10 фото', 400);
}

$order = (int)($_POST['order'] ?? $existing);

try {
    // ImageUploader::save() сам разберётся с HEIC/HEIF и сохранит как WebP
    $relativeUrl = ImageUploader::save($_FILES['file'], 'activities', 1600, 1600, 10 * 1024 * 1024);
} catch (Throwable $e) {
    json_err($e->getMessage(), 400);
}

$fullUrl = url($relativeUrl); // ImageUploader возвращает относительный путь
$photoId = Activity::addPhoto($activityId, $fullUrl, $order);

json_ok([
    'photo_id' => $photoId,
    'url'      => $fullUrl,
]);
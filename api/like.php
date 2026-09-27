<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Like.php';

// 1. Проверка CSRF (для браузера)
api_check_csrf();

// 2. Требуем авторизации (сессия или Bearer)
$me = api_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$input = json_input();
$activityId = (int)($input['activity_id'] ?? 0);
if ($activityId <= 0) {
    json_err('activity_id required', 400);
}

try {
    $liked = Like::toggle((int)$me['id'], $activityId);

    $stmt = db()->prepare('SELECT COUNT(*) FROM activity_likes WHERE activity_id = ?');
    $stmt->execute([$activityId]);
    $count = (int)$stmt->fetchColumn();

    json_ok([
        'liked' => $liked,
        'count' => $count,
    ]);
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
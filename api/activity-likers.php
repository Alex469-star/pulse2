<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$me = api_require_user();

$activityId = (int)($_GET['activity_id'] ?? 0);
if ($activityId <= 0) {
    json_err('activity_id required', 400);
}

try {
    // Проверим, что активность вообще существует
    $activity = Activity::findById($activityId);
    if (!$activity) {
        json_err('Not found', 404);
    }

    $likers = Activity::likers($activityId, 200);

    // Отдаём список + готовые url профилей
    $items = [];
    foreach ($likers as $l) {
        $items[] = [
            'id'           => $l['id'],
            'username'     => $l['username'],
            'display_name' => $l['display_name'],
            'avatar_url'   => $l['avatar_url'],
            'initial'      => $l['initial'],
            'profile_url'  => url('profile.php?u=' . urlencode($l['username'])),
        ];
    }

    json_ok([
        'activity_id' => $activityId,
        'count'       => count($items),
        'likers'      => $items,
    ]);
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
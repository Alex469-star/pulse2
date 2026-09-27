<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../models/Comment.php';

$me = api_require_user();
$id = (int)($_GET['id'] ?? 0);
if (!$id) json_err('id required');

$activity = Activity::findById($id);
if (!$activity) json_err('Not found', 404);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $activity['track'] = json_decode((string)$activity['track_json'], true);
    $activity['comments'] = Comment::forActivity($id);
    unset($activity['track_json']);
    json_ok($activity);
}

json_err('Method not allowed', 405);
<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';

$me = api_require_user();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $items = Activity::feed((int)$me['id'], $limit, $offset);
    json_ok(['items' => $items, 'limit' => $limit, 'offset' => $offset]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_input();
    $id = Activity::create((int)$me['id'], [
        'type'             => $input['type'] ?? 'run',
        'title'            => $input['title'] ?? 'Activity',
        'description'      => $input['description'] ?? null,
        'started_at'       => $input['started_at'] ?? null,
        'duration_sec'     => $input['duration_sec'] ?? null,
        'distance_m'       => $input['distance_m'] ?? null,
        'elevation_gain_m' => $input['elevation_gain_m'] ?? null,
        'avg_speed_mps'    => $input['avg_speed_mps'] ?? null,
        'max_speed_mps'    => $input['max_speed_mps'] ?? null,
        'track_json'       => isset($input['track']) ? json_encode($input['track']) : null,
        'visibility'       => $input['visibility'] ?? 'public',
    ]);
    json_ok(['id' => $id], 201);
}

json_err('Method not allowed', 405);
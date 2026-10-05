<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

function ok($d = null) { echo json_encode(['ok'=>true,'data'=>$d], JSON_UNESCAPED_UNICODE); exit; }
function err(string $m, int $c = 400) { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

$me = current_user();

// --- Параметры ---
$minLat = isset($_GET['min_lat']) ? (float)$_GET['min_lat'] : null;
$minLng = isset($_GET['min_lng']) ? (float)$_GET['min_lng'] : null;
$maxLat = isset($_GET['max_lat']) ? (float)$_GET['max_lat'] : null;
$maxLng = isset($_GET['max_lng']) ? (float)$_GET['max_lng'] : null;

if ($minLat === null || $minLng === null || $maxLat === null || $maxLng === null) {
    err('bbox обязателен');
}

// Ограничение размера bbox
if (($maxLat - $minLat) > 2.0 || ($maxLng - $minLng) > 2.0) {
    err('bbox слишком большой — приблизьте карту');
}

$type = (string)($_GET['type'] ?? '');       // run, ride, ... или пусто
$mode = (string)($_GET['mode'] ?? 'global'); // global | personal
$days = (int)($_GET['days'] ?? 365);         // за сколько дней

if ($mode === 'personal' && !$me) {
    err('Для личной карты войдите', 401);
}

// --- Собираем точки ---
$where = [
    'lat BETWEEN ? AND ?',
    'lng BETWEEN ? AND ?',
    'recorded_at >= NOW() - INTERVAL ? DAY',
];
$params = [$minLat, $maxLat, $minLng, $maxLng, $days];

if ($mode === 'personal') {
    $where[] = 'user_id = ?';
    $params[] = (int)$me['id'];
} else {
    // Глобальная — только публичные
    $where[] = 'is_public = 1';
    $where[] = 'visibility = "public"';
}

if ($type !== '' && in_array($type, ['run','ride','swim','ski','walk','hike','other'], true)) {
    $where[] = 'type = ?';
    $params[] = $type;
}

$whereSql = implode(' AND ', $where);

// Лимит точек для отдачи
$limit = $mode === 'personal' ? 8000 : 12000;

$stmt = db()->prepare(
    "SELECT lat, lng, type
       FROM heatmap_points
      WHERE $whereSql
   ORDER BY RAND()
      LIMIT $limit"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// --- Кластеризация в сетку 50 м ---
// Одна ячейка = одна «горячая точка». Intensity = min(1, count / 10)
$clusters = [];
$cellM = 50;
$latM = 111320.0;

foreach ($rows as $r) {
    $lat = (float)$r['lat'];
    $lng = (float)$r['lng'];

    $lngM = 111320.0 * cos(deg2rad($lat));
    $cx = (int)floor($lng * $lngM / $cellM);
    $cy = (int)floor($lat * $latM / $cellM);

    $key = $cx . ':' . $cy;

    if (!isset($clusters[$key])) {
        $clusters[$key] = ['lat' => $lat, 'lng' => $lng, 'cnt' => 0];
    }
    $clusters[$key]['cnt']++;
}

// Формируем результат: [lat, lng, intensity]
$points = [];
foreach ($clusters as $c) {
    $intensity = min(1.0, $c['cnt'] / 10.0); // 10+ точек = максимум
    $points[] = [
        round($c['lat'], 5),
        round($c['lng'], 5),
        round($intensity, 2),
    ];
}

ok([
    'mode'    => $mode,
    'type'    => $type ?: 'all',
    'count'   => count($points),
    'points'  => $points,
]);
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

function ok($d = null): void {
    echo json_encode(['ok' => true, 'data' => $d], JSON_UNESCAPED_UNICODE);
    exit;
}
function err(string $m, int $c = 400): void {
    http_response_code($c);
    echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}

$me = current_user();

// ---- Параметры bbox ----
$minLat = isset($_GET['min_lat']) ? (float)$_GET['min_lat'] : null;
$minLng = isset($_GET['min_lng']) ? (float)$_GET['min_lng'] : null;
$maxLat = isset($_GET['max_lat']) ? (float)$_GET['max_lat'] : null;
$maxLng = isset($_GET['max_lng']) ? (float)$_GET['max_lng'] : null;

if ($minLat === null || $minLng === null || $maxLat === null || $maxLng === null) {
    err('bbox обязателен');
}

// Ограничение размера bbox, чтобы не тянуть всю планету
if (($maxLat - $minLat) > 3.0 || ($maxLng - $minLng) > 3.0) {
    err('bbox слишком большой — приблизьте карту');
}

$type = (string)($_GET['type'] ?? '');
$allowedTypes = ['', 'run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
if (!in_array($type, $allowedTypes, true)) $type = '';

// ---- Кэш Redis (если доступен) ----
$cacheKey = Cache::key('heatmap.segments', 
    round($minLat, 3), round($minLng, 3), 
    round($maxLat, 3), round($maxLng, 3), 
    $type
);
$cached = Cache::get($cacheKey);
if ($cached !== null && is_array($cached)) {
    ok($cached);
}

// ---- Запрос сегментов ----
$sql = 'SELECT id, name, type, distance_m, elevation_gain_m, track_json
          FROM segments
         WHERE is_public = 1';
$params = [];

if ($type !== '') {
    $sql .= ' AND type = ?';
    $params[] = $type;
}

// Сортировка по свежести + ограничение
$sql .= ' ORDER BY id DESC LIMIT 3000';

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    err('Ошибка выборки: ' . $e->getMessage(), 500);
}

// ---- Фильтрация по bbox и прореживание ----
$segments = [];
foreach ($rows as $s) {
    $track = json_decode((string)$s['track_json'], true);
    if (!is_array($track) || count($track) < 2) continue;

    $simplified = [];
    $minLatS = $maxLatS = $minLngS = $maxLngS = null;

    foreach ($track as $p) {
        if (!isset($p['lat'], $p['lng'])) continue;

        $lat = (float)$p['lat'];
        $lng = (float)$p['lng'];

        if ($minLatS === null || $lat < $minLatS) $minLatS = $lat;
        if ($maxLatS === null || $lat > $maxLatS) $maxLatS = $lat;
        if ($minLngS === null || $lng < $minLngS) $minLngS = $lng;
        if ($maxLngS === null || $lng > $maxLngS) $maxLngS = $lng;

        $simplified[] = [round($lat, 5), round($lng, 5)];
    }

    if ($minLatS === null) continue;

    // Пропускаем, если bbox сегмента не пересекается с запрошенным
    if ($maxLatS < $minLat || $minLatS > $maxLat) continue;
    if ($maxLngS < $minLng || $minLngS > $maxLng) continue;

    // Прореживание до 300 точек — для скорости отрисовки
    if (count($simplified) > 300) {
        $step = (int)ceil(count($simplified) / 300);
        $out = [];
        for ($i = 0; $i < count($simplified); $i += $step) $out[] = $simplified[$i];
        if (end($out) !== end($simplified)) $out[] = end($simplified);
        $simplified = $out;
    }

    $segments[] = [
        'id'         => (int)$s['id'],
        'name'       => (string)$s['name'],
        'type'       => (string)$s['type'],
        'distance_m' => (float)$s['distance_m'],
        'elevation'  => $s['elevation_gain_m'] !== null ? (float)$s['elevation_gain_m'] : null,
        'url'        => url('segment.php?id=' . (int)$s['id']),
        'track'      => $simplified,
    ];

    // Ограничим общее количество сегментов, чтобы не грузить браузер
    if (count($segments) >= 500) break;
}

$result = [
    'count'    => count($segments),
    'segments' => $segments,
];

// ---- Кэшируем ----
Cache::set($cacheKey, $result, 300);

ok($result);
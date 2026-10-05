<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') exit('CLI only');

$limit = isset($argv[1]) ? (int)$argv[1] : 500;
$offset = isset($argv[2]) ? (int)$argv[2] : 0;

// Берём активности, которых ещё нет в heatmap_points
$stmt = db()->prepare(
    'SELECT a.id, a.user_id, a.type, a.visibility, a.track_json,
            a.started_at, a.created_at
       FROM activities a
  LEFT JOIN heatmap_points h ON h.activity_id = a.id
      WHERE h.id IS NULL
        AND a.track_json IS NOT NULL
        AND a.visibility = "public"
   ORDER BY a.id ASC
      LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$activities = $stmt->fetchAll();

if (!$activities) {
    fwrite(STDERR, "[heatmap] всё обработано\n");
    exit;
}

$insert = db()->prepare(
    'INSERT INTO heatmap_points
        (activity_id, user_id, type, lat, lng, hour, recorded_at, is_public, visibility)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1, "public")'
);

$totalPoints = 0;

foreach ($activities as $a) {
    $track = json_decode((string)$a['track_json'], true);
    if (!is_array($track) || count($track) < 2) continue;

    // Прореживание: не больше 100 точек на активность
    $maxPoints = 100;
    $n = count($track);
    $step = $n > $maxPoints ? (int)ceil($n / $maxPoints) : 1;

    $startedAt = $a['started_at'] ?: $a['created_at'];
    $startTs = strtotime((string)$startedAt) ?: time();

    db()->beginTransaction();
    try {
        for ($i = 0; $i < $n; $i += $step) {
            $p = $track[$i];
            if (!isset($p['lat'], $p['lng'])) continue;

            $lat = (float)$p['lat'];
            $lng = (float)$p['lng'];

            // Отсеиваем мусор
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;

            // Время точки: либо из трека, либо интерполируем
            $ts = isset($p['t']) ? (int)$p['t'] : ($startTs + $i * 5);
            $hour = (int)date('G', $ts);
            $recordedAt = date('Y-m-d H:i:s', $ts);

            $insert->execute([
                (int)$a['id'],
                (int)$a['user_id'],
                (string)$a['type'],
                round($lat, 6),
                round($lng, 6),
                $hour,
                $recordedAt,
            ]);
            $totalPoints++;
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        fwrite(STDERR, "[heatmap] #{$a['id']} ошибка: " . $e->getMessage() . "\n");
    }
}

fwrite(STDERR, "[heatmap] обработано активностей: " . count($activities) . ", точек: $totalPoints\n");
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Activity.php';

$limit  = 500;
$offset = 0;
$total  = 0;
$skipped = 0;

$countStmt = db()->query('SELECT COUNT(*) FROM activities WHERE fingerprint IS NULL');
$totalRows = (int)$countStmt->fetchColumn();

echo "К обработке: {$totalRows} активностей\n";

while (true) {
    $s = db()->prepare(
        'SELECT id, user_id, type, started_at, distance_m, duration_sec, track_json
         FROM activities
         WHERE fingerprint IS NULL
         LIMIT ?'
    );
    $s->bindValue(1, $limit, PDO::PARAM_INT);
    $s->execute();
    $rows = $s->fetchAll();

    if (!$rows) break;

    $upd = db()->prepare(
        'UPDATE activities SET fingerprint = ?, track_hash = ? WHERE id = ?'
    );

    foreach ($rows as $r) {
        $fp = Activity::fingerprint([
            'type'         => $r['type'],
            'started_at'   => $r['started_at'],
            'distance_m'   => $r['distance_m'],
            'duration_sec' => $r['duration_sec'],
        ]);

        $th = Activity::trackHash($r['track_json']);

        $upd->execute([$fp, $th, (int)$r['id']]);
        $total++;

        if ($total % 100 === 0) {
            echo "  обработано: {$total}\n";
        }
    }

    // Пауза, чтобы не грузить БД
    usleep(200000);
    $offset += $limit;
}

echo "Готово. Обновлено: {$total}\n";
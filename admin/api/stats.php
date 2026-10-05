<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

admin_require('readonly');

$type = (string)($_GET['type'] ?? 'all');
$out = [];

try {
    if ($type === 'all' || $type === 'registrations') {
        $s = db()->query(
            "SELECT DATE(created_at) AS d, COUNT(*) AS cnt
               FROM users
              WHERE created_at >= NOW() - INTERVAL 30 DAY
              GROUP BY DATE(created_at) ORDER BY d"
        );
        $out['registrations'] = $s->fetchAll();
    }

    if ($type === 'all' || $type === 'activities') {
        $s = db()->query(
            "SELECT DATE(COALESCE(started_at, created_at)) AS d, COUNT(*) AS cnt
               FROM activities
              WHERE COALESCE(started_at, created_at) >= NOW() - INTERVAL 30 DAY
              GROUP BY DATE(COALESCE(started_at, created_at)) ORDER BY d"
        );
        $out['activities'] = $s->fetchAll();
    }

    if ($type === 'all' || $type === 'types') {
        $s = db()->query(
            "SELECT type, COUNT(*) AS cnt FROM activities GROUP BY type ORDER BY cnt DESC"
        );
        $out['types'] = $s->fetchAll();
    }

    if ($type === 'all' || $type === 'dow') {
        $s = db()->query(
            "SELECT DAYOFWEEK(COALESCE(started_at, created_at)) AS dow, COUNT(*) AS cnt
               FROM activities
              WHERE COALESCE(started_at, created_at) >= NOW() - INTERVAL 90 DAY
              GROUP BY DAYOFWEEK(COALESCE(started_at, created_at))
              ORDER BY dow"
        );
        $out['dow'] = $s->fetchAll();
    }

    if ($type === 'all' || $type === 'hour') {
        $s = db()->query(
            "SELECT HOUR(COALESCE(started_at, created_at)) AS h, COUNT(*) AS cnt
               FROM activities
              WHERE COALESCE(started_at, created_at) >= NOW() - INTERVAL 90 DAY
              GROUP BY HOUR(COALESCE(started_at, created_at))
              ORDER BY h"
        );
        $out['hour'] = $s->fetchAll();
    }

    if ($type === 'all' || $type === 'top') {
        $s = db()->query(
            "SELECT u.username, u.display_name,
                    COALESCE(SUM(a.distance_m),0) AS dist
               FROM users u
          LEFT JOIN activities a ON a.user_id = u.id
           GROUP BY u.id
           ORDER BY dist DESC LIMIT 10"
        );
        $out['top'] = $s->fetchAll();
    }

    if ($type === 'all' || $type === 'weekly') {
        $s = db()->query(
            "SELECT YEARWEEK(COALESCE(started_at, created_at), 1) AS w,
                    COALESCE(SUM(distance_m),0) AS dist
               FROM activities
              WHERE COALESCE(started_at, created_at) >= NOW() - INTERVAL 12 WEEK
              GROUP BY YEARWEEK(COALESCE(started_at, created_at), 1)
              ORDER BY w"
        );
        $out['weekly'] = $s->fetchAll();
    }

    if ($type === 'all' || $type === 'cities') {
        $s = db()->query(
            "SELECT u.city, COUNT(*) AS cnt
               FROM users u
              WHERE u.city IS NOT NULL AND u.city != ''
              GROUP BY u.city ORDER BY cnt DESC LIMIT 15"
        );
        $out['cities'] = $s->fetchAll();
    }

    echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
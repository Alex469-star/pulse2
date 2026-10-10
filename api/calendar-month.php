<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';

$me = api_require_user();
$userId = (int)$me['id'];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

if ($month < 1 || $month > 12) {
    json_err('Invalid month', 400);
}
if ($year < 2000 || $year > 2100) {
    json_err('Invalid year', 400);
}

// Границы месяца
$from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
$to   = date('Y-m-t 23:59:59', strtotime($from));

// Тянем активности за месяц
$stmt = db()->prepare(
    'SELECT id, type, title, distance_m, duration_sec, elevation_gain_m,
            avg_speed_mps, max_speed_mps, calories, started_at, created_at,
            avg_hr, max_hr, visibility
       FROM activities
      WHERE user_id = ?
        AND COALESCE(started_at, created_at) >= ?
        AND COALESCE(started_at, created_at) <= ?
   ORDER BY COALESCE(started_at, created_at) ASC'
);
$stmt->execute([$userId, $from, $to]);
$rows = $stmt->fetchAll();

// Группируем по дням + считаем агрегаты месяца
$byDay = [];
$stats = [
    'count'         => 0,
    'distance_m'    => 0.0,
    'duration_sec'  => 0,
    'elevation_m'   => 0.0,
    'active_days'   => 0,
    'avg_speed_sum' => 0.0,
    'avg_speed_cnt' => 0,
    'avg_hr_sum'    => 0,
    'avg_hr_cnt'    => 0,
];

foreach ($rows as $r) {
    $ts = strtotime((string)($r['started_at'] ?? $r['created_at']));
    if ($ts === false) continue;
    $dayKey = date('Y-m-d', $ts);

    if (!isset($byDay[$dayKey])) {
        $byDay[$dayKey] = [
            'date'         => $dayKey,
            'count'        => 0,
            'distance_m'   => 0.0,
            'duration_sec' => 0,
            'types'        => [],
            'activities'   => [],
        ];
    }

    $byDay[$dayKey]['count']++;
    $byDay[$dayKey]['distance_m']   += (float)($r['distance_m'] ?? 0);
    $byDay[$dayKey]['duration_sec'] += (int)($r['duration_sec'] ?? 0);

    $type = (string)$r['type'];
    if (!in_array($type, $byDay[$dayKey]['types'], true)) {
        $byDay[$dayKey]['types'][] = $type;
    }

    $byDay[$dayKey]['activities'][] = [
        'id'               => (int)$r['id'],
        'type'             => $type,
        'title'            => (string)$r['title'],
        'distance_m'       => (float)($r['distance_m'] ?? 0),
        'duration_sec'     => (int)($r['duration_sec'] ?? 0),
        'elevation_gain_m' => (float)($r['elevation_gain_m'] ?? 0),
        'avg_speed_mps'    => $r['avg_speed_mps'] !== null ? (float)$r['avg_speed_mps'] : null,
        'avg_hr'           => $r['avg_hr'] !== null ? (int)$r['avg_hr'] : null,
        'started_at'       => (string)($r['started_at'] ?? $r['created_at']),
    ];

    // ---- Агрегаты месяца ----
    $stats['count']++;
    $stats['distance_m']    += (float)($r['distance_m'] ?? 0);
    $stats['duration_sec']  += (int)($r['duration_sec'] ?? 0);
    $stats['elevation_m']   += (float)($r['elevation_gain_m'] ?? 0);

    if ($r['avg_speed_mps'] !== null && (float)$r['avg_speed_mps'] > 0) {
        $stats['avg_speed_sum'] += (float)$r['avg_speed_mps'];
        $stats['avg_speed_cnt']++;
    }
    if ($r['avg_hr'] !== null && (int)$r['avg_hr'] > 0) {
        $stats['avg_hr_sum'] += (int)$r['avg_hr'];
        $stats['avg_hr_cnt']++;
    }
}

$stats['active_days'] = count($byDay);
$stats['avg_speed_mps'] = $stats['avg_speed_cnt'] > 0
    ? $stats['avg_speed_sum'] / $stats['avg_speed_cnt']
    : null;
$stats['avg_hr'] = $stats['avg_hr_cnt'] > 0
    ? (int)round($stats['avg_hr_sum'] / $stats['avg_hr_cnt'])
    : null;

// Убираем вспомогательные суммы из ответа
unset(
    $stats['avg_speed_sum'], $stats['avg_speed_cnt'],
    $stats['avg_hr_sum'],    $stats['avg_hr_cnt']
);

// Метаданные для навигации
$firstDay = strtotime($from);
$daysInMonth = (int)date('t', $firstDay);
$startWeekday = (int)date('N', $firstDay); // 1 (Пн) — 7 (Вс)

$stats['days_in_month'] = $daysInMonth;

json_ok([
    'year'          => $year,
    'month'         => $month,
    'month_label'   => date('F Y', $firstDay),
    'days_in_month' => $daysInMonth,
    'start_weekday' => $startWeekday,
    'days'          => $byDay,
    'stats'         => $stats,
]);
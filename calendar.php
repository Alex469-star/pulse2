<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Activity.php';

auth_start();
$me = require_login();
$userId = (int)$me['id'];

// ============================================================
// 1. ЗАГРУЗКА ВСЕХ АКТИВНОСТЕЙ ЗА 365 ДНЕЙ
// ============================================================
$activities = [];
$byDate = [];      // ['2026-09-26' => [count, distance, duration, hr_sum, pwr_sum, ids]]
$byType = [];      // ['run' => [...]]
$byMonth = [];     // ['2026-09' => [...]]
$byWeekday = [];   // ['Mon' => count] — какой день недели активнее
$byHour = [];      // ['07' => count] — в какое время дня чаще

try {
    $stmt = db()->prepare(
        'SELECT id, type, title, distance_m, duration_sec, elevation_gain_m,
                avg_speed_mps, max_speed_mps, calories, started_at, created_at,
                avg_hr, max_hr, avg_cadence, max_cadence,
                avg_power_w, max_power_w, has_sensors
         FROM activities
         WHERE user_id = ?
           AND created_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
         ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    $activities = $stmt->fetchAll();
} catch (Throwable $e) {
    $activities = [];
}

// Полные суммы за всё время (для «за всё время»)
$totalsAllTime = [
    'count' => 0, 'distance' => 0, 'duration' => 0,
    'elevation' => 0, 'calories' => 0,
];
try {
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS c, COALESCE(SUM(distance_m),0) AS d,
                COALESCE(SUM(duration_sec),0) AS t,
                COALESCE(SUM(elevation_gain_m),0) AS el,
                COALESCE(SUM(calories),0) AS cal
         FROM activities WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) {
        $totalsAllTime = [
            'count'     => (int)$row['c'],
            'distance'  => (float)$row['d'],
            'duration'  => (int)$row['t'],
            'elevation' => (float)$row['el'],
            'calories'  => (int)$row['cal'],
        ];
    }
} catch (Throwable $e) {}

// Заполняем агрегаты
foreach ($activities as $a) {
    $started  = (string)($a['started_at'] ?? $a['created_at']);
    $ts       = strtotime($started);
    if ($ts === false) continue;

    $dateKey  = date('Y-m-d', $ts);
    $monthKey = date('Y-m', $ts);
    $typeKey  = (string)$a['type'];
    $weekday  = date('D', $ts); // Mon, Tue...
    $hour     = date('H', $ts);

    $dist = (float)($a['distance_m'] ?? 0);
    $dur  = (int)($a['duration_sec'] ?? 0);
    $elev = (float)($a['elevation_gain_m'] ?? 0);
    $cal  = (int)($a['calories'] ?? 0);
    $hr   = $a['avg_hr']       !== null ? (int)$a['avg_hr']       : null;
    $pwr  = $a['avg_power_w']  !== null ? (int)$a['avg_power_w']  : null;
    $cad  = $a['avg_cadence']  !== null ? (int)$a['avg_cadence']  : null;

    if (!isset($byDate[$dateKey])) {
        $byDate[$dateKey] = [
            'count' => 0, 'distance' => 0, 'duration' => 0,
            'elevation' => 0, 'calories' => 0,
            'hr_sum' => 0, 'hr_cnt' => 0,
            'pwr_sum' => 0, 'pwr_cnt' => 0,
            'ids' => [],
        ];
    }
    $byDate[$dateKey]['count']++;
    $byDate[$dateKey]['distance']  += $dist;
    $byDate[$dateKey]['duration']  += $dur;
    $byDate[$dateKey]['elevation'] += $elev;
    $byDate[$dateKey]['calories']  += $cal;
    $byDate[$dateKey]['ids'][] = (int)$a['id'];
    if ($hr  !== null) { $byDate[$dateKey]['hr_sum']  += $hr;  $byDate[$dateKey]['hr_cnt']++; }
    if ($pwr !== null) { $byDate[$dateKey]['pwr_sum'] += $pwr; $byDate[$dateKey]['pwr_cnt']++; }

    if (!isset($byType[$typeKey])) {
        $byType[$typeKey] = [
            'count' => 0, 'distance' => 0, 'duration' => 0,
            'elevation' => 0, 'calories' => 0,
            'hr_sum' => 0, 'hr_cnt' => 0,
            'pwr_sum' => 0, 'pwr_cnt' => 0,
        ];
    }
    $byType[$typeKey]['count']++;
    $byType[$typeKey]['distance']  += $dist;
    $byType[$typeKey]['duration']  += $dur;
    $byType[$typeKey]['elevation'] += $elev;
    $byType[$typeKey]['calories']  += $cal;
    if ($hr  !== null) { $byType[$typeKey]['hr_sum']  += $hr;  $byType[$typeKey]['hr_cnt']++; }
    if ($pwr !== null) { $byType[$typeKey]['pwr_sum'] += $pwr; $byType[$typeKey]['pwr_cnt']++; }

    if (!isset($byMonth[$monthKey])) {
        $byMonth[$monthKey] = ['count' => 0, 'distance' => 0, 'duration' => 0];
    }
    $byMonth[$monthKey]['count']++;
    $byMonth[$monthKey]['distance'] += $dist;
    $byMonth[$monthKey]['duration'] += $dur;

    if (!isset($byWeekday[$weekday])) $byWeekday[$weekday] = 0;
    $byWeekday[$weekday]++;

    $hKey = str_pad($hour, 2, '0', STR_PAD_LEFT);
    if (!isset($byHour[$hKey])) $byHour[$hKey] = 0;
    $byHour[$hKey]++;
}

// ============================================================
// 2. МЕТРИКИ ЗА ПЕРИОДЫ
// ============================================================
function sum_period(array $byDate, string $from, string $to): array
{
    $out = ['count' => 0, 'distance' => 0, 'duration' => 0, 'elevation' => 0, 'calories' => 0];
    foreach ($byDate as $date => $data) {
        if ($date >= $from && $date <= $to) {
            $out['count']     += $data['count'];
            $out['distance']  += $data['distance'];
            $out['duration']  += $data['duration'];
            $out['elevation'] += $data['elevation'];
            $out['calories']  += $data['calories'];
        }
    }
    return $out;
}

$today     = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
$monthFrom = date('Y-m-01');
$monthTo   = date('Y-m-t');
$yearFrom  = date('Y-01-01');
$yearTo    = date('Y-12-31');

$statsWeek  = sum_period($byDate, $weekStart, $weekEnd);
$statsMonth = sum_period($byDate, $monthFrom, $monthTo);
$statsYear  = sum_period($byDate, $yearFrom, $yearTo);
$statsToday = $byDate[$today] ?? null;

// Прошлая неделя / месяц / год — для сравнения
$prevWeekStart = date('Y-m-d', strtotime('monday last week'));
$prevWeekEnd   = date('Y-m-d', strtotime('sunday last week'));
$prevWeek      = sum_period($byDate, $prevWeekStart, $prevWeekEnd);

$prevMonthFrom = date('Y-m-01', strtotime('first day of last month'));
$prevMonthTo   = date('Y-m-t', strtotime('last day of last month'));
$prevMonth     = sum_period($byDate, $prevMonthFrom, $prevMonthTo);

$prevYearFrom = date((date('Y') - 1) . '-01-01');
$prevYearTo   = date((date('Y') - 1) . '-12-31');
$prevYear     = sum_period($byDate, $prevYearFrom, $prevYearTo);

// Разница
$diffWeekCount    = $statsWeek['count'] - $prevWeek['count'];
$diffWeekDistance = $statsWeek['distance'] - $prevWeek['distance'];
$diffMonthCount   = $statsMonth['count'] - $prevMonth['count'];
$diffMonthDist    = $statsMonth['distance'] - $prevMonth['distance'];

// Streak — дней подряд (с сегодня в прошлое)
$streak = 0;
$checkDate = $today;
for ($i = 0; $i < 365; $i++) {
    if (!empty($byDate[$checkDate])) {
        $streak++;
        $checkDate = date('Y-m-d', strtotime($checkDate . ' -1 day'));
    } else {
        break;
    }
}

// Лучший streak за год
$bestStreak = 0;
$currentRun = 0;
$sortedDates = array_keys($byDate);
sort($sortedDates);
foreach ($sortedDates as $i => $d) {
    if ($i > 0) {
        $prev = $sortedDates[$i - 1];
        $gap = (strtotime($d) - strtotime($prev)) / 86400;
        if ($gap === 1.0) {
            $currentRun++;
        } else {
            $currentRun = 1;
        }
    } else {
        $currentRun = 1;
    }
    if ($currentRun > $bestStreak) $bestStreak = $currentRun;
}

// ============================================================
// 3. РЕКОРДЫ И ДОСТИЖЕНИЯ
// ============================================================
$longestActivity = null;
$fastestActivity = null;
$biggestElevation = null;
foreach ($activities as $a) {
    $d = (float)($a['distance_m'] ?? 0);
    $s = (float)($a['avg_speed_mps'] ?? 0);
    $e = (float)($a['elevation_gain_m'] ?? 0);

    if ($longestActivity === null || $d > (float)$longestActivity['distance_m']) {
        $longestActivity = $a;
    }
    if ($s > 0 && ($fastestActivity === null || $s > (float)$fastestActivity['avg_speed_mps'])) {
        $fastestActivity = $a;
    }
    if ($biggestElevation === null || $e > (float)$biggestElevation['elevation_gain_m']) {
        $biggestElevation = $a;
    }
}

// Лучший день / неделя / месяц
$bestDay = null;
foreach ($byDate as $date => $d) {
    if ($bestDay === null || $d['distance'] > $bestDay['distance']) {
        $bestDay = ['date' => $date] + $d;
    }
}

// Недели за год — найти лучшую
$weeksAll = [];
$start = strtotime('monday this week -52 week');
$end   = strtotime('sunday this week');
for ($w = $start; $w <= $end; $w += 7 * 86400) {
    $wStart = date('Y-m-d', $w);
    $wEnd   = date('Y-m-d', $w + 6 * 86400);
    $sum = sum_period($byDate, $wStart, $wEnd);
    $weeksAll[] = ['start' => $wStart, 'end' => $wEnd] + $sum;
}
$bestWeek = null;
foreach ($weeksAll as $w) {
    if ($bestWeek === null || $w['distance'] > $bestWeek['distance']) {
        $bestWeek = $w;
    }
}

$bestMonth = null;
foreach ($byMonth as $m => $d) {
    if ($bestMonth === null || $d['distance'] > $bestMonth['distance']) {
        $bestMonth = ['month' => $m] + $d;
    }
}

// Средние за год
$avgDistPerActivity = $statsYear['count'] > 0
    ? $statsYear['distance'] / $statsYear['count'] : 0;
$avgDurationPerActivity = $statsYear['count'] > 0
    ? $statsYear['duration'] / $statsYear['count'] : 0;
$avgDistPerWeek = $statsYear['distance'] / 52;

// Средний темп/скорость
$avgPaceSecPerKm = ($statsYear['distance'] > 0 && $statsYear['duration'] > 0)
    ? $statsYear['duration'] / ($statsYear['distance'] / 1000) : 0;

// Количество активных дней и дней отдыха за год
$activeDays = 0;
foreach ($byDate as $date => $d) {
    if ($date >= $yearFrom && $date <= $yearTo) $activeDays++;
}
$daysSinceYearStart = (int)((strtotime($today) - strtotime($yearFrom)) / 86400) + 1;
$restDays = max(0, $daysSinceYearStart - $activeDays);

// Самая длинная серия отдыха за год
$longestRest = 0;
$currentRest = 0;
$cursor = strtotime($yearFrom);
$todayTs = strtotime($today);
while ($cursor <= $todayTs) {
    $d = date('Y-m-d', $cursor);
    if (empty($byDate[$d])) {
        $currentRest++;
        if ($currentRest > $longestRest) $longestRest = $currentRest;
    } else {
        $currentRest = 0;
    }
    $cursor += 86400;
}

// ============================================================
// 4. МЕТРИКИ ДАТЧИКОВ (средний пульс, мощность, каденс за год)
// ============================================================
$sensorTotals = [
    'hr_sum' => 0, 'hr_cnt' => 0,
    'pwr_sum' => 0, 'pwr_cnt' => 0,
];
foreach ($byDate as $date => $d) {
    if ($date >= $yearFrom && $date <= $yearTo) {
        $sensorTotals['hr_sum']  += $d['hr_sum']  ?? 0;
        $sensorTotals['hr_cnt']  += $d['hr_cnt']  ?? 0;
        $sensorTotals['pwr_sum'] += $d['pwr_sum'] ?? 0;
        $sensorTotals['pwr_cnt'] += $d['pwr_cnt'] ?? 0;
    }
}
$avgHrYear  = $sensorTotals['hr_cnt']  > 0 ? (int)round($sensorTotals['hr_sum']  / $sensorTotals['hr_cnt'])  : null;
$avgPwrYear = $sensorTotals['pwr_cnt'] > 0 ? (int)round($sensorTotals['pwr_sum'] / $sensorTotals['pwr_cnt']) : null;

// ============================================================
// 5. ГРАФИК ПО НЕДЕЛЯМ (12 недель)
// ============================================================
$weeks = [];
for ($i = 11; $i >= 0; $i--) {
    $s = date('Y-m-d', strtotime('monday this week -' . $i . ' week'));
    $e = date('Y-m-d', strtotime('sunday this week -' . $i . ' week'));
    $sum = sum_period($byDate, $s, $e);
    $weeks[] = ['label' => date('d.m', strtotime($s))] + $sum;
}
$maxWeekDistance = max(1, max(array_column($weeks, 'distance')));

// ============================================================
// 6. МЕСЯЦЫ ГОДА (12 столбцов)
// ============================================================
$monthsYear = [];
for ($m = 1; $m <= 12; $m++) {
    $key = date('Y-') . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
    $monthsYear[] = [
        'key'    => $key,
        'label'  => date('M', mktime(0, 0, 0, $m, 1)),
        'data'   => $byMonth[$key] ?? ['count' => 0, 'distance' => 0, 'duration' => 0],
        'future' => $key > date('Y-m'),
    ];
}
$maxMonthDistance = max(1, max(array_column(array_column($monthsYear, 'data'), 'distance')));

// ============================================================
// 7. ЦЕЛЬ НА НЕДЕЛЮ (можно хранить в users, но пока захардкодим)
// ============================================================
$weeklyGoalDistance = 30000; // 30 км/нед — можно вынести в настройки
$weeklyGoalCount    = 4;     // 4 активности/нед
$weekProgress       = $weeklyGoalDistance > 0
    ? min(100, round(($statsWeek['distance'] / $weeklyGoalDistance) * 100)) : 0;
$weekCountProgress  = $weeklyGoalCount > 0
    ? min(100, round(($statsWeek['count'] / $weeklyGoalCount) * 100)) : 0;

// ============================================================
// 8. КАЛЕНДАРЬ-СЕТКА (52 недели)
// ============================================================
$calendarStart = date('Y-m-d', strtotime('monday this week -52 week'));
$calendarEnd   = date('Y-m-d', strtotime('sunday this week'));

$calendar = [];
$currentDate = $calendarStart;
while ($currentDate <= $calendarEnd) {
    $week = [];
    for ($d = 0; $d < 7; $d++) {
        $week[] = [
            'date'     => $currentDate,
            'data'     => $byDate[$currentDate] ?? null,
            'inFuture' => $currentDate > $today,
        ];
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }
    $calendar[] = $week;
}

$maxDayDistance = 1;
foreach ($byDate as $d) {
    if ($d['distance'] > $maxDayDistance) $maxDayDistance = $d['distance'];
}

function activity_level(?array $data, float $maxDistance): int
{
    if (!$data || $data['count'] === 0) return 0;
    if ($maxDistance <= 0) return 1;
    $ratio = $data['distance'] / $maxDistance;
    if ($ratio < 0.25) return 1;
    if ($ratio < 0.5)  return 2;
    if ($ratio < 0.8)  return 3;
    return 4;
}

// Топ-5 активностей по дистанции
$topActivities = $activities;
usort($topActivities, fn($a, $b) => (float)$b['distance_m'] <=> (float)$a['distance_m']);
$topActivities = array_slice($topActivities, 0, 5);

// День недели с максимумом
$bestWeekday = null;
$bestWeekdayCount = 0;
$weekdayLabels = ['Mon' => 'Понедельник', 'Tue' => 'Вторник', 'Wed' => 'Среда',
                  'Thu' => 'Четверг', 'Fri' => 'Пятница', 'Sat' => 'Суббота', 'Sun' => 'Воскресенье'];
foreach ($byWeekday as $wd => $cnt) {
    if ($cnt > $bestWeekdayCount) {
        $bestWeekdayCount = $cnt;
        $bestWeekday = $wd;
    }
}

// Час с максимумом
$bestHour = null;
$bestHourCount = 0;
foreach ($byHour as $h => $cnt) {
    if ($cnt > $bestHourCount) {
        $bestHourCount = $cnt;
        $bestHour = $h;
    }
}

$pageTitle = 'Календарь и статистика';
require __DIR__ . '/includes/header.php';

function activity_icon(string $type): string {
    return match ($type) {
        'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
        'walk' => '🚶', 'hike' => '🥾', default => '📦',
    };
}
function activity_label(string $type): string {
    return match ($type) {
        'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
        'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
        default => 'Другое',
    };
}
function format_sec_to_hm(int $sec): string {
    if ($sec <= 0) return '—';
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return $h > 0 ? sprintf('%d ч %d мин', $h, $m) : sprintf('%d мин', $m);
}
?>

<section class="calendar-page">
    <header class="calendar-page__head">
        <div>
            <h1 class="section-title" style="text-align:left;margin-bottom:4px">Календарь и статистика</h1>
            <p class="muted">Твой год в тренировках — подробно и с мотивацией</p>
        </div>
        <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--primary">+ Активность</a>
    </header>

    <!-- ============ МОТИВАЦИОННЫЙ БЛОК ============ -->
    <?php
        $motivation = [];

        if ($streak >= 7) {
            $motivation[] = ['icon' => '🔥', 'text' => 'Серия <strong>' . $streak . ' дней подряд</strong>! Это уже привычка. Не разрывай.'];
        } elseif ($streak >= 3) {
            $motivation[] = ['icon' => '⚡', 'text' => 'Серия из <strong>' . $streak . ' дней</strong>. Ещё немного до недели!'];
        } elseif ($statsToday) {
            $motivation[] = ['icon' => '✅', 'text' => 'Сегодня уже есть тренировка. Отличный старт дня!'];
        } else {
            $motivation[] = ['icon' => '💪', 'text' => 'Сегодня ещё нет активности. Даже 20 минут — уже победа.'];
        }

        if ($statsWeek['count'] === 0) {
            $motivation[] = ['icon' => '🎯', 'text' => 'На этой неделе пока пусто. Одна загрузка — и неделя считается.'];
        } else {
            $motivation[] = ['icon' => '📅', 'text' => 'На этой неделе <strong>' . $statsWeek['count'] . '</strong> активн. на <strong>' . e(format_distance($statsWeek['distance'])) . '</strong>.'];
        }

        if ($diffWeekCount > 0) {
            $motivation[] = ['icon' => '📈', 'text' => 'На этой неделе на <strong>' . $diffWeekCount . '</strong> активн. больше, чем на прошлой. Растёшь!'];
        } elseif ($diffWeekCount < 0 && $prevWeek['count'] > 0) {
            $motivation[] = ['icon' => '📊', 'text' => 'На прошлой неделе было на <strong>' . abs($diffWeekCount) . '</strong> активн. больше. Догоняем!'];
        }

        if ($diffMonthCount > 0) {
            $motivation[] = ['icon' => '🚀', 'text' => 'В этом месяце на <strong>' . $diffMonthCount . '</strong> активн. больше, чем в прошлом. Так держать!'];
        } elseif ($diffMonthCount < 0 && $prevMonth['count'] > 0) {
            $motivation[] = ['icon' => '⏳', 'text' => 'До конца месяца есть время обогнать прошлый. Осталось <strong>' . abs($diffMonthCount) . '</strong>.'];
        }

        if ($weekProgress >= 100) {
            $motivation[] = ['icon' => '🏆', 'text' => 'Недельная цель по дистанции выполнена на <strong>100%</strong>!'];
        } elseif ($weekProgress > 0) {
            $motivation[] = ['icon' => '🎯', 'text' => 'До недельной цели <strong>' . round(100 - $weekProgress) . '%</strong> — это <strong>' . e(format_distance($weeklyGoalDistance - $statsWeek['distance'])) . '</strong>.'];
        }
    ?>

    <?php if ($motivation): ?>
        <div class="motivation">
            <?php foreach ($motivation as $m): ?>
                <div class="motivation__item">
                    <span class="motivation__icon"><?= $m['icon'] ?></span>
                    <span class="motivation__text"><?= $m['text'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ============ ЦЕЛЬ НА НЕДЕЛЮ ============ -->
    <div class="cal-goal">
        <div class="cal-goal__head">
            <h2 class="cal-goal__title">🎯 Цель на неделю</h2>
            <span class="muted"><?= e(date('d.m', strtotime($weekStart))) ?> — <?= e(date('d.m', strtotime($weekEnd))) ?></span>
        </div>
        <div class="cal-goal__row">
            <div class="cal-goal__label">Дистанция</div>
            <div class="cal-goal__bar">
                <div class="cal-goal__fill" style="width:<?= $weekProgress ?>%"></div>
            </div>
            <div class="cal-goal__value">
                <?= e(format_distance($statsWeek['distance'])) ?> / <?= e(format_distance($weeklyGoalDistance)) ?>
                <strong>(<?= $weekProgress ?>%)</strong>
            </div>
        </div>
        <div class="cal-goal__row">
            <div class="cal-goal__label">Количество</div>
            <div class="cal-goal__bar">
                <div class="cal-goal__fill cal-goal__fill--alt" style="width:<?= $weekCountProgress ?>%"></div>
            </div>
            <div class="cal-goal__value">
                <?= (int)$statsWeek['count'] ?> / <?= (int)$weeklyGoalCount ?>
                <strong>(<?= $weekCountProgress ?>%)</strong>
            </div>
        </div>
    </div>

    <!-- ============ КАЛЕНДАРЬ-СЕТКА ============ -->
    <div class="calendar-card">
        <div class="calendar-card__head">
            <h2 class="calendar-card__title">Год активности</h2>
            <div class="calendar-legend">
                <span class="muted">меньше</span>
                <span class="cal-cell cal-cell--l0"></span>
                <span class="cal-cell cal-cell--l1"></span>
                <span class="cal-cell cal-cell--l2"></span>
                <span class="cal-cell cal-cell--l3"></span>
                <span class="cal-cell cal-cell--l4"></span>
                <span class="muted">больше</span>
            </div>
        </div>

        <div class="calendar-grid-wrap">
            <div class="calendar-grid">
                <?php foreach ($calendar as $week): ?>
                    <div class="cal-col">
                        <?php foreach ($week as $day): ?>
                            <?php
                                $level = activity_level($day['data'], $maxDayDistance);
                                $dateLabel = date('d.m.Y', strtotime($day['date']));
                                $isToday = $day['date'] === $today;

                                if ($day['inFuture']) {
                                    echo '<span class="cal-cell cal-cell--future"></span>';
                                    continue;
                                }

                                if ($day['data']) {
                                    $title = $dateLabel
                                           . ' · ' . $day['data']['count'] . ' активн.'
                                           . ' · ' . format_distance($day['data']['distance'])
                                           . ' · ' . format_sec_to_hm((int)$day['data']['duration']);
                                    $link = url('calendar.php?date=' . $day['date']);
                                } else {
                                    $title = $dateLabel . ' · нет активностей';
                                    $link = null;
                                }
                            ?>
                            <?php if ($link): ?>
                                <a href="<?= e($link) ?>"
                                   class="cal-cell cal-cell--l<?= $level ?> <?= $isToday ? 'cal-cell--today' : '' ?>"
                                   title="<?= e($title) ?>"></a>
                            <?php else: ?>
                                <span class="cal-cell cal-cell--l<?= $level ?> <?= $isToday ? 'cal-cell--today' : '' ?>"
                                      title="<?= e($title) ?>"></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="calendar-months">
            <?php
                $seenMonths = [];
                foreach ($calendar as $week) {
                    $m = date('M', strtotime($week[0]['date']));
                    if (!in_array($m, $seenMonths, true)) {
                        $seenMonths[] = $m;
                        echo '<span class="cal-month">' . e($m) . '</span>';
                    }
                }
            ?>
        </div>
    </div>

    <!-- ============ СВОДНАЯ СТАТИСТИКА ПО ПЕРИОДАМ ============ -->
    <h2 class="profile-section-title" style="margin-top:32px">Статистика по периодам</h2>

    <div class="cal-periods">
        <?php
        $periods = [
            ['label' => 'Сегодня',      'data' => $statsToday ? [
                'count' => $statsToday['count'],
                'distance' => $statsToday['distance'],
                'duration' => $statsToday['duration'],
                'elevation' => $statsToday['elevation'],
                'calories' => $statsToday['calories'],
            ] : ['count'=>0,'distance'=>0,'duration'=>0,'elevation'=>0,'calories'=>0]],
            ['label' => 'Эта неделя',   'data' => $statsWeek],
            ['label' => 'Этот месяц',   'data' => $statsMonth],
            ['label' => 'Этот год',     'data' => $statsYear],
        ];
        foreach ($periods as $p):
            $d = $p['data'];
        ?>
            <div class="cal-period">
                <div class="cal-period__label"><?= e($p['label']) ?></div>
                <div class="cal-period__main"><?= e(format_distance((float)$d['distance'])) ?></div>
                <div class="cal-period__sub">
                    <span><strong><?= (int)$d['count'] ?></strong> активн.</span>
                    <span><strong><?= e(format_sec_to_hm((int)$d['duration'])) ?></strong></span>
                    <?php if ($d['elevation'] > 0): ?>
                        <span>↑<strong><?= (int)$d['elevation'] ?> м</strong></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ============ СРАВНЕНИЕ С ПРОШЛЫМИ ПЕРИОДАМИ ============ -->
    <h2 class="profile-section-title" style="margin-top:32px">Сравнение с прошлыми периодами</h2>

    <div class="cal-compare">
        <div class="cal-compare__row">
            <div class="cal-compare__label">Эта неделя vs прошлая</div>
            <div class="cal-compare__current"><?= e(format_distance($statsWeek['distance'])) ?></div>
            <div class="cal-compare__prev">
                прошлая: <?= e(format_distance($prevWeek['distance'])) ?>
            </div>
            <div class="cal-compare__diff <?= $diffWeekDistance >= 0 ? 'is-up' : 'is-down' ?>">
                <?= $diffWeekDistance >= 0 ? '↑' : '↓' ?>
                <?= e(format_distance(abs($diffWeekDistance))) ?>
            </div>
        </div>
        <div class="cal-compare__row">
            <div class="cal-compare__label">Этот месяц vs прошлый</div>
            <div class="cal-compare__current"><?= e(format_distance($statsMonth['distance'])) ?></div>
            <div class="cal-compare__prev">
                прошлый: <?= e(format_distance($prevMonth['distance'])) ?>
            </div>
            <div class="cal-compare__diff <?= $diffMonthDist >= 0 ? 'is-up' : 'is-down' ?>">
                <?= $diffMonthDist >= 0 ? '↑' : '↓' ?>
                <?= e(format_distance(abs($diffMonthDist))) ?>
            </div>
        </div>
        <div class="cal-compare__row">
            <div class="cal-compare__label">Этот год vs прошлый</div>
            <div class="cal-compare__current"><?= e(format_distance($statsYear['distance'])) ?></div>
            <div class="cal-compare__prev">
                прошлый: <?= e(format_distance($prevYear['distance'])) ?>
            </div>
            <?php $diffYearDist = $statsYear['distance'] - $prevYear['distance']; ?>
            <div class="cal-compare__diff <?= $diffYearDist >= 0 ? 'is-up' : 'is-down' ?>">
                <?= $diffYearDist >= 0 ? '↑' : '↓' ?>
                <?= e(format_distance(abs($diffYearDist))) ?>
            </div>
        </div>
    </div>

    <!-- ============ РЕКОРДЫ ============ -->
    <h2 class="profile-section-title" style="margin-top:32px">Личные рекорды</h2>

    <div class="cal-records">
        <div class="cal-record">
            <div class="cal-record__icon">🏅</div>
            <div class="cal-record__label">Лучший день</div>
            <div class="cal-record__value">
                <?php if ($bestDay): ?>
                    <?= e(format_distance((float)$bestDay['distance'])) ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <?php if ($bestDay): ?>
                <div class="cal-record__hint"><?= e(date('d.m.Y', strtotime($bestDay['date']))) ?></div>
            <?php endif; ?>
        </div>

        <div class="cal-record">
            <div class="cal-record__icon">📅</div>
            <div class="cal-record__label">Лучшая неделя</div>
            <div class="cal-record__value">
                <?php if ($bestWeek): ?>
                    <?= e(format_distance((float)$bestWeek['distance'])) ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <?php if ($bestWeek): ?>
                <div class="cal-record__hint">
                    <?= e(date('d.m', strtotime($bestWeek['start']))) ?> — <?= e(date('d.m', strtotime($bestWeek['end']))) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="cal-record">
            <div class="cal-record__icon">🗓️</div>
            <div class="cal-record__label">Лучший месяц</div>
            <div class="cal-record__value">
                <?php if ($bestMonth): ?>
                    <?= e(format_distance((float)$bestMonth['distance'])) ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <?php if ($bestMonth): ?>
                <div class="cal-record__hint"><?= e(date('F Y', strtotime($bestMonth['month'] . '-01'))) ?></div>
            <?php endif; ?>
        </div>

        <div class="cal-record">
            <div class="cal-record__icon">⚡</div>
            <div class="cal-record__label">Лучшая серия</div>
            <div class="cal-record__value"><?= (int)$bestStreak ?> дн.</div>
            <div class="cal-record__hint">подряд без пропусков</div>
        </div>

        <?php if ($longestActivity): ?>
            <div class="cal-record">
                <div class="cal-record__icon">📏</div>
                <div class="cal-record__label">Самая длинная</div>
                <div class="cal-record__value"><?= e(format_distance((float)$longestActivity['distance_m'])) ?></div>
                <div class="cal-record__hint"><?= e((string)$longestActivity['title']) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($fastestActivity): ?>
            <div class="cal-record">
                <div class="cal-record__icon">🚀</div>
                <div class="cal-record__label">Макс. скорость</div>
                <div class="cal-record__value">
                    <?= number_format((float)$fastestActivity['avg_speed_mps'] * 3.6, 1, '.', '') ?> км/ч
                </div>
                <div class="cal-record__hint"><?= e((string)$fastestActivity['title']) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($biggestElevation): ?>
            <div class="cal-record">
                <div class="cal-record__icon">⛰️</div>
                <div class="cal-record__label">Макс. набор</div>
                <div class="cal-record__value"><?= (int)$biggestElevation['elevation_gain_m'] ?> м</div>
                <div class="cal-record__hint"><?= e((string)$biggestElevation['title']) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============ ГРАФИКИ: НЕДЕЛИ И МЕСЯЦЫ ============ -->
    <h2 class="profile-section-title" style="margin-top:32px">Динамика</h2>

    <div class="cal-chart">
        <div class="cal-chart__head">
            <h2>Последние 12 недель</h2>
            <div class="cal-chart__legend">
                <span class="cal-chart__dot" style="background:#ff5a1f"></span> Дистанция
            </div>
        </div>
        <div class="cal-chart__bars">
            <?php foreach ($weeks as $w): ?>
                <?php
                    $h = $w['distance'] > 0 ? max(4, (int)round(($w['distance'] / $maxWeekDistance) * 100)) : 2;
                    $km = round($w['distance'] / 1000, 1);
                ?>
                <div class="cal-bar-wrap" title="<?= $w['label'] ?>: <?= $km ?> км, <?= (int)$w['count'] ?> активн.">
                    <div class="cal-bar" style="height:<?= $h ?>%"></div>
                    <div class="cal-bar__label"><?= e($w['label']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="cal-chart">
        <div class="cal-chart__head">
            <h2>Месяцы этого года</h2>
            <div class="cal-chart__legend">
                <span class="cal-chart__dot" style="background:#ff5a1f"></span> Дистанция
            </div>
        </div>
        <div class="cal-chart__bars">
            <?php foreach ($monthsYear as $m): ?>
                <?php
                    $dist = (float)$m['data']['distance'];
                    $h = $dist > 0 ? max(4, (int)round(($dist / $maxMonthDistance) * 100)) : 2;
                    $km = round($dist / 1000, 1);
                ?>
                <div class="cal-bar-wrap <?= $m['future'] ? 'is-future' : '' ?>"
                     title="<?= e($m['label']) ?>: <?= $km ?> км, <?= (int)$m['data']['count'] ?> активн.">
                    <div class="cal-bar" style="height:<?= $h ?>%"></div>
                    <div class="cal-bar__label"><?= e($m['label']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============ СРЕДНИЕ ЗА ГОД ============ -->
    <h2 class="profile-section-title" style="margin-top:32px">Средние за год</h2>

    <div class="cal-averages">
        <div class="cal-avg">
            <div class="cal-avg__label">За активность</div>
            <div class="cal-avg__value"><?= e(format_distance($avgDistPerActivity)) ?></div>
            <div class="cal-avg__sub"><?= e(format_sec_to_hm((int)$avgDurationPerActivity)) ?></div>
        </div>
        <div class="cal-avg">
            <div class="cal-avg__label">За неделю</div>
            <div class="cal-avg__value"><?= e(format_distance($avgDistPerWeek)) ?></div>
            <div class="cal-avg__sub">в среднем</div>
        </div>
        <div class="cal-avg">
            <div class="cal-avg__label">Средний темп</div>
            <div class="cal-avg__value">
                <?php if ($avgPaceSecPerKm > 0): ?>
                    <?php
                        $m = intdiv((int)$avgPaceSecPerKm, 60);
                        $s = (int)$avgPaceSecPerKm % 60;
                        echo sprintf('%d:%02d', $m, $s);
                    ?>
                    <small>/км</small>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <div class="cal-avg__sub">по всем активностям</div>
        </div>
        <div class="cal-avg">
            <div class="cal-avg__label">Активных дней</div>
            <div class="cal-avg__value"><?= (int)$activeDays ?></div>
            <div class="cal-avg__sub">из <?= (int)$daysSinceYearStart ?> в году</div>
        </div>
        <div class="cal-avg">
            <div class="cal-avg__label">Дней отдыха</div>
            <div class="cal-avg__value"><?= (int)$restDays ?></div>
            <div class="cal-avg__sub">макс. серия: <?= (int)$longestRest ?> дн.</div>
        </div>
        <?php if ($avgHrYear !== null): ?>
            <div class="cal-avg">
                <div class="cal-avg__label">Средний пульс</div>
                <div class="cal-avg__value"><?= (int)$avgHrYear ?><small> уд/мин</small></div>
                <div class="cal-avg__sub">по всем активностям с датчиком</div>
            </div>
        <?php endif; ?>
        <?php if ($avgPwrYear !== null): ?>
            <div class="cal-avg">
                <div class="cal-avg__label">Средняя мощность</div>
                <div class="cal-avg__value"><?= (int)$avgPwrYear ?><small> Вт</small></div>
                <div class="cal-avg__sub">по всем активностям с мощемером</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============ ЗА ВСЁ ВРЕМЯ ============ -->
    <h2 class="profile-section-title" style="margin-top:32px">За всё время</h2>

    <div class="cal-alltime">
        <div class="cal-alltime__cell">
            <div class="cal-alltime__value"><?= (int)$totalsAllTime['count'] ?></div>
            <div class="cal-alltime__label">активностей</div>
        </div>
        <div class="cal-alltime__cell">
            <div class="cal-alltime__value"><?= e(format_distance((float)$totalsAllTime['distance'])) ?></div>
            <div class="cal-alltime__label">всего дистанции</div>
        </div>
        <div class="cal-alltime__cell">
            <div class="cal-alltime__value"><?= e(format_sec_to_hm((int)$totalsAllTime['duration'])) ?></div>
            <div class="cal-alltime__label">всего времени</div>
        </div>
        <div class="cal-alltime__cell">
            <div class="cal-alltime__value"><?= (int)$totalsAllTime['elevation'] ?> м</div>
            <div class="cal-alltime__label">суммарный набор</div>
        </div>
        <?php if ($totalsAllTime['calories'] > 0): ?>
            <div class="cal-alltime__cell">
                <div class="cal-alltime__value"><?= number_format((int)$totalsAllTime['calories'], 0, '.', ' ') ?></div>
                <div class="cal-alltime__label">ккал</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============ ПАТТЕРНЫ ============ -->
    <?php if ($bestWeekday || $bestHour): ?>
        <h2 class="profile-section-title" style="margin-top:32px">Паттерны</h2>
        <div class="cal-patterns">
            <?php if ($bestWeekday): ?>
                <div class="cal-pattern">
                    <span class="cal-pattern__icon">📆</span>
                    <div>
                        <div class="cal-pattern__label">Любимый день недели</div>
                        <div class="cal-pattern__value"><?= e($weekdayLabels[$bestWeekday] ?? $bestWeekday) ?></div>
                        <div class="cal-pattern__hint"><?= (int)$bestWeekdayCount ?> активн. за год</div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($bestHour): ?>
                <div class="cal-pattern">
                    <span class="cal-pattern__icon">🕐</span>
                    <div>
                        <div class="cal-pattern__label">Любимое время</div>
                        <div class="cal-pattern__value"><?= e($bestHour) ?>:00</div>
                        <div class="cal-pattern__hint"><?= (int)$bestHourCount ?> активн. за год</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ============ ТОП-5 АКТИВНОСТЕЙ ============ -->
    <?php if ($topActivities): ?>
        <h2 class="profile-section-title" style="margin-top:32px">Топ-5 по дистанции за год</h2>
        <div class="cal-top">
            <?php foreach ($topActivities as $i => $a): ?>
                <a class="cal-top__row" href="<?= e(url('activity.php?id=' . (int)$a['id'])) ?>">
                    <span class="cal-top__rank"><?= $i + 1 ?></span>
                    <span class="cal-top__icon"><?= e(activity_icon((string)$a['type'])) ?></span>
                    <span class="cal-top__title"><?= e((string)$a['title']) ?></span>
                    <span class="cal-top__meta muted">
                        <?= e(date('d.m.Y', strtotime((string)($a['started_at'] ?? $a['created_at'])))) ?>
                    </span>
                    <span class="cal-top__value"><?= e(format_distance((float)$a['distance_m'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ============ РАСПРЕДЕЛЕНИЕ ПО ТИПАМ ============ -->
    <?php if ($byType): ?>
        <?php
            uasort($byType, fn($a, $b) => $b['count'] <=> $a['count']);
            $totalCount = array_sum(array_column($byType, 'count'));
        ?>
        <h2 class="profile-section-title" style="margin-top:32px">По видам активности</h2>
        <div class="cal-types">
            <?php foreach ($byType as $type => $data): ?>
                <?php $percent = $totalCount > 0 ? round(($data['count'] / $totalCount) * 100, 0) : 0; ?>
                <div class="cal-type-row">
                    <div class="cal-type-row__head">
                        <span class="cal-type-row__icon"><?= e(activity_icon((string)$type)) ?></span>
                        <span class="cal-type-row__name"><?= e(activity_label((string)$type)) ?></span>
                        <span class="cal-type-row__count">
                            <?= (int)$data['count'] ?> · <?= e(format_distance($data['distance'])) ?>
                            · <?= $percent ?>%
                        </span>
                    </div>
                    <div class="cal-type-row__bar">
                        <div class="cal-type-row__fill" style="width:<?= $percent ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
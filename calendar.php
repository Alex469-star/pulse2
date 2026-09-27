<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Activity.php';

auth_start();
$me = require_login();

$userId = (int)$me['id'];

// ============================================================
// ЗАГРУЗКА АКТИВНОСТЕЙ ЗА 365 ДНЕЙ
// ============================================================

$activities = [];
$byDate = [];      // ['2026-09-26' => ['count' => 2, 'distance' => 12500, 'duration' => 3600, 'ids' => [1,2]]]
$byType = [];      // ['run' => ['count' => 5, 'distance' => 50000]]
$byMonth = [];     // ['2026-09' => ['count' => 5, 'distance' => 50000]]

try {
    $stmt = db()->prepare(
        'SELECT id, type, title, distance_m, duration_sec, started_at, created_at
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

foreach ($activities as $a) {
    $dateKey  = date('Y-m-d', strtotime((string)($a['started_at'] ?? $a['created_at'])));
    $monthKey = date('Y-m', strtotime((string)($a['started_at'] ?? $a['created_at'])));
    $typeKey  = (string)$a['type'];
    $dist     = (float)($a['distance_m'] ?? 0);
    $dur      = (int)($a['duration_sec'] ?? 0);

    if (!isset($byDate[$dateKey])) {
        $byDate[$dateKey] = ['count' => 0, 'distance' => 0, 'duration' => 0, 'ids' => []];
    }
    $byDate[$dateKey]['count']++;
    $byDate[$dateKey]['distance'] += $dist;
    $byDate[$dateKey]['duration'] += $dur;
    $byDate[$dateKey]['ids'][] = (int)$a['id'];

    if (!isset($byType[$typeKey])) {
        $byType[$typeKey] = ['count' => 0, 'distance' => 0, 'duration' => 0];
    }
    $byType[$typeKey]['count']++;
    $byType[$typeKey]['distance'] += $dist;
    $byType[$typeKey]['duration'] += $dur;

    if (!isset($byMonth[$monthKey])) {
        $byMonth[$monthKey] = ['count' => 0, 'distance' => 0, 'duration' => 0];
    }
    $byMonth[$monthKey]['count']++;
    $byMonth[$monthKey]['distance'] += $dist;
    $byMonth[$monthKey]['duration'] += $dur;
}

// ============================================================
// МЕТРИКИ ЗА РАЗНЫЕ ПЕРИОДЫ
// ============================================================

function sum_period(array $byDate, string $from, string $to): array
{
    $out = ['count' => 0, 'distance' => 0, 'duration' => 0];
    foreach ($byDate as $date => $data) {
        if ($date >= $from && $date <= $to) {
            $out['count']    += $data['count'];
            $out['distance'] += $data['distance'];
            $out['duration'] += $data['duration'];
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

// За прошлый месяц — для сравнения
$prevMonthFrom = date('Y-m-01', strtotime('first day of last month'));
$prevMonthTo   = date('Y-m-t', strtotime('last day of last month'));
$prevMonth     = sum_period($byDate, $prevMonthFrom, $prevMonthTo);

// Сравнение текущего месяца с прошлым
$monthDiffCount = $statsMonth['count'] - $prevMonth['count'];
$monthDiffDist  = $statsMonth['distance'] - $prevMonth['distance'];

// Streak — сколько дней подряд есть активность (с сегодня в прошлое)
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

// Разбиваем по неделям для графика (последние 12 недель)
$weeks = [];
for ($i = 11; $i >= 0; $i--) {
    $start = date('Y-m-d', strtotime('monday this week -' . $i . ' week'));
    $end   = date('Y-m-d', strtotime('sunday this week -' . $i . ' week'));
    $weeks[] = [
        'label'    => date('d.m', strtotime($start)),
        'count'    => 0,
        'distance' => 0,
        'duration' => 0,
    ];
    $idx = count($weeks) - 1;
    foreach ($byDate as $date => $data) {
        if ($date >= $start && $date <= $end) {
            $weeks[$idx]['count']    += $data['count'];
            $weeks[$idx]['distance'] += $data['distance'];
            $weeks[$idx]['duration'] += $data['duration'];
        }
    }
}

// Максимум по неделям — для масштабирования графика
$maxWeekDistance = max(1, max(array_column($weeks, 'distance')));

// ============================================================
// СЕТКА КАЛЕНДАРЯ ЗА ПОСЛЕДНИЕ 365 ДНЕЙ
// ============================================================
// Строим колонки по неделям, как у GitHub. Начинаем с понедельника,
// заканчиваем воскресеньем.

$calendarStart = date('Y-m-d', strtotime('monday this week -52 week'));
$calendarEnd   = date('Y-m-d', strtotime('sunday this week'));

$calendar = [];  // массив колонок (недель), каждая — 7 дней
$currentDate = $calendarStart;

while ($currentDate <= $calendarEnd) {
    $week = [];
    for ($d = 0; $d < 7; $d++) {
        $week[] = [
            'date'  => $currentDate,
            'data'  => $byDate[$currentDate] ?? null,
            'inFuture' => $currentDate > $today,
        ];
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }
    $calendar[] = $week;
}

// ============================================================
// ОПРЕДЕЛЯЕМ УРОВЕНЬ АКТИВНОСТИ ДЛЯ ЦВЕТА ЯЧЕЙКИ
// ============================================================
// 0 — нет, 1 — мало, 2 — средне, 3 — много, 4 — максимум

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

$maxDayDistance = 1;
foreach ($byDate as $d) {
    if ($d['distance'] > $maxDayDistance) $maxDayDistance = $d['distance'];
}

$pageTitle = 'Календарь активностей';
require __DIR__ . '/includes/header.php';

function activity_icon(string $type): string
{
    return match ($type) {
        'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
        'walk' => '🚶', 'hike' => '🥾', default => '📦',
    };
}
function activity_label(string $type): string
{
    return match ($type) {
        'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
        'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
        default => 'Другое',
    };
}
?>

<section class="calendar-page">
    <header class="calendar-page__head">
        <div>
            <h1 class="section-title" style="text-align:left;margin-bottom:4px">Календарь активностей</h1>
            <p class="muted">Твой год в тренировках</p>
        </div>
        <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--primary">+ Активность</a>
    </header>

    <!-- ============================================================
         МОТИВАЦИОННЫЙ БЛОК
         ============================================================ -->
    <?php
        $motivation = [];
        if ($statsWeek['count'] === 0) {
            $motivation[] = ['icon' => '💪', 'text' => 'На этой неделе ещё нет активностей. Начни сегодня — загрузи первую тренировку.'];
        } else {
            $motivation[] = ['icon' => '🔥', 'text' => 'На этой неделе уже <strong>' . (int)$statsWeek['count'] . '</strong> активностей на <strong>' . e(format_distance($statsWeek['distance'])) . '</strong>. Так держать!'];
        }

        if ($streak >= 3) {
            $motivation[] = ['icon' => '⚡', 'text' => 'Серия из <strong>' . $streak . ' дней подряд</strong>. Не разрывай цепочку!'];
        }

        if ($monthDiffCount > 0) {
            $motivation[] = ['icon' => '📈', 'text' => 'В этом месяце на <strong>' . $monthDiffCount . '</strong> активностей больше, чем в прошлом. Растёшь!'];
        } elseif ($monthDiffCount < 0) {
            $motivation[] = ['icon' => '📉', 'text' => 'В этом месяце на <strong>' . abs($monthDiffCount) . '</strong> активностей меньше, чем в прошлом. Время наверстать!'];
        } elseif ($statsMonth['count'] > 0) {
            $motivation[] = ['icon' => '🎯', 'text' => 'Ты держишь темп с прошлым месяцем. Добавь одну активность — и выйдешь вперёд.'];
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

    <!-- ============================================================
         КАЛЕНДАРЬ-СЕТКА
         ============================================================ -->
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
                <?php foreach ($calendar as $weekIndex => $week): ?>
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
                                           . ' · ' . format_distance($day['data']['distance']);
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

    <!-- ============================================================
         СВОДНАЯ СТАТИСТИКА
         ============================================================ -->
    <div class="cal-stats">
        <div class="cal-stat">
            <span class="cal-stat__label">Эта неделя</span>
            <span class="cal-stat__value"><?= (int)$statsWeek['count'] ?></span>
            <span class="cal-stat__sub"><?= e(format_distance($statsWeek['distance'])) ?></span>
        </div>
        <div class="cal-stat">
            <span class="cal-stat__label">Этот месяц</span>
            <span class="cal-stat__value"><?= (int)$statsMonth['count'] ?></span>
            <span class="cal-stat__sub"><?= e(format_distance($statsMonth['distance'])) ?></span>
        </div>
        <div class="cal-stat">
            <span class="cal-stat__label">Этот год</span>
            <span class="cal-stat__value"><?= (int)$statsYear['count'] ?></span>
            <span class="cal-stat__sub"><?= e(format_distance($statsYear['distance'])) ?></span>
        </div>
        <div class="cal-stat">
            <span class="cal-stat__label">Серия</span>
            <span class="cal-stat__value"><?= $streak ?></span>
            <span class="cal-stat__sub"><?= $streak === 1 ? 'день' : ($streak < 5 ? 'дня' : 'дней') ?> подряд</span>
        </div>
    </div>

    <!-- ============================================================
         ГРАФИК ПО НЕДЕЛЯМ
         ============================================================ -->
    <div class="cal-chart">
        <div class="cal-chart__head">
            <h2>Динамика за 12 недель</h2>
            <div class="cal-chart__legend">
                <span class="cal-chart__dot" style="background:#ff5a1f"></span> Дистанция, км
            </div>
        </div>

        <div class="cal-chart__bars">
            <?php foreach ($weeks as $w): ?>
                <?php
                    $h = $w['distance'] > 0
                        ? max(4, (int)round(($w['distance'] / $maxWeekDistance) * 100))
                        : 2;
                    $km = round($w['distance'] / 1000, 1);
                ?>
                <div class="cal-bar-wrap" title="<?= $w['label'] ?>: <?= $km ?> км, <?= (int)$w['count'] ?> активн.">
                    <div class="cal-bar" style="height:<?= $h ?>%"></div>
                    <div class="cal-bar__label"><?= e($w['label']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============================================================
         РАСПРЕДЕЛЕНИЕ ПО ТИПАМ АКТИВНОСТИ
         ============================================================ -->
    <?php if ($byType): ?>
        <?php
            // Сортируем по количеству
            uasort($byType, fn($a, $b) => $b['count'] <=> $a['count']);
            $totalCount = array_sum(array_column($byType, 'count'));
        ?>
        <div class="cal-types">
            <h2 class="cal-types__title">По видам активности</h2>
            <?php foreach ($byType as $type => $data): ?>
                <?php $percent = $totalCount > 0 ? round(($data['count'] / $totalCount) * 100, 0) : 0; ?>
                <div class="cal-type-row">
                    <div class="cal-type-row__head">
                        <span class="cal-type-row__icon"><?= e(activity_icon((string)$type)) ?></span>
                        <span class="cal-type-row__name"><?= e(activity_label((string)$type)) ?></span>
                        <span class="cal-type-row__count"><?= (int)$data['count'] ?> · <?= e(format_distance($data['distance'])) ?></span>
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
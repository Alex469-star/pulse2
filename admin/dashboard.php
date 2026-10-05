<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('readonly');

// Сводные метрики
$stats = [];

try {
    $stats['users_total']       = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['users_today']       = (int)db()->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $stats['users_week']        = (int)db()->query("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
    $stats['users_banned']      = (int)db()->query('SELECT COUNT(*) FROM users WHERE is_banned = 1')->fetchColumn();
    $stats['users_online']      = (int)db()->query("SELECT COUNT(*) FROM users WHERE last_seen_at >= NOW() - INTERVAL 5 MINUTE")->fetchColumn();

    $stats['activities_total']  = (int)db()->query('SELECT COUNT(*) FROM activities')->fetchColumn();
    $stats['activities_today']  = (int)db()->query("SELECT COUNT(*) FROM activities WHERE DATE(COALESCE(started_at, created_at)) = CURDATE()")->fetchColumn();
    $stats['activities_week']   = (int)db()->query("SELECT COUNT(*) FROM activities WHERE COALESCE(started_at, created_at) >= NOW() - INTERVAL 7 DAY")->fetchColumn();
    $stats['distance_total_km'] = round((float)db()->query('SELECT COALESCE(SUM(distance_m),0) FROM activities')->fetchColumn() / 1000, 1);
    $stats['distance_week_km']  = round((float)db()->query("SELECT COALESCE(SUM(distance_m),0) FROM activities WHERE COALESCE(started_at, created_at) >= NOW() - INTERVAL 7 DAY")->fetchColumn() / 1000, 1);

    $stats['posts_total']       = (int)db()->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    $stats['comments_total']    = (int)db()->query('SELECT COUNT(*) FROM activity_comments')->fetchColumn()
                                + (int)db()->query('SELECT COUNT(*) FROM post_comments')->fetchColumn();
    $stats['likes_total']       = (int)db()->query('SELECT COUNT(*) FROM activity_likes')->fetchColumn()
                                + (int)db()->query('SELECT COUNT(*) FROM post_likes')->fetchColumn();
    $stats['segments_total']    = (int)db()->query('SELECT COUNT(*) FROM segments')->fetchColumn();
    $stats['routes_total']      = (int)db()->query('SELECT COUNT(*) FROM routes')->fetchColumn();
    $stats['gear_total']        = (int)db()->query('SELECT COUNT(*) FROM gear')->fetchColumn();

    // Размер БД
    $dbName = (string)config('db.database');
    $s = db()->prepare(
        'SELECT SUM(data_length + index_length) AS size
           FROM information_schema.tables
          WHERE table_schema = ?'
    );
    $s->execute([$dbName]);
    $stats['db_size'] = (int)$s->fetchColumn();
} catch (\Throwable $e) {
    $stats = array_fill_keys(array_keys($stats), 0);
}

// Активность за 30 дней — для графика
$chartDaily = [];
try {
    $s = db()->query(
        "SELECT DATE(COALESCE(started_at, created_at)) AS d,
                COUNT(*) AS cnt
           FROM activities
          WHERE COALESCE(started_at, created_at) >= NOW() - INTERVAL 30 DAY
          GROUP BY DATE(COALESCE(started_at, created_at))
          ORDER BY d ASC"
    );
    foreach ($s->fetchAll() as $row) {
        $chartDaily[] = ['date' => $row['d'], 'cnt' => (int)$row['cnt']];
    }
} catch (\Throwable $e) {}

// Новые пользователи за 30 дней
$chartUsers = [];
try {
    $s = db()->query(
        "SELECT DATE(created_at) AS d, COUNT(*) AS cnt
           FROM users
          WHERE created_at >= NOW() - INTERVAL 30 DAY
          GROUP BY DATE(created_at)
          ORDER BY d ASC"
    );
    foreach ($s->fetchAll() as $row) {
        $chartUsers[] = ['date' => $row['d'], 'cnt' => (int)$row['cnt']];
    }
} catch (\Throwable $e) {}

// Последние зарегистрированные
$recentUsers = db()->query(
    'SELECT id, username, display_name, email, created_at, is_banned
       FROM users ORDER BY created_at DESC LIMIT 10'
)->fetchAll();

// Последние активности
$recentActivities = db()->query(
    'SELECT a.id, a.title, a.type, a.distance_m, a.started_at, a.created_at,
            u.username, u.display_name
       FROM activities a JOIN users u ON u.id = a.user_id
      ORDER BY a.id DESC LIMIT 10'
)->fetchAll();

// Последние действия админов
$recentAudit = db()->query(
    'SELECT l.*, a.username
       FROM admin_audit_log l
       LEFT JOIN admins a ON a.id = l.admin_id
      ORDER BY l.id DESC LIMIT 15'
)->fetchAll();

$pageTitle = 'Дашборд';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-kpi-grid">
    <div class="admin-kpi">
        <div class="admin-kpi__label">Пользователи</div>
        <div class="admin-kpi__value"><?= admin_format_int($stats['users_total']) ?></div>
        <div class="admin-kpi__delta <?= $stats['users_week'] > 0 ? 'is-up' : '' ?>">
            +<?= admin_format_int($stats['users_week']) ?> за 7 дней
        </div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Онлайн (5 мин)</div>
        <div class="admin-kpi__value"><?= admin_format_int($stats['users_online']) ?></div>
        <div class="admin-kpi__delta">Активны прямо сейчас</div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Активности</div>
        <div class="admin-kpi__value"><?= admin_format_int($stats['activities_total']) ?></div>
        <div class="admin-kpi__delta <?= $stats['activities_week'] > 0 ? 'is-up' : '' ?>">
            +<?= admin_format_int($stats['activities_week']) ?> за 7 дней
        </div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Объём, км</div>
        <div class="admin-kpi__value"><?= admin_format_int((int)$stats['distance_total_km']) ?></div>
        <div class="admin-kpi__delta">+<?= admin_format_int((int)$stats['distance_week_km']) ?> за неделю</div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Посты</div>
        <div class="admin-kpi__value"><?= admin_format_int($stats['posts_total']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Лайки + комменты</div>
        <div class="admin-kpi__value"><?= admin_format_int($stats['likes_total'] + $stats['comments_total']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Сегменты</div>
        <div class="admin-kpi__value"><?= admin_format_int($stats['segments_total']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Размер БД</div>
        <div class="admin-kpi__value"><?= e(admin_format_bytes($stats['db_size'])) ?></div>
    </div>
</div>

<div class="admin-charts-grid">
    <div class="admin-card">
        <h3 class="admin-card__title">Активности (30 дней)</h3>
        <div class="admin-chart"><canvas id="chart-activities"></canvas></div>
    </div>
    <div class="admin-card">
        <h3 class="admin-card__title">Новые пользователи (30 дней)</h3>
        <div class="admin-chart"><canvas id="chart-users"></canvas></div>
    </div>
</div>

<div class="admin-grid-3">
    <div class="admin-card">
        <h3 class="admin-card__title">Последние пользователи</h3>
        <table class="admin-table">
            <thead><tr><th>Логин</th><th>Дата</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recentUsers as $u): ?>
                <tr>
                    <td>
                        <a href="<?= e(admin_url('user.php?id=' . (int)$u['id'])) ?>">
                            <?= e($u['username']) ?>
                        </a>
                        <?php if ((int)$u['is_banned']): ?><span class="admin-badge admin-badge--danger">бан</span><?php endif; ?>
                    </td>
                    <td class="muted"><?= e(admin_time_ago((string)$u['created_at'])) ?></td>
                    <td><a class="admin-link" href="<?= e(admin_url('user.php?id=' . (int)$u['id'])) ?>">→</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title">Последние активности</h3>
        <table class="admin-table">
            <thead><tr><th>Пользователь</th><th>Тип</th><th>Дист.</th></tr></thead>
            <tbody>
            <?php foreach ($recentActivities as $a): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('activity.php?id=' . (int)$a['id'])) ?>" target="_blank">
                            <?= e($a['display_name']) ?>
                        </a>
                    </td>
                    <td><?= e($a['type']) ?></td>
                    <td><?= e(number_format((float)$a['distance_m'] / 1000, 2, '.', ' ')) ?> км</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title">Последние действия админов</h3>
        <table class="admin-table">
            <thead><tr><th>Админ</th><th>Действие</th><th>Когда</th></tr></thead>
            <tbody>
            <?php foreach ($recentAudit as $l): ?>
                <tr>
                    <td><?= e($l['username'] ?? '—') ?></td>
                    <td><code><?= e($l['action']) ?></code></td>
                    <td class="muted"><?= e(admin_time_ago((string)$l['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.__ADMIN_CHARTS__ = {
    activities: <?= json_encode($chartDaily, JSON_UNESCAPED_UNICODE) ?>,
    users: <?= json_encode($chartUsers, JSON_UNESCAPED_UNICODE) ?>
};
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
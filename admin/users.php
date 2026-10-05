<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('readonly');

// ---- POST: массовые операции ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');
    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));

    if ($ids && in_array($action, ['ban','unban','delete'], true)) {
        admin_require('moderator');

        if ($action === 'delete') {
            admin_require('super');
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        try {
            if ($action === 'ban') {
                db()->prepare("UPDATE users SET is_banned = 1, banned_at = NOW() WHERE id IN ($in)")
                    ->execute($ids);
            } elseif ($action === 'unban') {
                db()->prepare("UPDATE users SET is_banned = 0, banned_at = NULL, banned_until = NULL, ban_reason = NULL WHERE id IN ($in)")
                    ->execute($ids);
            } elseif ($action === 'delete') {
                db()->prepare("DELETE FROM users WHERE id IN ($in)")->execute($ids);
            }
            admin_audit((int)$admin['id'], "users.bulk_$action", 'user', null, ['ids' => $ids]);
            flash("Готово: $action применён к " . count($ids) . ' пользователям', 'success');
        } catch (Throwable $e) {
            flash('Ошибка: ' . $e->getMessage(), 'error');
        }
        redirect(admin_url('users.php'));
    }
}

// ---- Фильтры ----
$q      = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? 'all');    // all | banned | active | verified | unverified
$sort   = (string)($_GET['sort'] ?? 'new');      // new | old | active | activities
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

switch ($filter) {
    case 'banned':     $where[] = 'u.is_banned = 1'; break;
    case 'active':     $where[] = 'u.last_seen_at >= NOW() - INTERVAL 7 DAY'; break;
    case 'verified':   $where[] = 'u.email_verified = 1'; break;
    case 'unverified': $where[] = 'u.email_verified = 0'; break;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderSql = match ($sort) {
    'old'        => 'u.id ASC',
    'active'     => 'u.last_seen_at DESC, u.id DESC',
    'activities' => 'activities_cnt DESC, u.id DESC',
    default      => 'u.id DESC',
};

$countStmt = db()->prepare("SELECT COUNT(*) FROM users u $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT u.id, u.username, u.email, u.display_name, u.avatar_url,
               u.is_public, u.email_verified, u.is_banned, u.banned_until,
               u.created_at, u.last_seen_at,
               (SELECT COUNT(*) FROM activities a WHERE a.user_id = u.id) AS activities_cnt,
               (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS posts_cnt
          FROM users u
          $whereSql
      ORDER BY $orderSql
         LIMIT $perPage OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Пользователи (' . admin_format_int($total) . ')';
require __DIR__ . '/includes/header.php';
?>

<form class="admin-filters" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Логин, email или имя">
    <select name="filter">
        <option value="all"        <?= $filter === 'all' ? 'selected' : '' ?>>Все</option>
        <option value="active"     <?= $filter === 'active' ? 'selected' : '' ?>>Активные (7 дней)</option>
        <option value="banned"     <?= $filter === 'banned' ? 'selected' : '' ?>>Забаненные</option>
        <option value="verified"   <?= $filter === 'verified' ? 'selected' : '' ?>>Email подтверждён</option>
        <option value="unverified" <?= $filter === 'unverified' ? 'selected' : '' ?>>Email не подтверждён</option>
    </select>
    <select name="sort">
        <option value="new"        <?= $sort === 'new' ? 'selected' : '' ?>>Сначала новые</option>
        <option value="old"        <?= $sort === 'old' ? 'selected' : '' ?>>Сначала старые</option>
        <option value="active"     <?= $sort === 'active' ? 'selected' : '' ?>>По активности</option>
        <option value="activities" <?= $sort === 'activities' ? 'selected' : '' ?>>По числу активностей</option>
    </select>
    <button class="btn btn--primary">Найти</button>
    <?php if ($q !== '' || $filter !== 'all'): ?>
        <a class="btn btn--ghost" href="<?= e(admin_url('users.php')) ?>">Сбросить</a>
    <?php endif; ?>
</form>

<form method="post" id="bulk-form">
    <?= admin_csrf_field() ?>

    <div class="admin-card">
        <div class="admin-table-head">
            <label class="admin-checkbox">
                <input type="checkbox" id="check-all"> Выбрать все
            </label>

            <div class="admin-bulk-actions" id="bulk-actions" hidden>
                <span class="muted">Выбрано: <strong id="bulk-count">0</strong></span>
                <button class="btn btn--ghost btn--sm" name="action" value="ban"
                        onclick="return confirm('Заблокировать выбранных?')">🚫 Забанить</button>
                <button class="btn btn--ghost btn--sm" name="action" value="unban">✅ Разбанить</button>
                <?php if ($admin['role'] === 'super'): ?>
                    <button class="btn btn--danger btn--sm" name="action" value="delete"
                            onclick="return confirm('УДАЛИТЬ выбранных? Необратимо.')">🗑 Удалить</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-db__wrap">
            <table class="admin-table admin-table--wide">
                <thead>
                    <tr>
                        <th style="width:32px"></th>
                        <th>ID</th>
                        <th>Пользователь</th>
                        <th>Email</th>
                        <th>Актив.</th>
                        <th>Посты</th>
                        <th>Регистрация</th>
                        <th>Последний визит</th>
                        <th>Статус</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr class="<?= (int)$u['is_banned'] ? 'is-banned' : '' ?>">
                        <td><input type="checkbox" name="ids[]" value="<?= (int)$u['id'] ?>" class="js-row-check"></td>
                        <td><?= (int)$u['id'] ?></td>
                        <td>
                            <a href="<?= e(admin_url('user.php?id=' . (int)$u['id'])) ?>">
                                <strong><?= e($u['display_name'] ?: $u['username']) ?></strong>
                            </a>
                            <div class="muted">@<?= e($u['username']) ?></div>
                        </td>
                        <td>
                            <?= e($u['email']) ?>
                            <?php if ((int)$u['email_verified']): ?>
                                <span class="admin-badge admin-badge--ok">✓</span>
                            <?php endif; ?>
                        </td>
                        <td><?= admin_format_int((int)$u['activities_cnt']) ?></td>
                        <td><?= admin_format_int((int)$u['posts_cnt']) ?></td>
                        <td class="muted"><?= e(date('d.m.Y', strtotime((string)$u['created_at']))) ?></td>
                        <td class="muted"><?= $u['last_seen_at'] ? e(admin_time_ago((string)$u['last_seen_at'])) : '—' ?></td>
                        <td>
                            <?php if ((int)$u['is_banned']): ?>
                                <span class="admin-badge admin-badge--danger">бан</span>
                            <?php else: ?>
                                <span class="admin-badge admin-badge--ok">ok</span>
                            <?php endif; ?>
                        </td>
                        <td><a class="admin-link" href="<?= e(admin_url('user.php?id=' . (int)$u['id'])) ?>">Открыть</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php
$totalPages = (int)ceil($total / $perPage);
if ($totalPages > 1):
?>
<nav class="admin-pagination">
    <?php for ($i = max(1, $page - 5); $i <= min($totalPages, $page + 10); $i++): ?>
        <a class="<?= $i === $page ? 'is-active' : '' ?>"
           href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
    <?php endfor; ?>
</nav>
<?php endif; ?>

<script>
(function () {
    var all = document.getElementById('check-all');
    var checks = document.querySelectorAll('.js-row-check');
    var actions = document.getElementById('bulk-actions');
    var count = document.getElementById('bulk-count');

    function update() {
        var n = document.querySelectorAll('.js-row-check:checked').length;
        count.textContent = n;
        actions.hidden = n === 0;
    }

    all?.addEventListener('change', function () {
        checks.forEach(function (c) { c.checked = all.checked; });
        update();
    });
    checks.forEach(function (c) { c.addEventListener('change', update); });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
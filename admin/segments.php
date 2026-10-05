<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('readonly');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');
    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));

    if ($ids && in_array($action, ['hide','show','delete'], true)) {
        admin_require('moderator');
        if ($action === 'delete') admin_require('super');

        $in = implode(',', array_fill(0, count($ids), '?'));

        try {
            if ($action === 'hide') {
                db()->prepare("UPDATE segments SET is_public = 0 WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'show') {
                db()->prepare("UPDATE segments SET is_public = 1 WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'delete') {
                db()->prepare("DELETE FROM segments WHERE id IN ($in)")->execute($ids);
            }
            admin_audit((int)$admin['id'], "segments.bulk_$action", 'segment', null, ['ids' => $ids]);
            flash("Готово: $action применён к " . count($ids) . ' сегментам', 'success');
        } catch (Throwable $e) {
            flash('Ошибка: ' . $e->getMessage(), 'error');
        }
        redirect(admin_url('segments.php'));
    }
}

$q      = trim((string)($_GET['q'] ?? ''));
$type   = (string)($_GET['type'] ?? '');
$vis    = (string)($_GET['vis'] ?? '');
$sort   = (string)($_GET['sort'] ?? 'new');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($q !== '') { $where[] = 's.name LIKE ?'; $params[] = '%' . $q . '%'; }
if ($type !== '') { $where[] = 's.type = ?'; $params[] = $type; }
if ($vis === 'public')  $where[] = 's.is_public = 1';
if ($vis === 'private') $where[] = 's.is_public = 0';

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderSql = match ($sort) {
    'old'     => 's.id ASC',
    'athletes'=> 'athletes_cnt DESC',
    'longest' => 's.distance_m DESC',
    default   => 's.id DESC',
};

$countStmt = db()->prepare("SELECT COUNT(*) FROM segments s $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT s.id, s.creator_id, s.name, s.type, s.distance_m, s.is_public,
               s.created_at,
               u.username, u.display_name,
               (SELECT COUNT(DISTINCT user_id) FROM segment_efforts e WHERE e.segment_id = s.id) AS athletes_cnt,
               (SELECT COUNT(*) FROM segment_efforts e WHERE e.segment_id = s.id) AS efforts_cnt
          FROM segments s
          JOIN users u ON u.id = s.creator_id
          $whereSql
      ORDER BY $orderSql
         LIMIT $perPage OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$segments = $stmt->fetchAll();

$pageTitle = 'Сегменты (' . admin_format_int($total) . ')';
require __DIR__ . '/includes/header.php';
?>

<form class="admin-filters" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Название сегмента">
    <select name="type">
        <option value="">Все типы</option>
        <?php foreach (['run','ride','swim','ski','walk','hike','other'] as $t): ?>
            <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
    </select>
    <select name="vis">
        <option value="">Все</option>
        <option value="public"  <?= $vis === 'public' ? 'selected' : '' ?>>Публичные</option>
        <option value="private" <?= $vis === 'private' ? 'selected' : '' ?>>Приватные</option>
    </select>
    <select name="sort">
        <option value="new"      <?= $sort === 'new' ? 'selected' : '' ?>>Сначала новые</option>
        <option value="old"      <?= $sort === 'old' ? 'selected' : '' ?>>Сначала старые</option>
        <option value="athletes" <?= $sort === 'athletes' ? 'selected' : '' ?>>По числу спортсменов</option>
        <option value="longest"  <?= $sort === 'longest' ? 'selected' : '' ?>>По дистанции</option>
    </select>
    <button class="btn btn--primary">Найти</button>
</form>

<form method="post">
    <?= admin_csrf_field() ?>

    <div class="admin-card">
        <div class="admin-table-head">
            <label class="admin-checkbox"><input type="checkbox" id="check-all"> Выбрать все</label>
            <div class="admin-bulk-actions" id="bulk-actions" hidden>
                <span class="muted">Выбрано: <strong id="bulk-count">0</strong></span>
                <button class="btn btn--ghost btn--sm" name="action" value="hide">🔒 Скрыть</button>
                <button class="btn btn--ghost btn--sm" name="action" value="show">🌐 Открыть</button>
                <?php if ($admin['role'] === 'super'): ?>
                    <button class="btn btn--danger btn--sm" name="action" value="delete"
                            onclick="return confirm('Удалить сегменты? Необратимо.')">🗑 Удалить</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-db__wrap">
            <table class="admin-table admin-table--wide">
                <thead>
                    <tr>
                        <th style="width:32px"></th>
                        <th>ID</th>
                        <th>Создатель</th>
                        <th>Название</th>
                        <th>Тип</th>
                        <th>Дист.</th>
                        <th>Спортсм.</th>
                        <th>Попыток</th>
                        <th>Видимость</th>
                        <th>Дата</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($segments as $s): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>" class="js-row-check"></td>
                        <td><?= (int)$s['id'] ?></td>
                        <td>
                            <a href="<?= e(admin_url('user.php?id=' . (int)$s['creator_id'])) ?>">
                                <?= e($s['display_name']) ?>
                            </a>
                            <div class="muted">@<?= e($s['username']) ?></div>
                        </td>
                        <td>
                            <a href="<?= e(url('segment.php?id=' . (int)$s['id'])) ?>" target="_blank">
                                <strong><?= e(mb_substr((string)$s['name'], 0, 60)) ?></strong>
                            </a>
                        </td>
                        <td><?= e($s['type']) ?></td>
                        <td><?= e(number_format((float)$s['distance_m'] / 1000, 2, '.', ' ')) ?> км</td>
                        <td><?= (int)$s['athletes_cnt'] ?></td>
                        <td><?= (int)$s['efforts_cnt'] ?></td>
                        <td>
                            <span class="admin-badge <?= (int)$s['is_public'] ? 'admin-badge--ok' : '' ?>">
                                <?= (int)$s['is_public'] ? 'публичный' : 'приватный' ?>
                            </span>
                        </td>
                        <td class="muted"><?= e(date('d.m.Y', strtotime((string)$s['created_at']))) ?></td>
                        <td>
                            <a class="admin-link" href="<?= e(url('segment.php?id=' . (int)$s['id'])) ?>" target="_blank">Открыть</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php $totalPages = (int)ceil($total / $perPage); ?>
<?php if ($totalPages > 1): ?>
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
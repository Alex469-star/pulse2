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

    if ($ids && in_array($action, ['retire','unretire','delete'], true)) {
        admin_require('moderator');
        if ($action === 'delete') admin_require('super');

        $in = implode(',', array_fill(0, count($ids), '?'));

        try {
            if ($action === 'retire') {
                db()->prepare("UPDATE gear SET is_retired = 1 WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'unretire') {
                db()->prepare("UPDATE gear SET is_retired = 0 WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'delete') {
                // Обнуляем привязки активностей перед удалением
                db()->prepare("UPDATE activities SET gear_id = NULL WHERE gear_id IN ($in)")->execute($ids);
                db()->prepare("DELETE FROM gear WHERE id IN ($in)")->execute($ids);
            }
            admin_audit((int)$admin['id'], "gear.bulk_$action", 'gear', null, ['ids' => $ids]);
            flash("Готово: $action применён к " . count($ids) . ' единицам инвентаря', 'success');
        } catch (Throwable $e) {
            flash('Ошибка: ' . $e->getMessage(), 'error');
        }
        redirect(admin_url('gear.php'));
    }
}

// ---- Фильтры ----
$q       = trim((string)($_GET['q'] ?? ''));
$type    = (string)($_GET['type'] ?? '');
$retired = (string)($_GET['retired'] ?? '');    // '' | '0' | '1'
$userId  = (int)($_GET['user_id'] ?? 0);
$sort    = (string)($_GET['sort'] ?? 'new');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(g.name LIKE ? OR g.brand LIKE ? OR g.model LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($type !== '') { $where[] = 'g.type = ?'; $params[] = $type; }
if ($retired === '0') $where[] = 'g.is_retired = 0';
if ($retired === '1') $where[] = 'g.is_retired = 1';
if ($userId > 0) { $where[] = 'g.user_id = ?'; $params[] = $userId; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderSql = match ($sort) {
    'old'      => 'g.id ASC',
    'distance' => 'total_distance_m DESC',
    'name'     => 'g.name ASC',
    default    => 'g.id DESC',
};

$countStmt = db()->prepare("SELECT COUNT(*) FROM gear g $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT g.id, g.user_id, g.type, g.name, g.brand, g.model,
               g.purchase_date, g.photo_url, g.is_retired, g.created_at,
               u.username, u.display_name,
               (SELECT COUNT(*) FROM activities a WHERE a.gear_id = g.id) AS activities_cnt,
               (SELECT COALESCE(SUM(a.distance_m), 0) FROM activities a WHERE a.gear_id = g.id) AS total_distance_m,
               (SELECT COALESCE(SUM(a.duration_sec), 0) FROM activities a WHERE a.gear_id = g.id) AS total_duration_sec
          FROM gear g
          JOIN users u ON u.id = g.user_id
          $whereSql
      ORDER BY $orderSql
         LIMIT $perPage OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$gear = $stmt->fetchAll();

// ---- Сводка ----
$summary = db()->query(
    'SELECT
        COUNT(*) AS total,
        SUM(is_retired = 0) AS active,
        SUM(is_retired = 1) AS retired,
        SUM(type = "bike")   AS bikes,
        SUM(type = "shoes")  AS shoes,
        SUM(type = "skis")   AS skis,
        SUM(type = "other")  AS other
     FROM gear'
)->fetch();

$pageTitle = 'Инвентарь (' . admin_format_int($total) . ')';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-kpi-grid">
    <div class="admin-kpi">
        <div class="admin-kpi__label">Всего единиц</div>
        <div class="admin-kpi__value"><?= admin_format_int((int)$summary['total']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Активный</div>
        <div class="admin-kpi__value"><?= admin_format_int((int)$summary['active']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Списан</div>
        <div class="admin-kpi__value"><?= admin_format_int((int)$summary['retired']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Велосипеды</div>
        <div class="admin-kpi__value"><?= admin_format_int((int)$summary['bikes']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Кроссовки</div>
        <div class="admin-kpi__value"><?= admin_format_int((int)$summary['shoes']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Лыжи</div>
        <div class="admin-kpi__value"><?= admin_format_int((int)$summary['skis']) ?></div>
    </div>
</div>

<form class="admin-filters" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Название, бренд, модель">
    <select name="type">
        <option value="">Все типы</option>
        <option value="bike"  <?= $type === 'bike' ? 'selected' : '' ?>>Велосипеды</option>
        <option value="shoes" <?= $type === 'shoes' ? 'selected' : '' ?>>Кроссовки</option>
        <option value="skis"  <?= $type === 'skis' ? 'selected' : '' ?>>Лыжи</option>
        <option value="other" <?= $type === 'other' ? 'selected' : '' ?>>Другое</option>
    </select>
    <select name="retired">
        <option value="">Любое состояние</option>
        <option value="0" <?= $retired === '0' ? 'selected' : '' ?>>Активный</option>
        <option value="1" <?= $retired === '1' ? 'selected' : '' ?>>Списанный</option>
    </select>
    <input type="number" name="user_id" value="<?= $userId ?: '' ?>" placeholder="ID владельца">
    <select name="sort">
        <option value="new"      <?= $sort === 'new' ? 'selected' : '' ?>>Сначала новые</option>
        <option value="old"      <?= $sort === 'old' ? 'selected' : '' ?>>Сначала старые</option>
        <option value="distance" <?= $sort === 'distance' ? 'selected' : '' ?>>По пробегу</option>
        <option value="name"     <?= $sort === 'name' ? 'selected' : '' ?>>По названию</option>
    </select>
    <button class="btn btn--primary">Найти</button>
    <?php if ($q || $type || $retired !== '' || $userId): ?>
        <a class="btn btn--ghost" href="<?= e(admin_url('gear.php')) ?>">Сбросить</a>
    <?php endif; ?>
</form>

<form method="post">
    <?= admin_csrf_field() ?>

    <div class="admin-card">
        <div class="admin-table-head">
            <label class="admin-checkbox"><input type="checkbox" id="check-all"> Выбрать все</label>
            <div class="admin-bulk-actions" id="bulk-actions" hidden>
                <span class="muted">Выбрано: <strong id="bulk-count">0</strong></span>
                <button class="btn btn--ghost btn--sm" name="action" value="retire">📦 Списать</button>
                <button class="btn btn--ghost btn--sm" name="action" value="unretire">♻ Вернуть в строй</button>
                <?php if ($admin['role'] === 'super'): ?>
                    <button class="btn btn--danger btn--sm" name="action" value="delete"
                            onclick="return confirm('Удалить инвентарь? Привязки активностей будут сброшены.')">🗑 Удалить</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-db__wrap">
            <table class="admin-table admin-table--wide">
                <thead>
                    <tr>
                        <th style="width:32px"></th>
                        <th style="width:56px"></th>
                        <th>ID</th>
                        <th>Владелец</th>
                        <th>Название</th>
                        <th>Тип</th>
                        <th>Актив.</th>
                        <th>Пробег</th>
                        <th>Время в движении</th>
                        <th>Статус</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($gear as $g): ?>
                    <tr class="<?= (int)$g['is_retired'] ? 'is-banned' : '' ?>">
                        <td><input type="checkbox" name="ids[]" value="<?= (int)$g['id'] ?>" class="js-row-check"></td>
                        <td>
                            <?php if (!empty($g['photo_url'])): ?>
                                <img src="<?= e($g['photo_url']) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:8px">
                            <?php else: ?>
                                <span style="font-size:24px">
                                    <?php
                                        echo match ($g['type']) {
                                            'bike'  => '🚴',
                                            'shoes' => '👟',
                                            'skis'  => '⛷️',
                                            default => '🎒',
                                        };
                                    ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$g['id'] ?></td>
                        <td>
                            <a href="<?= e(admin_url('user.php?id=' . (int)$g['user_id'])) ?>">
                                <?= e($g['display_name']) ?>
                            </a>
                            <div class="muted">@<?= e($g['username']) ?></div>
                        </td>
                        <td>
                            <a href="<?= e(admin_url('gear-item.php?id=' . (int)$g['id'])) ?>">
                                <strong><?= e($g['name']) ?></strong>
                            </a>
                            <?php if ($g['brand'] || $g['model']): ?>
                                <div class="muted">
                                    <?= e(trim((string)$g['brand'] . ' ' . (string)$g['model'])) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($g['type']) ?></td>
                        <td><?= admin_format_int((int)$g['activities_cnt']) ?></td>
                        <td><?= e(number_format((float)$g['total_distance_m'] / 1000, 1, '.', ' ')) ?> км</td>
                        <td>
                            <?php
                                $sec = (int)$g['total_duration_sec'];
                                $h = intdiv($sec, 3600);
                                echo $h > 0 ? $h . ' ч' : intdiv($sec, 60) . ' мин';
                            ?>
                        </td>
                        <td>
                            <?php if ((int)$g['is_retired']): ?>
                                <span class="admin-badge admin-badge--danger">списан</span>
                            <?php else: ?>
                                <span class="admin-badge admin-badge--ok">активен</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="admin-link" href="<?= e(admin_url('gear-item.php?id=' . (int)$g['id'])) ?>">Открыть</a>
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
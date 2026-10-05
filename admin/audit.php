<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('moderator');

$action = (string)($_GET['action'] ?? '');
$adminId = (int)($_GET['admin_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($action !== '') { $where[] = 'l.action LIKE ?'; $params[] = '%' . $action . '%'; }
if ($adminId > 0) { $where[] = 'l.admin_id = ?'; $params[] = $adminId; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM admin_audit_log l $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT l.*, a.username
       FROM admin_audit_log l
  LEFT JOIN admins a ON a.id = l.admin_id
       $whereSql
   ORDER BY l.id DESC
      LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'Аудит';
require __DIR__ . '/includes/header.php';
?>

<form class="admin-filters" method="get">
    <input type="text" name="action" value="<?= e($action) ?>" placeholder="Фильтр по действию">
    <input type="number" name="admin_id" value="<?= $adminId ?: '' ?>" placeholder="ID админа">
    <button class="btn btn--primary">Фильтр</button>
</form>

<div class="admin-card">
    <table class="admin-table admin-table--wide">
        <thead><tr><th>Время</th><th>Админ</th><th>Действие</th><th>Объект</th><th>IP</th><th>Данные</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td class="muted"><?= e(date('d.m.Y H:i:s', strtotime((string)$l['created_at']))) ?></td>
                <td><?= e($l['username'] ?? '—') ?></td>
                <td><code><?= e($l['action']) ?></code></td>
                <td><?= $l['target_type'] ? e($l['target_type'] . '#' . $l['target_id']) : '—' ?></td>
                <td class="muted"><?= e((string)$l['ip']) ?></td>
                <td><pre class="admin-code"><?= e((string)$l['payload']) ?></pre></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php $totalPages = (int)ceil($total / $perPage); ?>
<?php if ($totalPages > 1): ?>
<nav class="admin-pagination">
    <?php for ($i = max(1, $page - 5); $i <= min($totalPages, $page + 10); $i++): ?>
        <a class="<?= $i === $page ? 'is-active' : '' ?>"
           href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
    <?php endfor; ?>
</nav>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
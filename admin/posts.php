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
                db()->prepare("UPDATE posts SET visibility = 'private' WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'show') {
                db()->prepare("UPDATE posts SET visibility = 'public' WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'delete') {
                db()->prepare("DELETE FROM posts WHERE id IN ($in)")->execute($ids);
            }
            admin_audit((int)$admin['id'], "posts.bulk_$action", 'post', null, ['ids' => $ids]);
            flash("Готово: $action применён к " . count($ids) . ' постам', 'success');
        } catch (Throwable $e) {
            flash('Ошибка: ' . $e->getMessage(), 'error');
        }
        redirect(admin_url('posts.php'));
    }
}

$q       = trim((string)($_GET['q'] ?? ''));
$vis     = (string)($_GET['vis'] ?? '');
$userId  = (int)($_GET['user_id'] ?? 0);
$sort    = (string)($_GET['sort'] ?? 'new');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(p.title LIKE ? OR p.body LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($vis !== '') { $where[] = 'p.visibility = ?'; $params[] = $vis; }
if ($userId > 0) { $where[] = 'p.user_id = ?'; $params[] = $userId; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderSql = match ($sort) {
    'old'   => 'p.id ASC',
    'likes' => 'likes_cnt DESC',
    default => 'p.id DESC',
};

$countStmt = db()->prepare("SELECT COUNT(*) FROM posts p $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT p.id, p.user_id, p.title, p.visibility, p.created_at,
               LEFT(p.body, 120) AS excerpt,
               u.username, u.display_name,
               (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id) AS likes_cnt,
               (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id) AS comments_cnt,
               (SELECT COUNT(*) FROM post_photos ph WHERE ph.post_id = p.id) AS photos_cnt
          FROM posts p
          JOIN users u ON u.id = p.user_id
          $whereSql
      ORDER BY $orderSql
         LIMIT $perPage OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

$pageTitle = 'Посты (' . admin_format_int($total) . ')';
require __DIR__ . '/includes/header.php';
?>

<form class="admin-filters" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Заголовок или текст">
    <select name="vis">
        <option value="">Все видимости</option>
        <option value="public"    <?= $vis === 'public' ? 'selected' : '' ?>>Публичные</option>
        <option value="followers" <?= $vis === 'followers' ? 'selected' : '' ?>>Для подписчиков</option>
        <option value="private"   <?= $vis === 'private' ? 'selected' : '' ?>>Приватные</option>
    </select>
    <input type="number" name="user_id" value="<?= $userId ?: '' ?>" placeholder="ID пользователя">
    <select name="sort">
        <option value="new"   <?= $sort === 'new' ? 'selected' : '' ?>>Сначала новые</option>
        <option value="old"   <?= $sort === 'old' ? 'selected' : '' ?>>Сначала старые</option>
        <option value="likes" <?= $sort === 'likes' ? 'selected' : '' ?>>По лайкам</option>
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
                <button class="btn btn--ghost btn--sm" name="action" value="show">🌐 Опубликовать</button>
                <?php if ($admin['role'] === 'super'): ?>
                    <button class="btn btn--danger btn--sm" name="action" value="delete"
                            onclick="return confirm('Удалить посты? Необратимо.')">🗑 Удалить</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-db__wrap">
            <table class="admin-table admin-table--wide">
                <thead>
                    <tr>
                        <th style="width:32px"></th>
                        <th>ID</th>
                        <th>Автор</th>
                        <th>Заголовок</th>
                        <th>Фото</th>
                        <th>♥ / 💬</th>
                        <th>Видимость</th>
                        <th>Дата</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $p): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" class="js-row-check"></td>
                        <td><?= (int)$p['id'] ?></td>
                        <td>
                            <a href="<?= e(admin_url('user.php?id=' . (int)$p['user_id'])) ?>">
                                <?= e($p['display_name']) ?>
                            </a>
                            <div class="muted">@<?= e($p['username']) ?></div>
                        </td>
                        <td>
                            <a href="<?= e(url('post.php?id=' . (int)$p['id'])) ?>" target="_blank">
                                <strong><?= e(mb_substr((string)$p['title'], 0, 60)) ?></strong>
                            </a>
                            <div class="muted"><?= e(mb_substr((string)$p['excerpt'], 0, 80)) ?>…</div>
                        </td>
                        <td><?= (int)$p['photos_cnt'] ?></td>
                        <td><?= (int)$p['likes_cnt'] ?> / <?= (int)$p['comments_cnt'] ?></td>
                        <td><span class="admin-badge"><?= e($p['visibility']) ?></span></td>
                        <td class="muted"><?= e(date('d.m.Y', strtotime((string)$p['created_at']))) ?></td>
                        <td>
                            <a class="admin-link" href="<?= e(url('post.php?id=' . (int)$p['id'])) ?>" target="_blank">Открыть</a>
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
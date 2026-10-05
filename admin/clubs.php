<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('readonly');

// ============================================================
// ПРОВЕРКА: существует ли таблица clubs
// ============================================================
$tableExists = admin_table_exists('clubs');
if (!$tableExists) {
    $pageTitle = 'Клубы';
    require __DIR__ . '/includes/header.php';
    echo '<div class="admin-card admin-card--danger">';
    echo '<h3 class="admin-card__title">Таблица clubs не найдена</h3>';
    echo '<p>Возможно, схема клубов ещё не применена или таблица называется иначе.</p>';
    echo '<p>Проверьте:</p>';
    echo '<pre class="admin-code">SHOW TABLES LIKE \'%club%\';</pre>';
    echo '<p>Если есть <code>game_clubs</code> — переименуйте:</p>';
    echo '<pre class="admin-code">RENAME TABLE game_clubs TO clubs;
RENAME TABLE game_club_members TO club_members;</pre>';
    echo '<p>Затем примените схему клубов: <code>sql/clubs_schema.sql</code></p>';
    echo '</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// ДЕЙСТВИЯ
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');
    $clubId = (int)($_POST['club_id'] ?? 0);

    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
    if (!$ids && $clubId > 0) $ids = [$clubId];

    if ($ids && in_array($action, ['ban','unban','delete','verify','unverify','hide','show'], true)) {
        admin_require('moderator');
        if (in_array($action, ['delete'], true)) admin_require('super');

        $in = implode(',', array_fill(0, count($ids), '?'));

        try {
            if ($action === 'ban') {
                db()->prepare("UPDATE clubs SET is_banned = 1 WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'unban') {
                db()->prepare("UPDATE clubs SET is_banned = 0 WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'verify') {
                db()->prepare("UPDATE clubs SET is_verified = 1 WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'unverify') {
                db()->prepare("UPDATE clubs SET is_verified = 0 WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'hide') {
                db()->prepare("UPDATE clubs SET visibility = 'hidden' WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'show') {
                db()->prepare("UPDATE clubs SET visibility = 'public' WHERE id IN ($in)")->execute($ids);
            } elseif ($action === 'delete') {
                // Сначала отвязываем территории (если game-модуль установлен)
                if (admin_table_exists('game_territories')) {
                    db()->prepare("UPDATE game_territories SET owner_club_id = NULL WHERE owner_club_id IN ($in)")
                        ->execute($ids);
                }
                db()->prepare("DELETE FROM clubs WHERE id IN ($in)")->execute($ids);
            }

            admin_audit((int)$admin['id'], "clubs.$action", 'club', null, ['ids' => $ids]);
            flash("Готово: $action применён к " . count($ids) . ' клубам', 'success');
        } catch (Throwable $e) {
            flash('Ошибка: ' . $e->getMessage(), 'error');
        }
        redirect(admin_url('clubs.php'));
    }
}

// ============================================================
// ФИЛЬТРЫ
// ============================================================
$q          = trim((string)($_GET['q'] ?? ''));
$vis        = (string)($_GET['vis'] ?? '');        // '', public, private, hidden
$state      = (string)($_GET['state'] ?? '');      // '', banned, verified, unverified
$sport      = (string)($_GET['sport'] ?? '');
$sort       = (string)($_GET['sort'] ?? 'members');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 50;
$offset     = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(c.name LIKE ? OR c.slug LIKE ? OR c.description LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($vis, ['public','private','hidden'], true)) {
    $where[] = 'c.visibility = ?';
    $params[] = $vis;
}
if ($state === 'banned')     $where[] = 'c.is_banned = 1';
if ($state === 'verified')   $where[] = 'c.is_verified = 1';
if ($state === 'unverified') $where[] = 'c.is_verified = 0';
if ($sport !== '')           { $where[] = 'c.sport_type = ?'; $params[] = $sport; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderSql = match ($sort) {
    'new'      => 'c.created_at DESC',
    'old'      => 'c.created_at ASC',
    'name'     => 'c.name ASC',
    'members'  => 'c.member_count DESC, c.created_at DESC',
    'territories' => 'territories_cnt DESC',
    default    => 'c.member_count DESC, c.created_at DESC',
};

// ============================================================
// ЗАПРОС
// ============================================================
$countStmt = db()->prepare("SELECT COUNT(*) FROM clubs c $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Опциональный JOIN с game_territories, если таблица есть
$hasTerritories = admin_table_exists('game_territories');
$territoriesSubquery = $hasTerritories
    ? '(SELECT COUNT(*) FROM game_territories t WHERE t.owner_club_id = c.id)'
    : '0';
$territoriesAreaSubquery = $hasTerritories
    ? '(SELECT COALESCE(SUM(t.area_m2), 0) FROM game_territories t WHERE t.owner_club_id = c.id)'
    : '0';

$sql = "SELECT c.id, c.name, c.slug, c.description, c.avatar_url, c.cover_url,
               c.city, c.country, c.sport_type, c.visibility, c.join_policy,
               c.owner_id, c.member_count, c.is_verified, c.is_banned,
               c.created_at,
               u.username AS owner_username,
               u.display_name AS owner_display_name,
               u.avatar_url AS owner_avatar,
               $territoriesSubquery AS territories_cnt,
               $territoriesAreaSubquery AS territories_m2
          FROM clubs c
          JOIN users u ON u.id = c.owner_id
          $whereSql
      ORDER BY $orderSql
         LIMIT $perPage OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$clubs = $stmt->fetchAll();

// ============================================================
// СВОДКА
// ============================================================
$summary = [
    'total' => (int)db()->query('SELECT COUNT(*) FROM clubs')->fetchColumn(),
    'public' => (int)db()->query("SELECT COUNT(*) FROM clubs WHERE visibility = 'public'")->fetchColumn(),
    'private' => (int)db()->query("SELECT COUNT(*) FROM clubs WHERE visibility = 'private'")->fetchColumn(),
    'hidden' => (int)db()->query("SELECT COUNT(*) FROM clubs WHERE visibility = 'hidden'")->fetchColumn(),
    'verified' => (int)db()->query('SELECT COUNT(*) FROM clubs WHERE is_verified = 1')->fetchColumn(),
    'banned' => (int)db()->query('SELECT COUNT(*) FROM clubs WHERE is_banned = 1')->fetchColumn(),
    'new_week' => (int)db()->query('SELECT COUNT(*) FROM clubs WHERE created_at >= NOW() - INTERVAL 7 DAY')->fetchColumn(),
    'members_total' => (int)db()->query('SELECT COALESCE(SUM(member_count), 0) FROM clubs')->fetchColumn(),
];

$pageTitle = 'Клубы (' . admin_format_int($total) . ')';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-kpi-grid">
    <div class="admin-kpi">
        <div class="admin-kpi__label">Всего клубов</div>
        <div class="admin-kpi__value"><?= admin_format_int($summary['total']) ?></div>
        <div class="admin-kpi__delta">+<?= admin_format_int($summary['new_week']) ?> за 7 дней</div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Публичные</div>
        <div class="admin-kpi__value"><?= admin_format_int($summary['public']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Приватные / скрытые</div>
        <div class="admin-kpi__value"><?= admin_format_int($summary['private']) ?> / <?= admin_format_int($summary['hidden']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Проверенные</div>
        <div class="admin-kpi__value"><?= admin_format_int($summary['verified']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Заблокированные</div>
        <div class="admin-kpi__value"><?= admin_format_int($summary['banned']) ?></div>
    </div>
    <div class="admin-kpi">
        <div class="admin-kpi__label">Участников всего</div>
        <div class="admin-kpi__value"><?= admin_format_int($summary['members_total']) ?></div>
    </div>
</div>

<form class="admin-filters" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Название, slug, описание">
    <select name="vis">
        <option value="">Любая видимость</option>
        <option value="public"  <?= $vis === 'public' ? 'selected' : '' ?>>Публичные</option>
        <option value="private" <?= $vis === 'private' ? 'selected' : '' ?>>Приватные</option>
        <option value="hidden"  <?= $vis === 'hidden' ? 'selected' : '' ?>>Скрытые</option>
    </select>
    <select name="state">
        <option value="">Любое состояние</option>
        <option value="verified"   <?= $state === 'verified' ? 'selected' : '' ?>>Проверенные</option>
        <option value="unverified" <?= $state === 'unverified' ? 'selected' : '' ?>>Не проверенные</option>
        <option value="banned"     <?= $state === 'banned' ? 'selected' : '' ?>>Заблокированные</option>
    </select>
    <select name="sport">
        <option value="">Все виды</option>
        <?php foreach (['run'=>'Бег','ride'=>'Вело','swim'=>'Плавание','ski'=>'Лыжи','walk'=>'Ходьба','hike'=>'Хайкинг','mixed'=>'Смешанный'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $sport === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
    </select>
    <select name="sort">
        <option value="members"     <?= $sort === 'members' ? 'selected' : '' ?>>По числу участников</option>
        <option value="new"         <?= $sort === 'new' ? 'selected' : '' ?>>Сначала новые</option>
        <option value="old"         <?= $sort === 'old' ? 'selected' : '' ?>>Сначала старые</option>
        <option value="name"        <?= $sort === 'name' ? 'selected' : '' ?>>По алфавиту</option>
        <?php if ($hasTerritories): ?>
            <option value="territories" <?= $sort === 'territories' ? 'selected' : '' ?>>По территориям</option>
        <?php endif; ?>
    </select>
    <button class="btn btn--primary">Найти</button>
    <?php if ($q || $vis || $state || $sport): ?>
        <a class="btn btn--ghost" href="<?= e(admin_url('clubs.php')) ?>">Сбросить</a>
    <?php endif; ?>
</form>

<form method="post">
    <?= admin_csrf_field() ?>

    <div class="admin-card">
        <div class="admin-table-head">
            <label class="admin-checkbox">
                <input type="checkbox" id="check-all"> Выбрать все
            </label>

            <div class="admin-bulk-actions" id="bulk-actions" hidden>
                <span class="muted">Выбрано: <strong id="bulk-count">0</strong></span>

                <button class="btn btn--ghost btn--sm" name="action" value="verify">⭐ Проверить</button>
                <button class="btn btn--ghost btn--sm" name="action" value="unverify">Убрать метку</button>

                <button class="btn btn--ghost btn--sm" name="action" value="hide">🔒 Скрыть</button>
                <button class="btn btn--ghost btn--sm" name="action" value="show">🌐 Открыть</button>

                <button class="btn btn--danger btn--sm" name="action" value="ban"
                        onclick="return confirm('Заблокировать выбранные клубы?')">🚫 Забанить</button>
                <button class="btn btn--ghost btn--sm" name="action" value="unban">✅ Разбанить</button>

                <?php if (($admin['role'] ?? '') === 'super'): ?>
                    <button class="btn btn--danger btn--sm" name="action" value="delete"
                            onclick="return confirm('УДАЛИТЬ выбранные клубы? Это необратимо. Территории станут нейтральными.')">
                        🗑 Удалить
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$clubs): ?>
            <div class="admin-empty" style="padding:60px 20px;text-align:center">
                <p>Клубы по заданным фильтрам не найдены.</p>
                <?php if ($summary['total'] === 0): ?>
                    <p class="muted" style="margin-top:12px">
                        В системе пока нет ни одного клуба. Пользователи создадут их сами — или можете создать вручную через SQL-консоль.
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="admin-db__wrap">
                <table class="admin-table admin-table--wide">
                    <thead>
                        <tr>
                            <th style="width:32px"></th>
                            <th style="width:44px"></th>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Основатель</th>
                            <th>Тип</th>
                            <th>Видимость</th>
                            <th>Участн.</th>
                            <?php if ($hasTerritories): ?>
                                <th>Территории</th>
                            <?php endif; ?>
                            <th>Дата</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clubs as $c): ?>
                        <?php
                            $banned = (int)$c['is_banned'] === 1;
                            $verified = (int)$c['is_verified'] === 1;
                            $visibility = (string)$c['visibility'];
                        ?>
                        <tr class="<?= $banned ? 'is-banned' : '' ?>">
                            <td>
                                <input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" class="js-row-check">
                            </td>
                            <td>
                                <span class="admin-club-avatar">
                                    <?php if (!empty($c['avatar_url'])): ?>
                                        <img src="<?= e($c['avatar_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(mb_substr((string)$c['name'], 0, 1)) ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td><?= (int)$c['id'] ?></td>
                            <td>
                                <a href="<?= e(url('club.php?id=' . (int)$c['id'])) ?>" target="_blank">
                                    <strong><?= e($c['name']) ?></strong>
                                    <?php if ($verified): ?>
                                        <span class="admin-badge admin-badge--ok" title="Проверенный">✓</span>
                                    <?php endif; ?>
                                </a>
                                <div class="muted" style="font-size:11px">
                                    <?= e((string)$c['slug']) ?>
                                    <?php if ($c['city']): ?> · 📍 <?= e($c['city']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <a href="<?= e(admin_url('user.php?id=' . (int)$c['owner_id'])) ?>">
                                    <?= e($c['owner_display_name']) ?>
                                </a>
                                <div class="muted">@<?= e($c['owner_username']) ?></div>
                            </td>
                            <td><span class="admin-badge"><?= e($c['sport_type']) ?></span></td>
                            <td>
                                <?php if ($visibility === 'public'): ?>
                                    <span class="admin-badge admin-badge--ok">🌐 public</span>
                                <?php elseif ($visibility === 'private'): ?>
                                    <span class="admin-badge">🔒 private</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge--danger">👁 hidden</span>
                                <?php endif; ?>
                            </td>
                            <td><?= admin_format_int((int)$c['member_count']) ?></td>
                            <?php if ($hasTerritories): ?>
                                <td>
                                    <?php if ((int)$c['territories_cnt'] > 0): ?>
                                        <?= admin_format_int((int)$c['territories_cnt']) ?>
                                        <div class="muted" style="font-size:11px">
                                            <?= e(number_format((float)$c['territories_m2'] / 10000, 1, '.', ' ')) ?> га
                                        </div>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td class="muted"><?= e(date('d.m.Y', strtotime((string)$c['created_at']))) ?></td>
                            <td>
                                <?php if ($banned): ?>
                                    <span class="admin-badge admin-badge--danger">забанен</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge--ok">активен</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="admin-link" href="<?= e(url('club.php?id=' . (int)$c['id'])) ?>" target="_blank">Открыть</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
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
    if (!all || !actions) return;

    function update() {
        var n = document.querySelectorAll('.js-row-check:checked').length;
        count.textContent = n;
        actions.hidden = n === 0;
    }
    all.addEventListener('change', function () {
        checks.forEach(function (c) { c.checked = all.checked; });
        update();
    });
    checks.forEach(function (c) { c.addEventListener('change', update); });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
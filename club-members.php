<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';

auth_start();
$me = current_user();

$clubId = (int)($_GET['id'] ?? 0);
$club = Club::findById($clubId);
if (!$club) { http_response_code(404); exit('Клуб не найден'); }

$role = $me ? Club::roleOf($clubId, (int)$me['id']) : null;
$canManage = $me && Club::canManage($clubId, (int)$me['id']);

// Фильтры
$status = (string)($_GET['status'] ?? 'active');   // active | pending | banned
$sort   = (string)($_GET['sort'] ?? 'role');       // role | new | distance | name
$q      = trim((string)($_GET['q'] ?? ''));

if (!in_array($status, ['active','pending','banned'], true)) $status = 'active';

// Скрытые клубы — только для участников
if ($club['visibility'] === 'hidden' && !$role) {
    http_response_code(403); exit('Доступ запрещён');
}

// Заявки видны только модераторам
if ($status === 'pending' && !$canManage) {
    http_response_code(403); exit('Доступ запрещён');
}
if ($status === 'banned' && !$canManage) {
    http_response_code(403); exit('Доступ запрещён');
}

// Собираем SQL
$orderSql = match ($sort) {
    'new'      => 'm.joined_at DESC',
    'distance' => 'distance_m DESC',
    'name'     => 'u.display_name ASC',
    default    => 'FIELD(m.role, "owner","admin","moderator","member"), m.joined_at ASC',
};

$whereExtra = '';
$params = [$clubId, $status];
if ($q !== '') {
    $whereExtra = ' AND (u.display_name LIKE ? OR u.username LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$stmt = db()->prepare(
    "SELECT m.role, m.status, m.joined_at, m.approved_at,
            u.id, u.username, u.display_name, u.avatar_url, u.city,
            (SELECT COUNT(*) FROM activities a WHERE a.user_id = u.id) AS activities_cnt,
            (SELECT COALESCE(SUM(distance_m),0) FROM activities a WHERE a.user_id = u.id) AS distance_m
       FROM club_members m
       JOIN users u ON u.id = m.user_id
      WHERE m.club_id = ? AND m.status = ? $whereExtra
   ORDER BY $orderSql"
);
$stmt->execute($params);
$members = $stmt->fetchAll();

$pageTitle = $club['name'] . ' — участники';
$extraCss = [url('assets/css/clubs.css')];
require __DIR__ . '/includes/header.php';
?>

<section class="club-members">
    <header class="club-members__head">
        <div>
            <h1 class="clubs-page__title"><?= e($club['name']) ?></h1>
            <p class="muted">
                <a href="<?= e(url('club.php?id=' . $clubId)) ?>">← к клубу</a>
                · <?= (int)$club['member_count'] ?> активных участников
            </p>
        </div>
    </header>

    <form class="clubs-filters" method="get">
        <input type="hidden" name="id" value="<?= $clubId ?>">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Имя или логин">

        <select name="status">
            <option value="active"  <?= $status === 'active' ? 'selected' : '' ?>>Активные</option>
            <?php if ($canManage): ?>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Заявки</option>
                <option value="banned"  <?= $status === 'banned' ? 'selected' : '' ?>>Забаненные</option>
            <?php endif; ?>
        </select>

        <select name="sort">
            <option value="role"     <?= $sort === 'role' ? 'selected' : '' ?>>По роли</option>
            <option value="new"      <?= $sort === 'new' ? 'selected' : '' ?>>Сначала новые</option>
            <option value="distance" <?= $sort === 'distance' ? 'selected' : '' ?>>По километражу</option>
            <option value="name"     <?= $sort === 'name' ? 'selected' : '' ?>>По алфавиту</option>
        </select>

        <button class="btn btn--primary">Применить</button>
    </form>

    <?php if (!$members): ?>
        <div class="empty">
            <p>Нет участников по заданным фильтрам.</p>
        </div>
    <?php else: ?>
        <div class="club-members__list">
            <?php foreach ($members as $m): ?>
                <?php
                    $isMe = $me && (int)$m['id'] === (int)$me['id'];
                    $memberRole = $m['role'];
                    $canEdit = $canManage && !$isMe && $memberRole !== 'owner';
                ?>
                <div class="club-member-card">
                    <a class="club-member-card__link"
                       href="<?= e(url('profile.php?u=' . urlencode((string)$m['username']))) ?>">
                        <span class="avatar avatar--lg">
                            <?php if (!empty($m['avatar_url'])): ?>
                                <img src="<?= e($m['avatar_url']) ?>" alt="">
                            <?php else: ?>
                                <?= e(mb_substr((string)$m['display_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </span>
                        <div class="club-member-card__info">
                            <div class="club-member-card__name">
                                <?= e($m['display_name']) ?>
                                <?php if ($isMe): ?><span class="leaderboard__you">вы</span><?php endif; ?>
                                <?php if ($memberRole === 'owner'): ?>
                                    <span class="club-member-card__role club-member-card__role--owner">владелец</span>
                                <?php elseif ($memberRole === 'admin'): ?>
                                    <span class="club-member-card__role club-member-card__role--admin">админ</span>
                                <?php elseif ($memberRole === 'moderator'): ?>
                                    <span class="club-member-card__role">модератор</span>
                                <?php endif; ?>
                            </div>
                            <div class="club-member-card__meta muted">
                                @<?= e($m['username']) ?>
                                <?php if ($m['city']): ?> · <?= e($m['city']) ?><?php endif; ?>
                                · <?= e(date('d.m.Y', strtotime((string)$m['joined_at']))) ?>
                            </div>
                            <div class="club-member-card__stats">
                                <span><strong><?= (int)$m['activities_cnt'] ?></strong> актив.</span>
                                <span><strong><?= number_format((float)$m['distance_m'] / 1000, 0, '.', ' ') ?></strong> км</span>
                            </div>
                        </div>
                    </a>

                    <?php if ($canEdit): ?>
                        <div class="club-member-card__actions">
                            <form method="post" action="<?= e(url('api/club-member-action.php')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="club_id" value="<?= $clubId ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">

                                <?php if ($status === 'pending'): ?>
                                    <button name="action" value="approve" class="btn btn--sm btn--primary">Принять</button>
                                    <button name="action" value="reject"  class="btn btn--sm btn--ghost">Отклонить</button>
                                <?php elseif ($status === 'banned'): ?>
                                    <button name="action" value="unban" class="btn btn--sm btn--ghost">Разбанить</button>
                                <?php else: ?>
                                    <select name="new_role" class="club-member-card__role-select">
                                        <option value="member">Участник</option>
                                        <option value="moderator" <?= $memberRole === 'moderator' ? 'selected' : '' ?>>Модератор</option>
                                        <option value="admin" <?= $memberRole === 'admin' ? 'selected' : '' ?>>Админ</option>
                                    </select>
                                    <button name="action" value="set_role" class="btn btn--sm btn--ghost">ОК</button>
                                    <button name="action" value="kick" class="btn btn--sm btn--ghost"
                                            onclick="return confirm('Удалить из клуба?')">Исключить</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
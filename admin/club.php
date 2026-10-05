<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('readonly');

$clubId = (int)($_GET['id'] ?? 0);
if ($clubId <= 0) { http_response_code(404); exit('Клуб не найден'); }

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update') {
        admin_require('moderator');
        $name = trim((string)($_POST['name'] ?? ''));
        $tag  = strtoupper(trim((string)($_POST['tag'] ?? '')));

        if ($name === '') $error = 'Название не может быть пустым';
        elseif (!preg_match('/^[A-Z0-9]{2,12}$/', $tag)) $error = 'Тег: 2–12 символов A-Z0-9';
        else {
            db()->prepare('UPDATE game_clubs SET name = ?, tag = ? WHERE id = ?')
                ->execute([$name, $tag, $clubId]);
            admin_audit((int)$admin['id'], 'club.update', 'club', $clubId, ['name' => $name, 'tag' => $tag]);
            $success = 'Сохранено';
        }
    }

    if ($action === 'remove_member' && !empty($_POST['user_id'])) {
        admin_require('moderator');
        $uid = (int)$_POST['user_id'];
        $role = (string)($_POST['role'] ?? '');

        if ($role === 'owner') {
            $error = 'Нельзя удалить основателя клуба';
        } else {
            db()->prepare('DELETE FROM game_club_members WHERE club_id = ? AND user_id = ?')
                ->execute([$clubId, $uid]);
            admin_audit((int)$admin['id'], 'club.remove_member', 'club', $clubId, ['user_id' => $uid]);
            $success = 'Участник удалён';
        }
    }

    if ($action === 'transfer_owner' && !empty($_POST['user_id'])) {
        admin_require('super');
        $newOwnerId = (int)$_POST['user_id'];

        // Проверяем, что пользователь — участник клуба
        $s = db()->prepare('SELECT role FROM game_club_members WHERE club_id = ? AND user_id = ?');
        $s->execute([$clubId, $newOwnerId]);
        if (!$s->fetchColumn()) {
            $error = 'Пользователь не состоит в клубе';
        } else {
            try {
                db()->beginTransaction();
                db()->prepare('UPDATE game_clubs SET owner_id = ? WHERE id = ?')
                    ->execute([$newOwnerId, $clubId]);
                db()->prepare("UPDATE game_club_members SET role = 'member' WHERE club_id = ? AND role = 'owner'")
                    ->execute([$clubId]);
                db()->prepare("UPDATE game_club_members SET role = 'owner' WHERE club_id = ? AND user_id = ?")
                    ->execute([$clubId, $newOwnerId]);
                db()->commit();
                admin_audit((int)$admin['id'], 'club.transfer_owner', 'club', $clubId, ['new_owner' => $newOwnerId]);
                $success = 'Владелец клуба изменён';
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                $error = 'Ошибка: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete') {
        admin_require('super');
        db()->prepare('UPDATE game_territories SET owner_club_id = NULL WHERE owner_club_id = ?')->execute([$clubId]);
        db()->prepare('DELETE FROM game_clubs WHERE id = ?')->execute([$clubId]);
        admin_audit((int)$admin['id'], 'club.delete', 'club', $clubId);
        header('Location: ' . admin_url('clubs.php'));
        exit;
    }
}

$stmt = db()->prepare(
    'SELECT c.*, u.username AS owner_username, u.display_name AS owner_display_name, u.id AS owner_user_id
       FROM game_clubs c JOIN users u ON u.id = c.owner_id
      WHERE c.id = ? LIMIT 1'
);
$stmt->execute([$clubId]);
$club = $stmt->fetch();
if (!$club) { http_response_code(404); exit('Клуб не найден'); }

// Участники
$membersStmt = db()->prepare(
    'SELECT m.user_id, m.role, m.joined_at,
            u.username, u.display_name, u.avatar_url,
            (SELECT COUNT(*) FROM game_territories t WHERE t.owner_user_id = u.id) AS territories_cnt
       FROM game_club_members m
       JOIN users u ON u.id = m.user_id
      WHERE m.club_id = ?
   ORDER BY m.role ASC, m.joined_at ASC'
);
$membersStmt->execute([$clubId]);
$members = $membersStmt->fetchAll();

// Статистика клуба
$statsStmt = db()->prepare(
    'SELECT COUNT(*) AS territories_cnt,
            COALESCE(SUM(area_m2), 0) AS area_m2
       FROM game_territories WHERE owner_club_id = ?'
);
$statsStmt->execute([$clubId]);
$stats = $statsStmt->fetch();

// Последние захваты
$capturesStmt = db()->prepare(
    'SELECT l.id, l.area_m2, l.captured_at, l.kind,
            t.id AS territory_id,
            u.username AS to_username, u.display_name AS to_display_name
       FROM game_capture_log l
       LEFT JOIN game_territories t ON t.id = l.territory_id
       LEFT JOIN users u ON u.id = l.to_user_id
      WHERE l.to_club_id = ? OR t.owner_club_id = ?
   ORDER BY l.id DESC
      LIMIT 20'
);
$capturesStmt->execute([$clubId, $clubId]);
$captures = $capturesStmt->fetchAll();

$pageTitle = 'Клуб: ' . $club['name'];
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>

<div class="admin-user">

    <div class="admin-card">
        <h3 class="admin-card__title">Сводка</h3>
        <div class="admin-user__stats">
            <div><span class="muted">ID</span><strong><?= (int)$club['id'] ?></strong></div>
            <div><span class="muted">Название</span><strong><?= e($club['name']) ?></strong></div>
            <div><span class="muted">Тег</span><strong><code><?= e($club['tag']) ?></code></strong></div>
            <div><span class="muted">Основатель</span>
                <a href="<?= e(admin_url('user.php?id=' . (int)$club['owner_user_id'])) ?>">
                    <strong><?= e($club['owner_display_name']) ?></strong>
                </a>
            </div>
            <div><span class="muted">Участников</span><strong><?= count($members) ?></strong></div>
            <div><span class="muted">Территорий</span><strong><?= admin_format_int((int)$stats['territories_cnt']) ?></strong></div>
            <div><span class="muted">Площадь</span>
                <strong><?= e(number_format((float)$stats['area_m2'] / 10000, 2, '.', ' ')) ?> га</strong>
            </div>
            <div><span class="muted">Создан</span>
                <strong><?= e(date('d.m.Y', strtotime((string)$club['created_at']))) ?></strong>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title">Редактирование</h3>
        <form method="post">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <div class="admin-form-grid">
                <div class="field"><label>Название</label>
                    <input type="text" name="name" value="<?= e($club['name']) ?>" required maxlength="120">
                </div>
                <div class="field"><label>Тег (A-Z0-9, 2–12)</label>
                    <input type="text" name="tag" value="<?= e($club['tag']) ?>" required maxlength="12"
                           pattern="[A-Za-z0-9]{2,12}" style="text-transform:uppercase">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn--primary">Сохранить</button>
            </div>
        </form>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title">Участники (<?= count($members) ?>)</h3>
        <div class="admin-db__wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Пользователь</th><th>Роль</th><th>Территорий</th><th>В клубе с</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($members as $m): ?>
                    <tr>
                        <td>
                            <a href="<?= e(admin_url('user.php?id=' . (int)$m['user_id'])) ?>">
                                <?= e($m['display_name']) ?>
                            </a>
                            <div class="muted">@<?= e($m['username']) ?></div>
                        </td>
                        <td>
                            <?php if ($m['role'] === 'owner'): ?>
                                <span class="admin-badge admin-badge--ok">основатель</span>
                            <?php else: ?>
                                <span class="admin-badge">участник</span>
                            <?php endif; ?>
                        </td>
                        <td><?= admin_format_int((int)$m['territories_cnt']) ?></td>
                        <td class="muted"><?= e(date('d.m.Y', strtotime((string)$m['joined_at']))) ?></td>
                        <td>
                            <?php if ($m['role'] !== 'owner'): ?>
                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('Удалить участника из клуба?')">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                                    <input type="hidden" name="role" value="<?= e($m['role']) ?>">
                                    <button class="admin-link admin-link--danger">удалить</button>
                                </form>
                                <?php if ($admin['role'] === 'super'): ?>
                                    <form method="post" style="display:inline"
                                          onsubmit="return confirm('Сделать основателем клуба?')">
                                        <?= admin_csrf_field() ?>
                                        <input type="hidden" name="action" value="transfer_owner">
                                        <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                                        <button class="admin-link">→ основатель</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title">Последние захваты территорий</h3>
        <?php if (!$captures): ?>
            <p class="muted">Клуб пока не захватывал территории</p>
        <?php else: ?>
            <div class="admin-db__wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>Когда</th><th>Тип</th><th>Кто захватил</th><th>Площадь</th><th>Территория</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($captures as $c): ?>
                        <tr>
                            <td class="muted"><?= e(date('d.m.Y H:i', strtotime((string)$c['captured_at']))) ?></td>
                            <td><span class="admin-badge"><?= e($c['kind']) ?></span></td>
                            <td>
                                <?php if ($c['to_username']): ?>
                                    <?= e($c['to_display_name']) ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(number_format((float)$c['area_m2'] / 10000, 3, '.', ' ')) ?> га</td>
                            <td>
                                <?php if ($c['territory_id']): ?>
                                    <a class="admin-link" href="<?= e(game_url('map.php')) ?>?t=<?= (int)$c['territory_id'] ?>" target="_blank">
                                        #<?= (int)$c['territory_id'] ?>
                                    </a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($admin['role'] === 'super'): ?>
        <div class="admin-card admin-card--danger">
            <h3 class="admin-card__title">Опасная зона</h3>
            <form method="post" onsubmit="return confirm('Удалить клуб? Территории станут нейтральными.')">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="btn btn--danger">Удалить клуб</button>
            </form>
        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
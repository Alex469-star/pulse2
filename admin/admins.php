<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('super');

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $email    = strtolower(trim((string)($_POST['email'] ?? '')));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role     = (string)($_POST['role'] ?? 'moderator');
        $userId   = (int)($_POST['user_id'] ?? 0) ?: null;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Некорректный email';
        elseif (strlen($username) < 3) $error = 'Логин минимум 3 символа';
        elseif (strlen($password) < 8) $error = 'Пароль минимум 8 символов';
        elseif (!in_array($role, ['super','moderator','readonly'], true)) $error = 'Неверная роль';
        else {
            try {
                db()->prepare(
                    'INSERT INTO admins (user_id, email, username, password_hash, display_name, role)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $userId, $email, $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $username, $role,
                ]);
                admin_audit((int)$admin['id'], 'admin.create', 'admin', (int)db()->lastInsertId(), [
                    'email' => $email, 'role' => $role,
                ]);
                $success = 'Админ создан';
            } catch (\Throwable $e) {
                $error = 'Не удалось: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'update_role' && !empty($_POST['admin_id'])) {
        $targetId = (int)$_POST['admin_id'];
        $role = (string)($_POST['role'] ?? '');
        if (in_array($role, ['super','moderator','readonly'], true)) {
            db()->prepare('UPDATE admins SET role = ? WHERE id = ?')->execute([$role, $targetId]);
            admin_audit((int)$admin['id'], 'admin.update_role', 'admin', $targetId, ['role' => $role]);
            $success = 'Роль обновлена';
        }
    }

    if ($action === 'deactivate' && !empty($_POST['admin_id'])) {
        $targetId = (int)$_POST['admin_id'];
        if ($targetId === (int)$admin['id']) {
            $error = 'Нельзя деактивировать себя';
        } else {
            db()->prepare('UPDATE admins SET is_active = 0 WHERE id = ?')->execute([$targetId]);
            admin_audit((int)$admin['id'], 'admin.deactivate', 'admin', $targetId);
            $success = 'Админ деактивирован';
        }
    }

    if ($action === 'activate' && !empty($_POST['admin_id'])) {
        $targetId = (int)$_POST['admin_id'];
        db()->prepare('UPDATE admins SET is_active = 1 WHERE id = ?')->execute([$targetId]);
        admin_audit((int)$admin['id'], 'admin.activate', 'admin', $targetId);
        $success = 'Админ активирован';
    }

    if ($action === 'delete' && !empty($_POST['admin_id'])) {
        $targetId = (int)$_POST['admin_id'];
        if ($targetId === (int)$admin['id']) {
            $error = 'Нельзя удалить себя';
        } else {
            db()->prepare('DELETE FROM admins WHERE id = ?')->execute([$targetId]);
            admin_audit((int)$admin['id'], 'admin.delete', 'admin', $targetId);
            $success = 'Админ удалён';
        }
    }
}

$admins = db()->query(
    'SELECT a.*, u.username AS linked_username
       FROM admins a
  LEFT JOIN users u ON u.id = a.user_id
   ORDER BY a.role ASC, a.id ASC'
)->fetchAll();

$pageTitle = 'Администраторы';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>

<div class="admin-card">
    <h3 class="admin-card__title">Добавить администратора</h3>
    <form method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="admin-form-grid">
            <div class="field"><label>Email</label><input type="email" name="email" required></div>
            <div class="field"><label>Логин</label><input type="text" name="username" required minlength="3"></div>
            <div class="field"><label>Пароль</label><input type="password" name="password" required minlength="8"></div>
            <div class="field">
                <label>Роль</label>
                <select name="role">
                    <option value="readonly">readonly — только чтение</option>
                    <option value="moderator" selected>moderator — модерация</option>
                    <option value="super">super — полный доступ</option>
                </select>
            </div>
            <div class="field">
                <label>Связанный user_id (опционально)</label>
                <input type="number" name="user_id" placeholder="">
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn--primary">Создать</button>
        </div>
    </form>
</div>

<div class="admin-card">
    <h3 class="admin-card__title">Все администраторы</h3>
    <table class="admin-table admin-table--wide">
        <thead>
            <tr><th>ID</th><th>Логин</th><th>Email</th><th>Роль</th><th>Статус</th><th>Последний вход</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
            <tr>
                <td><?= (int)$a['id'] ?></td>
                <td><?= e($a['username']) ?></td>
                <td><?= e($a['email']) ?></td>
                <td>
                    <form method="post" style="display:inline">
                        <?= admin_csrf_field() ?>
                        <input type="hidden" name="action" value="update_role">
                        <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                        <select name="role" onchange="this.form.submit()">
                            <option value="readonly"  <?= $a['role'] === 'readonly' ? 'selected' : '' ?>>readonly</option>
                            <option value="moderator" <?= $a['role'] === 'moderator' ? 'selected' : '' ?>>moderator</option>
                            <option value="super"     <?= $a['role'] === 'super' ? 'selected' : '' ?>>super</option>
                        </select>
                    </form>
                </td>
                <td>
                    <?= (int)$a['is_active'] ? '<span class="admin-badge admin-badge--ok">активен</span>' : '<span class="admin-badge admin-badge--danger">off</span>' ?>
                </td>
                <td class="muted"><?= $a['last_login_at'] ? e(admin_time_ago((string)$a['last_login_at'])) : '—' ?></td>
                <td>
                    <?php if ((int)$a['id'] !== (int)$admin['id']): ?>
                        <?php if ((int)$a['is_active']): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Деактивировать?')">
                                <?= admin_csrf_field() ?>
                                <input type="hidden" name="action" value="deactivate">
                                <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                                <button class="admin-link">off</button>
                            </form>
                        <?php else: ?>
                            <form method="post" style="display:inline">
                                <?= admin_csrf_field() ?>
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                                <button class="admin-link">on</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Удалить админа? Необратимо.')">
                            <?= admin_csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                            <button class="admin-link admin-link--danger">удалить</button>
                        </form>
                    <?php else: ?>
                        <span class="muted">вы</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
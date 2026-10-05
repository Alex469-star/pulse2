<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('readonly');

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) { http_response_code(404); exit('Пользователь не найден'); }

// ---- POST-обработчики ----
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    // Редактирование основных полей — только moderator+
    if ($action === 'update') {
        admin_require('moderator');

        $fields = [
            'display_name'  => trim((string)($_POST['display_name'] ?? '')),
            'bio'           => trim((string)($_POST['bio'] ?? '')),
            'city'          => trim((string)($_POST['city'] ?? '')),
            'country'       => trim((string)($_POST['country'] ?? '')),
            'is_public'     => isset($_POST['is_public']) ? 1 : 0,
            'email_verified'=> isset($_POST['email_verified']) ? 1 : 0,
        ];

        $setSql = [];
        $params = [];
        foreach ($fields as $k => $v) {
            $setSql[] = "`$k` = ?";
            $params[] = $v;
        }
        $params[] = $userId;

        db()->prepare('UPDATE users SET ' . implode(', ', $setSql) . ' WHERE id = ?')
            ->execute($params);

        admin_audit((int)$admin['id'], 'user.update', 'user', $userId, $fields);
        $success = 'Профиль сохранён';
    }

    if ($action === 'ban') {
        admin_require('moderator');
        $reason = trim((string)($_POST['reason'] ?? ''));
        $days   = (int)($_POST['days'] ?? 0);
        $until  = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

        db()->prepare(
            'UPDATE users SET is_banned = 1, banned_at = NOW(),
                              banned_until = ?, ban_reason = ? WHERE id = ?'
        )->execute([$until, $reason, $userId]);

        admin_audit((int)$admin['id'], 'user.ban', 'user', $userId, ['reason' => $reason, 'days' => $days]);
        $success = 'Пользователь заблокирован';
    }

    if ($action === 'unban') {
        admin_require('moderator');
        db()->prepare(
            'UPDATE users SET is_banned = 0, banned_at = NULL,
                              banned_until = NULL, ban_reason = NULL WHERE id = ?'
        )->execute([$userId]);

        admin_audit((int)$admin['id'], 'user.unban', 'user', $userId);
        $success = 'Пользователь разблокирован';
    }

    if ($action === 'reset_password') {
        admin_require('super');
        $newPassword = (string)($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 8) {
            $error = 'Пароль должен быть не короче 8 символов';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$hash, $userId]);
            admin_audit((int)$admin['id'], 'user.reset_password', 'user', $userId);
            $success = 'Пароль обновлён';
        }
    }

    if ($action === 'delete') {
        admin_require('super');
        admin_audit((int)$admin['id'], 'user.delete', 'user', $userId, ['username' => $_POST['username'] ?? null]);
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        header('Location: ' . admin_url('users.php'));
        exit;
    }
}

$stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) { http_response_code(404); exit('Пользователь не найден'); }

// Связанные данные
$activityCount = (int)db()->query("SELECT COUNT(*) FROM activities WHERE user_id = $userId")->fetchColumn();
$postCount     = (int)db()->query("SELECT COUNT(*) FROM posts WHERE user_id = $userId")->fetchColumn();
$followers     = (int)db()->query("SELECT COUNT(*) FROM follows WHERE following_id = $userId")->fetchColumn();
$following     = (int)db()->query("SELECT COUNT(*) FROM follows WHERE follower_id = $userId")->fetchColumn();

$isAdmin = (int)db()->query("SELECT COUNT(*) FROM admins WHERE user_id = $userId")->fetchColumn() > 0;

$pageTitle = 'Пользователь: ' . $user['username'];
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>

<div class="admin-user">

    <div class="admin-card">
        <h3 class="admin-card__title">Сводка</h3>
        <div class="admin-user__stats">
            <div><span class="muted">ID</span><strong><?= (int)$user['id'] ?></strong></div>
            <div><span class="muted">@username</span><strong><?= e($user['username']) ?></strong></div>
            <div><span class="muted">Email</span><strong><?= e($user['email']) ?></strong></div>
            <div><span class="muted">Активности</span><strong><?= admin_format_int($activityCount) ?></strong></div>
            <div><span class="muted">Посты</span><strong><?= admin_format_int($postCount) ?></strong></div>
            <div><span class="muted">Подписчики</span><strong><?= admin_format_int($followers) ?></strong></div>
            <div><span class="muted">Подписки</span><strong><?= admin_format_int($following) ?></strong></div>
            <div><span class="muted">Регистрация</span><strong><?= e(date('d.m.Y H:i', strtotime((string)$user['created_at']))) ?></strong></div>
            <div><span class="muted">Последний визит</span><strong><?= $user['last_seen_at'] ? e(admin_time_ago((string)$user['last_seen_at'])) : '—' ?></strong></div>
            <div><span class="muted">Админ</span><strong><?= $isAdmin ? 'да' : 'нет' ?></strong></div>
        </div>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title">Редактирование профиля</h3>
        <form method="post">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <div class="admin-form-grid">
                <div class="field">
                    <label>Отображаемое имя</label>
                    <input type="text" name="display_name" value="<?= e($user['display_name']) ?>">
                </div>
                <div class="field">
                    <label>Город</label>
                    <input type="text" name="city" value="<?= e((string)$user['city']) ?>">
                </div>
                <div class="field">
                    <label>Страна</label>
                    <input type="text" name="country" value="<?= e((string)$user['country']) ?>">
                </div>
                <div class="field field--checkbox">
                    <label><input type="checkbox" name="is_public" <?= (int)$user['is_public'] ? 'checked' : '' ?>> Публичный профиль</label>
                </div>
                <div class="field field--checkbox">
                    <label><input type="checkbox" name="email_verified" <?= (int)$user['email_verified'] ? 'checked' : '' ?>> Email подтверждён</label>
                </div>
                <div class="field" style="grid-column: 1 / -1">
                    <label>Bio</label>
                    <textarea name="bio" rows="3"><?= e((string)$user['bio']) ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn--primary" type="submit">Сохранить</button>
            </div>
        </form>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title">Модерация</h3>
        <?php if ((int)$user['is_banned']): ?>
            <div class="alert alert--error">
                Забанен <?= e($user['banned_at'] ? date('d.m.Y H:i', strtotime((string)$user['banned_at'])) : '') ?>
                <?php if ($user['banned_until']): ?> до <?= e(date('d.m.Y H:i', strtotime((string)$user['banned_until']))) ?><?php endif; ?>
                <?php if ($user['ban_reason']): ?><br>Причина: <?= e($user['ban_reason']) ?><?php endif; ?>
            </div>
            <form method="post">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="unban">
                <button class="btn btn--primary">Разблокировать</button>
            </form>
        <?php else: ?>
            <form method="post">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="ban">
                <div class="admin-form-grid">
                    <div class="field">
                        <label>Причина блокировки</label>
                        <input type="text" name="reason" placeholder="Спам, нарушение правил...">
                    </div>
                    <div class="field">
                        <label>Срок, дней (0 — навсегда)</label>
                        <input type="number" name="days" value="7" min="0">
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn--danger" onclick="return confirm('Заблокировать пользователя?')">Заблокировать</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <?php if (($admin['role'] ?? '') === 'super'): ?>
    <div class="admin-card admin-card--danger">
        <h3 class="admin-card__title">Опасная зона</h3>

        <form method="post" class="admin-inline-form">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="reset_password">
            <label>Новый пароль</label>
            <input type="password" name="new_password" placeholder="минимум 8 символов">
            <button class="btn btn--ghost" onclick="return confirm('Сменить пароль пользователю?')">Сменить пароль</button>
        </form>

        <hr>

        <form method="post" class="admin-inline-form"
              onsubmit="return confirm('УДАЛИТЬ пользователя вместе со всеми данными? Это необратимо.')">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="username" value="<?= e($user['username']) ?>">
            <button class="btn btn--danger">Удалить пользователя</button>
        </form>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
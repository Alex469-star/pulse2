<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/models/Broadcast.php';

$admin = admin_require('moderator');

// ---- Действия ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        admin_require('super');
        Broadcast::delete($id);
        admin_audit((int)$admin['id'], 'broadcast.delete', 'broadcast', $id);
        flash('Рассылка удалена', 'success');
        redirect(admin_url('broadcasts.php'));
    }

    if ($action === 'send' && $id > 0) {
        $b = Broadcast::findById($id);
        if ($b) {
            $ids = Broadcast::resolveAudience($b);
            [$sent, $failed] = Broadcast::send($id, $ids);
            admin_audit((int)$admin['id'], 'broadcast.send', 'broadcast', $id, [
                'sent' => $sent, 'failed' => $failed,
            ]);
            flash("Отправлено: $sent, ошибок: $failed", 'success');
        }
        redirect(admin_url('broadcasts.php'));
    }
}

$list = Broadcast::listAll(50, 0);

$pageTitle = 'Рассылки уведомлений';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-card">
    <div class="admin-table-head">
        <div>
            <h3 class="admin-card__title">Рассылки</h3>
            <p class="muted" style="margin:4px 0 0;font-size:13px">
                Уведомления появятся у пользователей в колокольчике в шапке сервиса.
            </p>
        </div>
        <a class="btn btn--primary" href="<?= e(admin_url('broadcast-create.php')) ?>">
            + Новая рассылка
        </a>
    </div>

    <?php if (!$list): ?>
        <div class="admin-empty" style="padding:48px 20px;text-align:center">
            <p>Рассылок ещё нет.</p>
            <a class="btn btn--primary" href="<?= e(admin_url('broadcast-create.php')) ?>">
                Создать первую
            </a>
        </div>
    <?php else: ?>
        <div class="admin-db__wrap">
            <table class="admin-table admin-table--wide">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Заголовок</th>
                        <th>Аудитория</th>
                        <th>Статус</th>
                        <th>Доставлено</th>
                        <th>Дата</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($list as $b): ?>
                    <tr>
                        <td><?= (int)$b['id'] ?></td>
                        <td>
                            <strong><?= e(mb_substr((string)$b['title'], 0, 60)) ?></strong>
                            <?php if (!empty($b['body'])): ?>
                                <div class="muted" style="font-size:12px">
                                    <?= e(mb_substr((string)$b['body'], 0, 80)) ?>…
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="admin-badge">
                                <?= e(match ($b['audience']) {
                                    'all' => 'Все',
                                    'active' => 'Активные',
                                    'inactive' => 'Неактивные',
                                    'new' => 'Новые',
                                    'club' => 'Клуб',
                                    'role' => 'Роль',
                                    'specific' => 'Выбранные',
                                    default => $b['audience'],
                                }) ?>
                            </span>
                        </td>
                        <td>
                            <?php
                                $statusClass = match ($b['status']) {
                                    'sent' => 'admin-badge--ok',
                                    'failed' => 'admin-badge--danger',
                                    'sending' => 'admin-badge',
                                    default => 'admin-badge',
                                };
                            ?>
                            <span class="admin-badge <?= $statusClass ?>">
                                <?= e($b['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ((int)$b['total_count'] > 0): ?>
                                <?= (int)$b['sent_count'] ?> / <?= (int)$b['total_count'] ?>
                                <?php if ((int)$b['failed_count'] > 0): ?>
                                    <span class="admin-badge admin-badge--danger">
                                        ошибок: <?= (int)$b['failed_count'] ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted">
                            <?= e(date('d.m.Y H:i', strtotime((string)($b['finished_at'] ?: $b['created_at'])))) ?>
                        </td>
                        <td style="white-space:nowrap">
                            <?php if ($b['status'] === 'draft'): ?>
                                <a class="admin-link"
                                   href="<?= e(admin_url('broadcast-edit.php?id=' . (int)$b['id'])) ?>">✏️</a>
                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('Отправить рассылку?')">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="action" value="send">
                                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                    <button class="admin-link">📤</button>
                                </form>
                            <?php endif; ?>
                            <?php if (($admin['role'] ?? '') === 'super'): ?>
                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('Удалить рассылку?')">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                    <button class="admin-link admin-link--danger">🗑</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
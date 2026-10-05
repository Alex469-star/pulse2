<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('readonly');

$gearId = (int)($_GET['id'] ?? 0);
if ($gearId <= 0) { http_response_code(404); exit('Инвентарь не найден'); }

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update') {
        admin_require('moderator');
        $fields = [
            'name'          => trim((string)($_POST['name'] ?? '')),
            'brand'         => trim((string)($_POST['brand'] ?? '')),
            'model'         => trim((string)($_POST['model'] ?? '')),
            'type'          => (string)($_POST['type'] ?? 'other'),
            'purchase_date' => trim((string)($_POST['purchase_date'] ?? '')) ?: null,
            'notes'         => trim((string)($_POST['notes'] ?? '')),
            'is_retired'    => isset($_POST['is_retired']) ? 1 : 0,
        ];

        $setSql = [];
        $params = [];
        foreach ($fields as $k => $v) {
            $setSql[] = "`$k` = ?";
            $params[] = $v;
        }
        $params[] = $gearId;

        db()->prepare('UPDATE gear SET ' . implode(', ', $setSql) . ' WHERE id = ?')
            ->execute($params);

        admin_audit((int)$admin['id'], 'gear.update', 'gear', $gearId, $fields);
        $success = 'Сохранено';
    }

    if ($action === 'delete') {
        admin_require('super');
        db()->prepare('UPDATE activities SET gear_id = NULL WHERE gear_id = ?')->execute([$gearId]);
        db()->prepare('DELETE FROM gear WHERE id = ?')->execute([$gearId]);
        admin_audit((int)$admin['id'], 'gear.delete', 'gear', $gearId);
        header('Location: ' . admin_url('gear.php'));
        exit;
    }
}

$stmt = db()->prepare(
    'SELECT g.*, u.username, u.display_name, u.id AS owner_id
       FROM gear g JOIN users u ON u.id = g.user_id
      WHERE g.id = ? LIMIT 1'
);
$stmt->execute([$gearId]);
$gear = $stmt->fetch();
if (!$gear) { http_response_code(404); exit('Инвентарь не найден'); }

// Статистика
$statsStmt = db()->prepare(
    'SELECT COUNT(*) AS cnt,
            COALESCE(SUM(distance_m), 0) AS dist,
            COALESCE(SUM(duration_sec), 0) AS dur,
            MAX(COALESCE(started_at, created_at)) AS last_used
       FROM activities WHERE gear_id = ?'
);
$statsStmt->execute([$gearId]);
$stats = $statsStmt->fetch();

// Последние 20 активностей с этим инвентарём
$activitiesStmt = db()->prepare(
    'SELECT id, title, type, distance_m, duration_sec,
            COALESCE(started_at, created_at) AS sort_date
       FROM activities
      WHERE gear_id = ?
   ORDER BY sort_date DESC
      LIMIT 20'
);
$activitiesStmt->execute([$gearId]);
$activities = $activitiesStmt->fetchAll();

$pageTitle = 'Инвентарь: ' . $gear['name'];
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>

<div class="admin-user">

    <div class="admin-card">
        <h3 class="admin-card__title">Сводка</h3>
        <div class="admin-user__stats">
            <div><span class="muted">ID</span><strong><?= (int)$gear['id'] ?></strong></div>
            <div><span class="muted">Владелец</span>
                <a href="<?= e(admin_url('user.php?id=' . (int)$gear['owner_id'])) ?>">
                    <strong><?= e($gear['display_name']) ?></strong>
                </a>
            </div>
            <div><span class="muted">Тип</span><strong><?= e($gear['type']) ?></strong></div>
            <div><span class="muted">Активностей</span><strong><?= admin_format_int((int)$stats['cnt']) ?></strong></div>
            <div><span class="muted">Пробег</span><strong><?= e(number_format((float)$stats['dist'] / 1000, 1, '.', ' ')) ?> км</strong></div>
            <div><span class="muted">Время в движении</span>
                <strong><?= intdiv((int)$stats['dur'], 3600) ?> ч</strong>
            </div>
            <div><span class="muted">Последнее использование</span>
                <strong><?= $stats['last_used'] ? e(admin_time_ago((string)$stats['last_used'])) : '—' ?></strong>
            </div>
            <div><span class="muted">Создан</span>
                <strong><?= e(date('d.m.Y', strtotime((string)$gear['created_at']))) ?></strong>
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
                    <input type="text" name="name" value="<?= e($gear['name']) ?>" required>
                </div>
                <div class="field"><label>Тип</label>
                    <select name="type">
                        <?php foreach (['bike','shoes','skis','other'] as $t): ?>
                            <option value="<?= $t ?>" <?= $gear['type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>Бренд</label>
                    <input type="text" name="brand" value="<?= e((string)$gear['brand']) ?>">
                </div>
                <div class="field"><label>Модель</label>
                    <input type="text" name="model" value="<?= e((string)$gear['model']) ?>">
                </div>
                <div class="field"><label>Дата покупки</label>
                    <input type="date" name="purchase_date" value="<?= e((string)$gear['purchase_date']) ?>">
                </div>
                <div class="field field--checkbox">
                    <label><input type="checkbox" name="is_retired" <?= (int)$gear['is_retired'] ? 'checked' : '' ?>> Списан</label>
                </div>
                <div class="field" style="grid-column:1/-1">
                    <label>Заметки</label>
                    <textarea name="notes" rows="3"><?= e((string)$gear['notes']) ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn--primary">Сохранить</button>
            </div>
        </form>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title">Последние активности с этим инвентарём</h3>
        <?php if (!$activities): ?>
            <p class="muted">Нет привязанных активностей</p>
        <?php else: ?>
            <div class="admin-db__wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>ID</th><th>Название</th><th>Тип</th><th>Дист.</th><th>Дата старта</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activities as $a): ?>
                        <tr>
                            <td><?= (int)$a['id'] ?></td>
                            <td><?= e(mb_substr((string)$a['title'], 0, 50)) ?></td>
                            <td><?= e($a['type']) ?></td>
                            <td><?= e(number_format((float)$a['distance_m'] / 1000, 2, '.', ' ')) ?> км</td>
                            <td class="muted"><?= e(date('d.m.Y H:i', strtotime((string)$a['sort_date']))) ?></td>
                            <td>
                                <a class="admin-link" href="<?= e(url('activity.php?id=' . (int)$a['id'])) ?>" target="_blank">Открыть</a>
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
            <form method="post" onsubmit="return confirm('Удалить инвентарь? Привязки активностей будут сброшены.')">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="btn btn--danger">Удалить инвентарь</button>
            </form>
        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
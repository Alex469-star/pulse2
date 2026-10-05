<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/models/Broadcast.php';

$admin = admin_require('moderator');

$id = (int)($_GET['id'] ?? 0);
$b = Broadcast::findById($id);
if (!$b) { http_response_code(404); exit('Рассылка не найдена'); }

if ($b['status'] !== 'draft') {
    // Отправленную рассылку нельзя редактировать
    flash('Эту рассылку уже нельзя редактировать', 'info');
    redirect(admin_url('broadcasts.php'));
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? 'save');

    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
    $type  = (string)($_POST['type'] ?? 'system');
    $url   = trim((string)($_POST['url'] ?? ''));
    $audience = (string)($_POST['audience'] ?? 'all');

    $meta = [];
    if ($audience === 'club') $meta['club_id'] = (int)($_POST['club_id'] ?? 0);
    if ($audience === 'role') $meta['role'] = (string)($_POST['role'] ?? 'admins');
    if ($audience === 'specific') {
        $ids = [];
        foreach (preg_split('/[\s,]+/', (string)($_POST['user_ids'] ?? '')) as $t) {
            $t = trim($t);
            if ($t === '') continue;
            if (ctype_digit($t)) $ids[] = (int)$t;
        }
        $meta['user_ids'] = array_values(array_unique($ids));
    }

    if (mb_strlen($title) < 3) $error = 'Заголовок минимум 3 символа';
    elseif ($audience === 'specific' && empty($meta['user_ids'])) $error = 'Выберите пользователей';
    elseif ($audience === 'club' && empty($meta['club_id'])) $error = 'Выберите клуб';

    if ($error === null) {
        Broadcast::update($id, [
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'url' => $url ?: null,
            'audience' => $audience,
            'audience_meta' => $meta,
        ]);

        if ($action === 'send') {
            $b = Broadcast::findById($id);
            $ids = Broadcast::resolveAudience($b);
            if (!$ids) {
                flash('Аудитория пуста', 'error');
            } else {
                [$sent, $failed] = Broadcast::send($id, $ids);
                admin_audit((int)$admin['id'], 'broadcast.send', 'broadcast', $id, [
                    'sent' => $sent, 'failed' => $failed,
                ]);
                flash("Отправлено: $sent, ошибок: $failed", 'success');
                redirect(admin_url('broadcasts.php'));
            }
        } elseif ($action === 'test') {
            $adminUser = $admin['user_id'] ?? null;
            if ($adminUser) {
                Broadcast::sendTest($id, (int)$adminUser);
                flash('Тест отправлен вам', 'success');
            } else {
                flash('У админа нет user_id', 'error');
            }
        } else {
            flash('Сохранено', 'success');
        }
        redirect(admin_url('broadcast-edit.php?id=' . $id));
    }

    // Обновим $b для рендера
    $b = array_merge($b, [
        'title' => $title, 'body' => $body, 'type' => $type,
        'url' => $url, 'audience' => $audience,
        'audience_meta' => json_encode($meta),
    ]);
}

$meta = json_decode((string)($b['audience_meta'] ?? '{}'), true) ?: [];

$clubs = [];
try {
    $clubs = db()->query(
        'SELECT id, name FROM clubs WHERE is_banned = 0 ORDER BY name ASC LIMIT 200'
    )->fetchAll();
} catch (Throwable $e) { $clubs = []; }

$pageTitle = 'Редактирование рассылки';
require __DIR__ . '/includes/header.php';
?>

<section class="admin-card">
    <div class="admin-card__head">
        <h3 class="admin-card__title">Рассылка #<?= (int)$id ?></h3>
        <a class="admin-link" href="<?= e(admin_url('broadcasts.php')) ?>">← к списку</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert--error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <?= admin_csrf_field() ?>

        <div class="field">
            <label>Заголовок</label>
            <input type="text" name="title" value="<?= e($b['title']) ?>" required maxlength="150">
        </div>

        <div class="field">
            <label>Текст</label>
            <textarea name="body" rows="4" maxlength="500"><?= e($b['body']) ?></textarea>
        </div>

        <div class="form-row">
            <div class="field">
                <label>Тип</label>
                <select name="type">
                    <?php foreach (['system'=>'Системное','info'=>'Информационное','warning'=>'Предупреждение','promo'=>'Промо'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $b['type'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>URL</label>
                <input type="text" name="url" value="<?= e((string)$b['url']) ?>">
            </div>
        </div>

        <div class="field">
            <label>Аудитория</label>
            <select name="audience" id="audience-select">
                <?php foreach ([
                    'all'=>'Все', 'active'=>'Активные', 'inactive'=>'Неактивные',
                    'new'=>'Новые', 'club'=>'Клуб', 'role'=>'Роль', 'specific'=>'Выбранные'
                ] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $b['audience'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field audience-block" data-audience="club" style="<?= $b['audience'] === 'club' ? '' : 'display:none' ?>">
            <label>Клуб</label>
            <select name="club_id">
                <option value="">—</option>
                <?php foreach ($clubs as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                        <?= (int)($meta['club_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field audience-block" data-audience="role" style="<?= $b['audience'] === 'role' ? '' : 'display:none' ?>">
            <label>Категория</label>
            <select name="role">
                <option value="admins" <?= ($meta['role'] ?? '') === 'admins' ? 'selected' : '' ?>>Админы клубов</option>
                <option value="creators" <?= ($meta['role'] ?? '') === 'creators' ? 'selected' : '' ?>>Создатели</option>
            </select>
        </div>

        <div class="field audience-block" data-audience="specific" style="<?= $b['audience'] === 'specific' ? '' : 'display:none' ?>">
            <label>Пользователи</label>
            <textarea name="user_ids" rows="3"><?= e(implode(', ', $meta['user_ids'] ?? [])) ?></textarea>
        </div>

        <div class="form-actions" style="margin-top:24px">
            <button type="submit" name="action" value="save" class="btn btn--ghost">💾 Сохранить</button>
            <button type="submit" name="action" value="test" class="btn btn--ghost">🧪 Тест себе</button>
            <button type="submit" name="action" value="send" class="btn btn--primary"
                    onclick="return confirm('Отправить рассылку?')">📤 Отправить</button>
        </div>
    </form>
</section>

<script>
(function () {
    var sel = document.getElementById('audience-select');
    var blocks = document.querySelectorAll('.audience-block');
    function update() {
        blocks.forEach(function (b) {
            b.style.display = b.dataset.audience === sel.value ? '' : 'none';
        });
    }
    sel.addEventListener('change', update);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
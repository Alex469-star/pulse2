<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/models/Broadcast.php';

$admin = admin_require('moderator');

$error = null;
$success = null;

$old = [
    'title' => '',
    'body' => '',
    'type' => 'system',
    'url' => '',
    'audience' => 'all',
    'audience_meta' => [],
];

// ---- Для аудитории "specific" — поиск пользователей ----
$specificIds = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['audience'] ?? '') === 'specific') {
    $raw = (string)($_POST['user_ids'] ?? '');
    foreach (preg_split('/[\s,]+/', $raw) as $token) {
        $token = trim($token);
        if ($token === '') continue;
        if (ctype_digit($token)) {
            $specificIds[] = (int)$token;
        } elseif (preg_match('/^@?([a-z0-9_-]+)$/i', $token, $m)) {
            // Ищем по username
            $u = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $u->execute([$m[1]]);
            $id = (int)$u->fetchColumn();
            if ($id > 0) $specificIds[] = $id;
        }
    }
    $specificIds = array_values(array_unique($specificIds));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? 'draft');

    $old = [
        'title' => trim((string)($_POST['title'] ?? '')),
        'body' => trim((string)($_POST['body'] ?? '')),
        'type' => (string)($_POST['type'] ?? 'system'),
        'url' => trim((string)($_POST['url'] ?? '')),
        'audience' => (string)($_POST['audience'] ?? 'all'),
        'audience_meta' => [],
    ];

    if ($old['audience'] === 'specific') {
        $old['audience_meta']['user_ids'] = $specificIds;
    } elseif ($old['audience'] === 'club') {
        $old['audience_meta']['club_id'] = (int)($_POST['club_id'] ?? 0);
    } elseif ($old['audience'] === 'role') {
        $old['audience_meta']['role'] = (string)($_POST['role'] ?? 'admins');
    }

    // ---- Валидация ----
    if (mb_strlen($old['title']) < 3) {
        $error = 'Заголовок минимум 3 символа';
    } elseif (mb_strlen($old['title']) > 150) {
        $error = 'Заголовок слишком длинный';
    } elseif (mb_strlen($old['body']) > 500) {
        $error = 'Текст слишком длинный';
    } elseif ($old['url'] !== '' && !preg_match('~^(https?://|/)~', $old['url'])) {
        $error = 'URL должен начинаться с http(s):// или /';
    } elseif ($old['audience'] === 'specific' && !$specificIds) {
        $error = 'Выберите хотя бы одного пользователя';
    } elseif ($old['audience'] === 'club' && empty($old['audience_meta']['club_id'])) {
        $error = 'Выберите клуб';
    }

    if ($error === null) {
        try {
            $broadcastId = Broadcast::create((int)$admin['id'], [
                'title' => $old['title'],
                'body' => $old['body'],
                'type' => $old['type'],
                'url' => $old['url'] ?: null,
                'audience' => $old['audience'],
                'audience_meta' => $old['audience_meta'],
                'status' => 'draft',
            ]);

            if ($action === 'test') {
                // Отправляем тест себе
                $adminUser = $admin['user_id'] ?? null;
                if ($adminUser) {
                    Broadcast::sendTest($broadcastId, (int)$adminUser);
                    flash('Тестовое уведомление отправлено вам', 'success');
                } else {
                    flash('У админа нет связанного user_id — тест невозможен', 'error');
                }
                redirect(admin_url('broadcast-edit.php?id=' . $broadcastId));
            }

            if ($action === 'send') {
                $broadcast = Broadcast::findById($broadcastId);
                $ids = Broadcast::resolveAudience($broadcast);
                if (!$ids) {
                    flash('Аудитория пуста — рассылка не отправлена', 'error');
                    redirect(admin_url('broadcast-edit.php?id=' . $broadcastId));
                }
                [$sent, $failed] = Broadcast::send($broadcastId, $ids);
                admin_audit((int)$admin['id'], 'broadcast.send', 'broadcast', $broadcastId, [
                    'sent' => $sent, 'failed' => $failed, 'audience' => $old['audience'],
                ]);
                flash("Отправлено: $sent, ошибок: $failed", 'success');
                redirect(admin_url('broadcasts.php'));
            }

            flash('Черновик сохранён', 'success');
            redirect(admin_url('broadcast-edit.php?id=' . $broadcastId));
        } catch (Throwable $e) {
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}

// ---- Список клубов для аудитории ----
$clubs = [];
try {
    $clubs = db()->query(
        'SELECT id, name FROM clubs WHERE is_banned = 0 ORDER BY name ASC LIMIT 200'
    )->fetchAll();
} catch (Throwable $e) { $clubs = []; }

$pageTitle = 'Новая рассылка';
require __DIR__ . '/includes/header.php';
?>

<section class="admin-card">
    <h3 class="admin-card__title">Создать рассылку</h3>

    <?php if ($error): ?>
        <div class="alert alert--error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="admin-form">
        <?= admin_csrf_field() ?>

        <div class="field">
            <label>Заголовок *</label>
            <input type="text" name="title" value="<?= e($old['title']) ?>"
                   required maxlength="150" autofocus
                   placeholder="Например: Обновление сервиса">
            <div class="field__hint">Появится крупно в уведомлении.</div>
        </div>

        <div class="field">
            <label>Текст сообщения</label>
            <textarea name="body" rows="4" maxlength="500"
                      placeholder="Что вы хотите сообщить пользователю..."><?= e($old['body']) ?></textarea>
            <div class="field__hint">Необязательно, но желательно. Максимум 500 символов.</div>
        </div>

        <div class="form-row">
            <div class="field">
                <label>Тип уведомления</label>
                <select name="type">
                    <option value="system"  <?= $old['type'] === 'system' ? 'selected' : '' ?>>Системное</option>
                    <option value="info"    <?= $old['type'] === 'info' ? 'selected' : '' ?>>Информационное</option>
                    <option value="warning" <?= $old['type'] === 'warning' ? 'selected' : '' ?>>Предупреждение</option>
                    <option value="promo"   <?= $old['type'] === 'promo' ? 'selected' : '' ?>>Промо / новость</option>
                </select>
            </div>
            <div class="field">
                <label>Ссылка (опционально)</label>
                <input type="text" name="url" value="<?= e($old['url']) ?>"
                       placeholder="https://... или /pulse/clubs.php">
                <div class="field__hint">Куда ведёт уведомление при клике.</div>
            </div>
        </div>

        <div class="field">
            <label>Аудитория</label>
            <select name="audience" id="audience-select">
                <option value="all"      <?= $old['audience'] === 'all' ? 'selected' : '' ?>>Все пользователи</option>
                <option value="active"   <?= $old['audience'] === 'active' ? 'selected' : '' ?>>Активные (30 дней)</option>
                <option value="inactive" <?= $old['audience'] === 'inactive' ? 'selected' : '' ?>>Неактивные (60+ дней)</option>
                <option value="new"      <?= $old['audience'] === 'new' ? 'selected' : '' ?>>Новые (14 дней)</option>
                <option value="club"     <?= $old['audience'] === 'club' ? 'selected' : '' ?>>Участники клуба</option>
                <option value="role"     <?= $old['audience'] === 'role' ? 'selected' : '' ?>>По роли</option>
                <option value="specific" <?= $old['audience'] === 'specific' ? 'selected' : '' ?>>Конкретные пользователи</option>
            </select>
        </div>

        <!-- Клуб -->
        <div class="field audience-block" data-audience="club"
             style="<?= $old['audience'] === 'club' ? '' : 'display:none' ?>">
            <label>Выберите клуб</label>
            <select name="club_id">
                <option value="">— выберите —</option>
                <?php foreach ($clubs as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                        <?= (int)($old['audience_meta']['club_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Роль -->
        <div class="field audience-block" data-audience="role"
             style="<?= $old['audience'] === 'role' ? '' : 'display:none' ?>">
            <label>Категория</label>
            <select name="role">
                <option value="admins"   <?= ($old['audience_meta']['role'] ?? '') === 'admins' ? 'selected' : '' ?>>Админы и владельцы клубов</option>
                <option value="creators" <?= ($old['audience_meta']['role'] ?? '') === 'creators' ? 'selected' : '' ?>>Активные создатели (3+ активности)</option>
            </select>
        </div>

        <!-- Список пользователей -->
        <div class="field audience-block" data-audience="specific"
             style="<?= $old['audience'] === 'specific' ? '' : 'display:none' ?>">
            <label>Пользователи</label>
            <textarea name="user_ids" rows="3"
                      placeholder="ID или username через запятую: 42, @ivan, 55"><?= e(implode(', ', array_map(fn($i) => $i, $specificIds))) ?></textarea>
            <div class="field__hint">Можно указывать ID или @username. Максимум 500.</div>
        </div>

        <div class="form-actions" style="margin-top:24px">
            <button type="submit" name="action" value="draft" class="btn btn--ghost">
                💾 Сохранить как черновик
            </button>
            <button type="submit" name="action" value="test" class="btn btn--ghost">
                🧪 Отправить тест себе
            </button>
            <button type="submit" name="action" value="send" class="btn btn--primary"
                    onclick="return confirm('Отправить уведомления всем выбранным пользователям?')">
                📤 Отправить
            </button>
        </div>
    </form>
</section>

<!-- Превью -->
<section class="admin-card" style="margin-top:20px">
    <h3 class="admin-card__title">Предпросмотр в колокольчике</h3>
    <div class="broadcast-preview">
        <div class="notif-row notif-row--unread">
            <div class="notif-row__icon notif-row__icon--system">🔔</div>
            <div class="notif-row__body">
                <div class="notif-row__text" id="preview-text">
                    <strong id="preview-title">Заголовок уведомления</strong>
                    <span id="preview-body"></span>
                </div>
                <div class="notif-row__meta">
                    <span class="notif-row__time">только что</span>
                    <span class="notif-row__new">new</span>
                </div>
            </div>
            <div class="notif-row__arrow">›</div>
        </div>
    </div>
</section>

<script>
(function () {
    var sel = document.getElementById('audience-select');
    var blocks = document.querySelectorAll('.audience-block');
    function update() {
        var v = sel.value;
        blocks.forEach(function (b) {
            b.style.display = b.dataset.audience === v ? '' : 'none';
        });
    }
    sel.addEventListener('change', update);

    // Живой предпросмотр
    var titleIn = document.querySelector('input[name="title"]');
    var bodyIn  = document.querySelector('textarea[name="body"]');
    var pvT = document.getElementById('preview-title');
    var pvB = document.getElementById('preview-body');
    function updPreview() {
        pvT.textContent = titleIn.value.trim() || 'Заголовок уведомления';
        var b = bodyIn.value.trim();
        pvB.textContent = b ? (': ' + b) : '';
    }
    titleIn.addEventListener('input', updPreview);
    bodyIn.addEventListener('input', updPreview);
    updPreview();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
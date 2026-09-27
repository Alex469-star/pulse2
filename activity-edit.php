<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/models/Gear.php';

auth_start();
$me = require_login();

$activityId = (int)($_GET['id'] ?? 0);
if ($activityId <= 0) {
    http_response_code(404);
    exit('Активность не найдена');
}

$activity = Activity::findById($activityId);
if (!$activity) {
    http_response_code(404);
    exit('Активность не найдена');
}

if ((int)$activity['user_id'] !== (int)$me['id']) {
    http_response_code(403);
    exit('Редактировать можно только свои активности');
}

$errors = [];
$old = [
    'title'       => (string)$activity['title'],
    'description' => (string)($activity['description'] ?? ''),
    'type'        => (string)$activity['type'],
    'visibility'  => (string)$activity['visibility'],
    'gear_id'     => (string)($activity['gear_id'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['title']       = trim((string)($_POST['title'] ?? ''));
    $old['description'] = trim((string)($_POST['description'] ?? ''));
    $old['type']        = (string)($_POST['type'] ?? 'run');
    $old['visibility']  = (string)($_POST['visibility'] ?? 'public');
    $old['gear_id']     = (string)($_POST['gear_id'] ?? '');

    if ($old['title'] === '') $errors['title'] = 'Введите название';
    elseif (mb_strlen($old['title']) > 190) $errors['title'] = 'Максимум 190 символов';

    if (mb_strlen($old['description']) > 2000) $errors['description'] = 'Максимум 2000 символов';

    $allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
    if (!in_array($old['type'], $allowedTypes, true)) $old['type'] = 'run';

    $allowedVis = ['public','followers','private'];
    if (!in_array($old['visibility'], $allowedVis, true)) $old['visibility'] = 'public';

    $gearId = null;
    if ($old['gear_id'] !== '') {
        $g = Gear::findById((int)$old['gear_id']);
        if ($g && (int)$g['user_id'] === (int)$me['id']) {
            $gearId = (int)$g['id'];
        }
    }

    if (!$errors) {
        try {
            db()->prepare(
                'UPDATE activities
                 SET title = :title,
                     description = :description,
                     type = :type,
                     visibility = :visibility,
                     gear_id = :gear_id
                 WHERE id = :id AND user_id = :uid'
            )->execute([
                ':title'       => $old['title'],
                ':description' => $old['description'] !== '' ? $old['description'] : null,
                ':type'        => $old['type'],
                ':visibility'  => $old['visibility'],
                ':gear_id'     => $gearId,
                ':id'          => $activityId,
                ':uid'         => (int)$me['id'],
            ]);

            // Загрузка новых фото
            if (!empty($_FILES['photos']['tmp_name'][0])) {
                $existingCount = Activity::photoCount($activityId);
                $order = $existingCount;

                foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
                    if ($order >= 10) break;
                    if (!is_uploaded_file($tmp)) continue;
                    $err = (int)($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                    if ($err !== UPLOAD_ERR_OK) continue;

                    $res = upload_photo([
                        'tmp_name' => $tmp,
                        'size'     => (int)($_FILES['photos']['size'][$i] ?? 0),
                        'error'    => $err,
                    ], 'activities', (int)$me['id']);

                    if ($res['url']) {
                        Activity::addPhoto($activityId, $res['url'], $order++);
                    }
                }
            }

            flash('Изменения сохранены', 'success');
            redirect(url('activity.php?id=' . $activityId));
        } catch (Throwable $e) {
            $errors['_general'] = 'Не удалось сохранить: ' . $e->getMessage();
        }
    }
}

$gearList = Gear::allForUser((int)$me['id']);
$editPhotos = Activity::photos($activityId);

$pageTitle = 'Редактировать активность';

// Подключаем heic2any — для конвертации HEIC в JPEG на клиенте
$extraJs = array_merge($extraJs ?? [], [
    'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js',
]);

require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Редактировать активность</h1>
        <p class="form-card__subtitle">
            Изменить название, описание, тип, видимость, привязку к инвентарю и фотографии.
        </p>

        <?php if (!empty($errors['_general'])): ?>
            <div class="alert alert--error"><?= e($errors['_general']) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label for="title">Название</label>
                <input type="text" id="title" name="title" value="<?= e($old['title']) ?>" required maxlength="190">
                <?php if (!empty($errors['title'])): ?>
                    <span class="field__error"><?= e($errors['title']) ?></span>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="description">Описание</label>
                <textarea id="description" name="description" rows="3" maxlength="2000"><?= e($old['description']) ?></textarea>
                <?php if (!empty($errors['description'])): ?>
                    <span class="field__error"><?= e($errors['description']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="type">Тип</label>
                    <select id="type" name="type">
                        <option value="run"   <?= $old['type'] === 'run'   ? 'selected' : '' ?>>🏃 Бег</option>
                        <option value="ride"  <?= $old['type'] === 'ride'  ? 'selected' : '' ?>>🚴 Велосипед</option>
                        <option value="swim"  <?= $old['type'] === 'swim'  ? 'selected' : '' ?>>🏊 Плавание</option>
                        <option value="ski"   <?= $old['type'] === 'ski'   ? 'selected' : '' ?>>⛷️ Лыжи</option>
                        <option value="walk"  <?= $old['type'] === 'walk'  ? 'selected' : '' ?>>🚶 Ходьба</option>
                        <option value="hike"  <?= $old['type'] === 'hike'  ? 'selected' : '' ?>>🥾 Хайкинг</option>
                        <option value="other" <?= $old['type'] === 'other' ? 'selected' : '' ?>>📦 Другое</option>
                    </select>
                </div>

                <div class="field">
                    <label for="visibility">Кто видит</label>
                    <select id="visibility" name="visibility">
                        <option value="public"    <?= $old['visibility'] === 'public'    ? 'selected' : '' ?>>Публичная</option>
                        <option value="followers" <?= $old['visibility'] === 'followers' ? 'selected' : '' ?>>Только подписчики</option>
                        <option value="private"   <?= $old['visibility'] === 'private'   ? 'selected' : '' ?>>Приватная</option>
                    </select>
                </div>
            </div>

            <?php if ($gearList): ?>
                <div class="field">
                    <label for="gear_id">Инвентарь</label>
                    <select id="gear_id" name="gear_id">
                        <option value="">— не привязывать —</option>
                        <?php foreach ($gearList as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= $old['gear_id'] === (string)$g['id'] ? 'selected' : '' ?>>
                                <?= e($g['name']) ?><?php if (!empty($g['brand'])): ?> · <?= e($g['brand']) ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($editPhotos): ?>
                <div class="post-existing-photos">
                    <label>Фотографии активности</label>
                    <div class="post-photos-grid">
                        <?php foreach ($editPhotos as $ph): ?>
                            <div class="post-photo-item">
                                <img src="<?= e($ph['url']) ?>" alt="">
                                <button type="button"
                                        class="post-photo-item__delete js-delete-activity-photo"
                                        data-photo-id="<?= (int)$ph['id'] ?>"
                                        data-activity-id="<?= (int)$activityId ?>"
                                        title="Удалить фото">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <span class="field__hint">Нажмите × на фото, чтобы удалить. Сохраняется сразу.</span>
                </div>
            <?php endif; ?>

            <div class="field">
    <label for="photos"><?= $editPhotos ? 'Добавить ещё фото' : 'Фото' ?> <span class="muted">(до 10 всего)</span></label>
    <input type="file" id="photos" name="photos[]" accept="image/*,.heic,.heif" multiple>
    <span class="field__hint">JPG, PNG, WEBP, HEIC · до 10 МБ каждое · максимум 10 фото на активность</span>
</div>

<!-- Прогресс конвертации HEIC -->
<div id="heic-progress" class="heic-progress" hidden>
    <span class="heic-progress__spinner"></span>
    <span class="heic-progress__text">Конвертирую HEIC-фото…</span>
</div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary btn--large">Сохранить</button>
                <a href="<?= e(url('activity.php?id=' . $activityId)) ?>" class="btn btn--ghost btn--large">Отмена</a>
            </div>
        </form>

        <div class="danger-zone">
            <h3 class="danger-zone__title">Опасная зона</h3>
            <p class="muted">Удаление необратимо. Все лайки, комментарии, фото и усилия по сегментам будут удалены.</p>
            <form method="post" action="<?= e(url('activity.php?id=' . $activityId)) ?>"
                  onsubmit="return confirm('Удалить активность? Это необратимо.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="btn btn--danger">🗑 Удалить активность</button>
            </form>
        </div>
    </div>
</section>

<script>
(function () {
    var csrf = <?= json_encode(csrf_token()) ?>;
    var deleteApi = <?= json_encode(url('api/activity-photo-delete.php')) ?>;

    document.querySelectorAll('.js-delete-activity-photo').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Удалить фото?')) return;

            fetch(deleteApi, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({
                    photo_id: btn.dataset.photoId,
                    activity_id: btn.dataset.activityId
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.ok) btn.closest('.post-photo-item').remove();
                else alert(res.error || 'Ошибка удаления');
            })
            .catch(function () { alert('Ошибка сети'); });
        });
    });
})();
</script>

<script>
(function () {
    "use strict";

    var input = document.getElementById("photos");
    if (!input) return;

    var progress = document.getElementById("heic-progress");
    var form = input.closest("form");
    if (!form) return;

    if (typeof heic2any === "undefined") {
        console.warn("heic2any не загрузился — HEIC не будет конвертироваться");
    }

    form.addEventListener("submit", function (e) {
        if (!input.files || !input.files.length) return;

        var hasHeic = false;
        for (var i = 0; i < input.files.length; i++) {
            var name = (input.files[i].name || "").toLowerCase();
            if (name.endsWith(".heic") || name.endsWith(".heif")) {
                hasHeic = true;
                break;
            }
        }
        if (!hasHeic) return;

        e.preventDefault();

        if (progress) progress.hidden = false;

        var files = Array.from(input.files);
        var converted = [];
        var chain = Promise.resolve();

        files.forEach(function (file) {
            chain = chain.then(function () {
                var name = (file.name || "").toLowerCase();
                var isHeic = name.endsWith(".heic") || name.endsWith(".heif");

                if (!isHeic || typeof heic2any === "undefined") {
                    converted.push(file);
                    return;
                }

                return heic2any({
                    blob: file,
                    toType: "image/jpeg",
                    quality: 0.85
                }).then(function (result) {
                    var blob = Array.isArray(result) ? result[0] : result;
                    var newName = file.name.replace(/\.(heic|heif)$/i, ".jpg");
                    converted.push(new File([blob], newName, { type: "image/jpeg" }));
                }).catch(function (err) {
                    console.error("Ошибка конвертации HEIC:", err);
                    converted.push(file);
                });
            });
        });

        chain.then(function () {
            var dt = new DataTransfer();
            converted.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;

            if (progress) progress.hidden = true;

// Отправляем через fetch, чтобы гарантированно передать cookies и csrf
var fd = new FormData(form);
// Явно перекладываем сконвертированные файлы
fd.delete('files[]');
converted.forEach(function (f) {
    fd.append('files[]', f, f.name);
});
// Перекладываем фото активностей, если были
if (input === photosInput) {
    fd.delete('photos[]');
    converted.forEach(function (f) {
        fd.append('photos[]', f, f.name);
    });
}

fetch(form.action || window.location.href, {
    method: 'POST',
    body: fd,
    credentials: 'same-origin'
}).then(function (response) {
    // Сервер ответил — переходим на ответ (обычно это redirect → HTML)
    return response.text().then(function (html) {
        // Простейший способ: заменить содержимое страницы
        document.open();
        document.write(html);
        document.close();
    });
}).catch(function (err) {
    console.error(err);
    alert('Ошибка отправки. Попробуйте ещё раз.');
});
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
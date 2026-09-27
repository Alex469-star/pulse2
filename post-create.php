<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Post.php';

auth_start();
$me = require_login();

// ---- Режим редактирования ----
$editId = (int)($_GET['id'] ?? 0);
$editPost = null;
if ($editId > 0) {
    $editPost = Post::findById($editId);
    if (!$editPost || (int)$editPost['user_id'] !== (int)$me['id']) {
        flash('Запись не найдена', 'error');
        redirect(url('feed.php'));
    }
}

$errors = [];
$old = [
    'title'      => $editPost ? (string)$editPost['title'] : '',
    'body'       => $editPost ? (string)$editPost['body'] : '',
    'visibility' => $editPost ? (string)$editPost['visibility'] : 'public',
];

// ---- Пути для фото ----
$uploadsDir = __DIR__ . '/assets/uploads/posts';
$uploadsUrl = url('assets/uploads/posts');
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

// ============================================================
// ОБРАБОТКА POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['title']      = trim((string)($_POST['title'] ?? ''));
    $old['body']       = trim((string)($_POST['body'] ?? ''));
    $old['visibility'] = (string)($_POST['visibility'] ?? 'public');

    if ($old['title'] === '') $errors['title'] = 'Введите заголовок';
    elseif (mb_strlen($old['title']) > 190) $errors['title'] = 'Максимум 190 символов';

    if ($old['body'] === '') $errors['body'] = 'Введите текст';
    elseif (mb_strlen($old['body']) > 20000) $errors['body'] = 'Максимум 20000 символов';

    $allowedVis = ['public', 'followers', 'private'];
    if (!in_array($old['visibility'], $allowedVis, true)) $old['visibility'] = 'public';

    if (!$errors) {
        try {
            if ($editPost) {
                Post::update($editId, (int)$me['id'], [
                    'title'      => $old['title'],
                    'body'       => $old['body'],
                    'visibility' => $old['visibility'],
                ]);
                $postId = $editId;
                $msg = 'Запись обновлена';
            } else {
                $postId = Post::create((int)$me['id'], [
                    'title'      => $old['title'],
                    'body'       => $old['body'],
                    'visibility' => $old['visibility'],
                ]);
                $msg = 'Запись создана';
            }

            // Загрузка новых фото
            if (!empty($_FILES['photos']['tmp_name'][0])) {
                $order = count(Post::photos($postId));
                foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
                    if (!is_uploaded_file($tmp)) continue;
                    $err = (int)($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                    if ($err !== UPLOAD_ERR_OK) continue;

                    $res = post_handle_upload([
                        'tmp_name' => $tmp,
                        'size'     => (int)($_FILES['photos']['size'][$i] ?? 0),
                    ], $uploadsDir, $uploadsUrl, (int)$me['id']);

                    if ($res['url']) {
                        Post::addPhoto($postId, $res['url'], $order++);
                    }
                }
            }

            flash($msg, 'success');
            redirect(url('post.php?id=' . $postId));
        } catch (Throwable $e) {
            $errors['_general'] = 'Не удалось сохранить: ' . $e->getMessage();
        }
    }
}

$existingPhotos = $editPost ? Post::photos($editId) : [];

$pageTitle = $editPost ? 'Редактировать запись' : 'Новая запись';

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

function post_handle_upload(array $file, string $dir, string $urlBase, int $userId): array
{
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        return ['url' => null, 'error' => 'Файл больше 8 МБ'];
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return ['url' => null, 'error' => 'Не изображение'];

    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($info['mime'], $allowedMime, true)) {
        return ['url' => null, 'error' => 'Только JPG, PNG, WEBP'];
    }

    $ext = match ($info['mime']) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $filename = 'p' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['url' => null, 'error' => 'Не удалось сохранить файл'];
    }

    post_resize($target, 1600, 1600);
    return ['url' => $urlBase . '/' . $filename, 'error' => null];
}

function post_resize(string $path, int $maxW, int $maxH): void
{
    if (!function_exists('imagecreatefromjpeg')) return;
    $info = @getimagesize($path);
    if (!$info) return;
    [$w, $h] = $info;
    $mime = $info['mime'];
    if ($w <= $maxW && $h <= $maxH) return;

    $ratio = min($maxW / $w, $maxH / $h);
    $newW = (int)round($w * $ratio);
    $newH = (int)round($h * $ratio);

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => null,
    };
    if (!$src) return;

    $dst = imagecreatetruecolor($newW, $newH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

    match ($mime) {
        'image/jpeg' => imagejpeg($dst, $path, 88),
        'image/png'  => imagepng($dst, $path, 8),
        'image/webp' => imagewebp($dst, $path, 88),
        default      => null,
    };

    imagedestroy($src);
    imagedestroy($dst);
}

// Подключаем heic2any — конвертация HEIC в JPEG на клиенте
$extraJs = array_merge($extraJs ?? [], [
    'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js',
]);

require __DIR__ . '/includes/header.php';
?>

<section class="form-page form-page--wide">
    <div class="form-card">
        <h1 class="form-card__title">
            <?= $editPost ? 'Редактировать запись' : 'Новая запись в блог' ?>
        </h1>
        <p class="form-card__subtitle">
            Поделитесь историей, впечатлением или заметкой. Можно прикрепить несколько фото.
        </p>

        <?php if (!empty($errors['_general'])): ?>
            <div class="alert alert--error"><?= e($errors['_general']) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label for="title">Заголовок</label>
                <input type="text" id="title" name="title" value="<?= e($old['title']) ?>"
                       required maxlength="190" placeholder="Например: Первый марафон — как это было">
                <?php if (!empty($errors['title'])): ?>
                    <span class="field__error"><?= e($errors['title']) ?></span>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="body">Текст</label>
                <textarea id="body" name="body" rows="12" required maxlength="20000"
                          placeholder="Расскажите, что произошло..."><?= e($old['body']) ?></textarea>
                <?php if (!empty($errors['body'])): ?>
                    <span class="field__error"><?= e($errors['body']) ?></span>
                <?php endif; ?>
                <span class="field__hint">До 20000 символов. Переводы строк сохраняются.</span>
            </div>

            <div class="field">
                <label for="visibility">Кто видит</label>
                <select id="visibility" name="visibility">
                    <option value="public"    <?= $old['visibility'] === 'public'    ? 'selected' : '' ?>>Публичная — видят все</option>
                    <option value="followers" <?= $old['visibility'] === 'followers' ? 'selected' : '' ?>>Только подписчики</option>
                    <option value="private"   <?= $old['visibility'] === 'private'   ? 'selected' : '' ?>>Приватная — только я</option>
                </select>
            </div>

            <?php if ($existingPhotos): ?>
                <div class="post-existing-photos">
                    <label>Текущие фото</label>
                    <div class="post-photos-grid">
                        <?php foreach ($existingPhotos as $ph): ?>
                            <div class="post-photo-item">
                                <img src="<?= e($ph['url']) ?>" alt="">
                                <button type="button"
                                        class="post-photo-item__delete js-delete-photo"
                                        data-photo-id="<?= (int)$ph['id'] ?>"
                                        data-post-id="<?= (int)$editId ?>"
                                        title="Удалить фото">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <span class="field__hint">Нажмите × на фото, чтобы удалить. Сохраняется сразу.</span>
                </div>
            <?php endif; ?>

            <div class="field">
    <label for="photos"><?= $existingPhotos ? 'Добавить ещё фото' : 'Фото' ?></label>
    <input type="file" id="photos" name="photos[]" accept="image/*,.heic,.heif" multiple>
    <span class="field__hint">JPG, PNG, WEBP, HEIC · до 8 МБ каждое · можно выбрать несколько</span>
</div>

<!-- Прогресс конвертации HEIC -->
<div id="heic-progress" class="heic-progress" hidden>
    <span class="heic-progress__spinner"></span>
    <span class="heic-progress__text">Конвертирую HEIC-фото…</span>
</div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary btn--large">
                    <?= $editPost ? 'Сохранить' : 'Опубликовать' ?>
                </button>
                <a href="<?= e($editPost ? url('post.php?id=' . $editId) : url('feed.php')) ?>"
                   class="btn btn--ghost btn--large">Отмена</a>
            </div>
        </form>

        <?php if ($editPost): ?>
            <div class="danger-zone">
                <h3 class="danger-zone__title">Опасная зона</h3>
                <p class="muted">Удаление записи необратимо. Все лайки, комментарии и фото будут удалены.</p>
                <form method="post" action="<?= e(url('post.php?id=' . $editId)) ?>"
                      onsubmit="return confirm('Удалить запись? Это необратимо.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn--danger">🗑 Удалить запись</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    var csrf = <?= json_encode(csrf_token()) ?>;
    var deleteApi = <?= json_encode(url('api/post-photo-delete.php')) ?>;

    document.querySelectorAll('.js-delete-photo').forEach(function (btn) {
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
                    post_id: btn.dataset.postId
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.ok) {
                    btn.closest('.post-photo-item').remove();
                } else {
                    alert(res.error || 'Ошибка удаления');
                }
            })
            .catch(function () { alert('Ошибка сети'); });
        });
    });
})();
</script>

<script>
window.addEventListener('load', function () {
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
            form.submit();
        });
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
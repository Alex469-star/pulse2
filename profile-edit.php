<?php
declare(strict_types=1);

// ВРЕМЕННО для отладки — уберите после того, как всё заработает
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/User.php';

auth_start();
$me = require_login();

$errors  = [];
$success = null;

// ---- Пути для аватаров ----
$uploadsDir = __DIR__ . '/assets/uploads/avatars';
$uploadsUrl = url('assets/uploads/avatars');

// Создаём папку, если её нет
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0755, true);
}

// ---- Обработка формы ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? 'profile';

    // ============================================================
    // Обновление профиля
    // ============================================================
    if ($action === 'profile') {
        $fields = [
            'display_name' => trim((string)($_POST['display_name'] ?? '')),
            'bio'          => trim((string)($_POST['bio'] ?? '')) ?: null,
            'city'         => trim((string)($_POST['city'] ?? '')) ?: null,
            'country'      => trim((string)($_POST['country'] ?? '')) ?: null,
            'gender'       => in_array($_POST['gender'] ?? '', ['male','female','other'], true) ? $_POST['gender'] : null,
            'birth_date'   => !empty($_POST['birth_date']) ? $_POST['birth_date'] : null,
            'weight_kg'    => ($_POST['weight_kg'] ?? '') !== '' ? (float)$_POST['weight_kg'] : null,
            'height_cm'    => ($_POST['height_cm'] ?? '') !== '' ? (float)$_POST['height_cm'] : null,
            'units'        => in_array($_POST['units'] ?? '', ['metric','imperial'], true) ? $_POST['units'] : 'metric',
            'is_public'    => isset($_POST['is_public']) ? 1 : 0,
        ];

        // Валидация
        if ($fields['display_name'] === '') {
            $errors['display_name'] = 'Введите отображаемое имя';
        } elseif (mb_strlen($fields['display_name']) > 120) {
            $errors['display_name'] = 'Максимум 120 символов';
        }

        if ($fields['bio'] !== null && mb_strlen($fields['bio']) > 500) {
            $errors['bio'] = 'Максимум 500 символов';
        }

        if ($fields['weight_kg'] !== null && ($fields['weight_kg'] < 20 || $fields['weight_kg'] > 400)) {
            $errors['weight_kg'] = 'Недопустимый вес';
        }

        if ($fields['height_cm'] !== null && ($fields['height_cm'] < 50 || $fields['height_cm'] > 250)) {
            $errors['height_cm'] = 'Недопустимый рост';
        }

        if ($fields['birth_date'] !== null) {
            $bd = strtotime($fields['birth_date']);
            if ($bd === false || $bd > time()) {
                $errors['birth_date'] = 'Некорректная дата';
            }
        }

        // ---- Загрузка аватара ----
        $newAvatarUrl = null;
        if (!empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
            $file = $_FILES['avatar'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors['avatar'] = 'Ошибка загрузки файла (код ' . (int)$file['error'] . ')';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $errors['avatar'] = 'Файл больше 5 МБ';
            } else {
                // Проверка, что это картинка
                $info = @getimagesize($file['tmp_name']);
                if ($info === false) {
                    $errors['avatar'] = 'Файл не является изображением';
                } else {
                    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                    if (!in_array($info['mime'], $allowedMime, true)) {
                        $errors['avatar'] = 'Поддерживаются JPG, PNG, WEBP, GIF';
                    } else {
                        // Генерируем имя файла
                        $ext = match ($info['mime']) {
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/webp' => 'webp',
                            'image/gif'  => 'gif',
                            default      => 'jpg',
                        };
                        $filename = 'u' . (int)$me['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $targetPath = $uploadsDir . '/' . $filename;

                        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                            // Оптимизация: уменьшаем до 400x400
                            try {
                                pulse_resize_image($targetPath, 400, 400);
                            } catch (Throwable $e) {
                                // не критично — оставим оригинал
                            }

                            $newAvatarUrl = $uploadsUrl . '/' . $filename;
                            $fields['avatar_url'] = $newAvatarUrl;

                            // Удаляем старый файл, если это наш загруженный
                            if (!empty($me['avatar_url'])) {
                                pulse_delete_old_avatar($me['avatar_url'], $uploadsDir, $uploadsUrl);
                            }
                        } else {
                            $errors['avatar'] = 'Не удалось сохранить файл';
                        }
                    }
                }
            }
        }

        // ---- Сохранение ----
        if (!$errors) {
            try {
                User::update((int)$me['id'], $fields);
                flash('Профиль обновлён', 'success');
                redirect(url('profile.php?u=' . urlencode((string)$me['username'])));
            } catch (Throwable $e) {
                $errors['_general'] = 'Не удалось сохранить: ' . $e->getMessage();
            }
        }
    }

    // ============================================================
    // Смена пароля
    // ============================================================
    if ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $p1      = (string)($_POST['new_password'] ?? '');
        $p2      = (string)($_POST['new_password2'] ?? '');

        if (!password_verify($current, (string)$me['password_hash'])) {
            $errors['current_password'] = 'Текущий пароль неверный';
        }
        if (strlen($p1) < 6) {
            $errors['new_password'] = 'Минимум 6 символов';
        } elseif ($p1 !== $p2) {
            $errors['new_password2'] = 'Пароли не совпадают';
        }

        if (!array_intersect_key($errors, array_flip([
            'current_password', 'new_password', 'new_password2'
        ]))) {
            try {
                User::update((int)$me['id'], [
                    'password_hash' => password_hash($p1, PASSWORD_DEFAULT),
                ]);
                flash('Пароль изменён', 'success');
                redirect(url('profile-edit.php'));
            } catch (Throwable $e) {
                $errors['_general'] = 'Не удалось сменить пароль: ' . $e->getMessage();
            }
        }
    }

    // ============================================================
    // Удаление аватара
    // ============================================================
    if ($action === 'remove_avatar') {
        try {
            if (!empty($me['avatar_url'])) {
                pulse_delete_old_avatar($me['avatar_url'], $uploadsDir, $uploadsUrl);
            }
            User::update((int)$me['id'], ['avatar_url' => null]);
            flash('Фото удалено', 'success');
        } catch (Throwable $e) {
            flash('Не удалось удалить фото: ' . $e->getMessage(), 'error');
        }
        redirect(url('profile-edit.php'));
    }
}

// ---- Перезагружаем данные пользователя после сохранения ----
$me = User::findById((int)$me['id']);

$pageTitle = 'Настройки профиля';

// Подключаем heic2any — конвертация HEIC в JPEG на клиенте
$extraJs = array_merge($extraJs ?? [], [
    'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js',
]);

require __DIR__ . '/includes/header.php';

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

/**
 * Уменьшает изображение до maxWidth x maxHeight, сохраняя пропорции.
 */
function pulse_resize_image(string $path, int $maxW, int $maxH): void
{
    $info = @getimagesize($path);
    if (!$info) return;

    [$w, $h] = $info;
    $mime = $info['mime'];

    // Не увеличиваем
    if ($w <= $maxW && $h <= $maxH) return;

    $ratio = min($maxW / $w, $maxH / $h);
    $newW = (int)round($w * $ratio);
    $newH = (int)round($h * $ratio);

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        'image/gif'  => @imagecreatefromgif($path),
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
        'image/gif'  => imagegif($dst, $path),
        default      => null,
    };

    imagedestroy($src);
    imagedestroy($dst);
}

/**
 * Удаляет старый файл аватара, если он лежит в нашей папке uploads.
 */
function pulse_delete_old_avatar(string $avatarUrl, string $uploadsDir, string $uploadsUrl): void
{
    // Если внешний URL (например, Google) — не трогаем
    if (strpos($avatarUrl, $uploadsUrl) === false) return;

    $filename = basename(parse_url($avatarUrl, PHP_URL_PATH) ?? '');
    if ($filename === '') return;

    $path = $uploadsDir . '/' . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}
?>

<section class="form-page form-page--wide">
    <div class="form-card">
        <h1 class="form-card__title">Настройки профиля</h1>
        
        <div id="heic-progress" class="heic-progress" hidden>
    <span class="heic-progress__spinner"></span>
    <span class="heic-progress__text">Конвертирую HEIC-фото…</span>
</div>
        
        <p class="form-card__subtitle">
            Обновите личные данные, фото профиля и пароль.
        </p>

        <?php if (!empty($errors['_general'])): ?>
            <div class="alert alert--error"><?= e($errors['_general']) ?></div>
        <?php endif; ?>

        <!-- ============ БЛОК АВАТАРА ============ -->
        <div class="avatar-editor">
            <div class="avatar-editor__preview">
                <?php if (!empty($me['avatar_url'])): ?>
                    <img src="<?= e($me['avatar_url']) ?>" alt="">
                <?php else: ?>
                    <span class="avatar-editor__initial">
                        <?= e(mb_substr((string)$me['display_name'], 0, 1)) ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="avatar-editor__actions">
                <input type="file" name="avatar" form="profile-form" accept="image/*,.heic,.heif" id="avatar-input">

                <?php if (!empty($me['avatar_url'])): ?>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('Удалить фото профиля?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_avatar">
                        <button class="btn btn--ghost btn--sm">🗑 Удалить</button>
                    </form>
                <?php endif; ?>

                <span class="avatar-editor__hint muted">
    JPG, PNG, WEBP, HEIC · до 5 МБ · рекомендуется квадратное
</span>
            </div>
        </div>

        <!-- ============ ФОРМА ПРОФИЛЯ ============ -->
        <form method="post" enctype="multipart/form-data" id="profile-form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile">

            <div class="field">
                <label for="display_name">Отображаемое имя</label>
                <input type="text" id="display_name" name="display_name"
                       value="<?= e($me['display_name']) ?>" required maxlength="120">
                <?php if (!empty($errors['display_name'])): ?>
                    <span class="field__error"><?= e($errors['display_name']) ?></span>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="bio">О себе</label>
                <textarea id="bio" name="bio" rows="3" maxlength="500"><?= e($me['bio']) ?></textarea>
                <?php if (!empty($errors['bio'])): ?>
                    <span class="field__error"><?= e($errors['bio']) ?></span>
                <?php endif; ?>
                <span class="field__hint">До 500 символов</span>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="city">Город</label>
                    <input type="text" id="city" name="city" value="<?= e($me['city']) ?>" maxlength="120">
                </div>
                <div class="field">
                    <label for="country">Страна</label>
                    <input type="text" id="country" name="country" value="<?= e($me['country']) ?>" maxlength="120">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="gender">Пол</label>
                    <select id="gender" name="gender">
                        <option value="">— не указан —</option>
                        <option value="male"   <?= $me['gender'] === 'male'   ? 'selected' : '' ?>>Мужской</option>
                        <option value="female" <?= $me['gender'] === 'female' ? 'selected' : '' ?>>Женский</option>
                        <option value="other"  <?= $me['gender'] === 'other'  ? 'selected' : '' ?>>Другое</option>
                    </select>
                </div>
                <div class="field">
                    <label for="birth_date">Дата рождения</label>
                    <input type="date" id="birth_date" name="birth_date"
                           value="<?= e($me['birth_date']) ?>" max="<?= date('Y-m-d') ?>">
                    <?php if (!empty($errors['birth_date'])): ?>
                        <span class="field__error"><?= e($errors['birth_date']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="weight_kg">Вес, кг</label>
                    <input type="number" id="weight_kg" name="weight_kg" step="0.1" min="20" max="400"
                           value="<?= e($me['weight_kg']) ?>">
                    <?php if (!empty($errors['weight_kg'])): ?>
                        <span class="field__error"><?= e($errors['weight_kg']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="height_cm">Рост, см</label>
                    <input type="number" id="height_cm" name="height_cm" step="0.1" min="50" max="250"
                           value="<?= e($me['height_cm']) ?>">
                    <?php if (!empty($errors['height_cm'])): ?>
                        <span class="field__error"><?= e($errors['height_cm']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="field">
                <label for="units">Единицы измерения</label>
                <select id="units" name="units">
                    <option value="metric"   <?= $me['units'] === 'metric'   ? 'selected' : '' ?>>Метрические (км, кг)</option>
                    <option value="imperial" <?= $me['units'] === 'imperial' ? 'selected' : '' ?>>Имперские (мили, фунты)</option>
                </select>
            </div>

            <div class="field field--checkbox">
                <label>
                    <input type="checkbox" name="is_public" <?= (int)$me['is_public'] ? 'checked' : '' ?>>
                    Публичный профиль (виден всем)
                </label>
            </div>

            <button type="submit" class="btn btn--primary btn--large" style="width:100%">
                Сохранить изменения
            </button>
        </form>
    </div>

    <!-- ============ СМЕНА ПАРОЛЯ ============ -->
    <div class="form-card" style="margin-top:24px">
        <h2 class="form-card__title" style="font-size:20px">Смена пароля</h2>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">

            <div class="field">
                <label for="current_password">Текущий пароль</label>
                <input type="password" id="current_password" name="current_password" required>
                <?php if (!empty($errors['current_password'])): ?>
                    <span class="field__error"><?= e($errors['current_password']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="new_password">Новый пароль</label>
                    <input type="password" id="new_password" name="new_password" required>
                    <?php if (!empty($errors['new_password'])): ?>
                        <span class="field__error"><?= e($errors['new_password']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="new_password2">Повторите</label>
                    <input type="password" id="new_password2" name="new_password2" required>
                    <?php if (!empty($errors['new_password2'])): ?>
                        <span class="field__error"><?= e($errors['new_password2']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn btn--ghost btn--large" style="width:100%">
                Сменить пароль
            </button>
        </form>
    </div>

    <!-- ============ ОПАСНАЯ ЗОНА ============ -->
    <div class="form-card form-card--danger" style="margin-top:24px">
        <h2 class="form-card__title" style="font-size:20px">Опасная зона</h2>
        <p class="muted" style="margin-bottom:16px">
            Удаление аккаунта необратимо. Все активности, маршруты, сегменты и подписки будут удалены.
        </p>
        <form method="post" action="<?= e(url('profile-delete.php')) ?>"
              onsubmit="return confirm('Вы уверены? Это действие нельзя отменить.')">
            <?= csrf_field() ?>
            <button class="btn btn--danger">🗑 Удалить аккаунт</button>
        </form>
    </div>
</section>

<script>
window.addEventListener('load', function () {
    var input = document.getElementById('avatar-input');
    if (!input) return;

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;

        if (file.size > 5 * 1024 * 1024) {
            alert('Файл больше 5 МБ');
            input.value = '';
            return;
        }

        var preview = document.querySelector('.avatar-editor__preview');
        if (!preview) return;

        var name = (file.name || "").toLowerCase();
        var isHeic = name.endsWith(".heic") || name.endsWith(".heif");

        if (isHeic && typeof heic2any !== "undefined") {
            // Конвертируем HEIC для превью
            heic2any({ blob: file, toType: "image/jpeg", quality: 0.9 })
                .then(function (result) {
                    var blob = Array.isArray(result) ? result[0] : result;
                    var url = URL.createObjectURL(blob);
                    preview.innerHTML = '<img src="' + url + '" alt="Предпросмотр">';
                })
                .catch(function () {
                    // Не удалось — покажем заглушку
                    preview.innerHTML = '<span class="avatar-editor__initial">?</span>';
                });
            return;
        }

        // Обычный файл — через FileReader
        var reader = new FileReader();
        reader.onload = function (e) {
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Предпросмотр">';
        };
        reader.readAsDataURL(file);
    });
});
</script>

<script>
window.addEventListener('load', function () {
    "use strict";

    var input = document.getElementById("avatar-input");
    if (!input) return;

    var progress = document.getElementById("heic-progress");
    var form = document.getElementById("profile-form");
    if (!form) return;

    if (typeof heic2any === "undefined") {
        console.warn("heic2any не загрузился — HEIC не будет конвертироваться");
    }

    form.addEventListener("submit", function (e) {
        if (!input.files || !input.files.length) return;

        var file = input.files[0];
        var name = (file.name || "").toLowerCase();
        var isHeic = name.endsWith(".heic") || name.endsWith(".heif");

        if (!isHeic || typeof heic2any === "undefined") return;

        e.preventDefault();
        if (progress) progress.hidden = false;

        heic2any({
            blob: file,
            toType: "image/jpeg",
            quality: 0.9
        }).then(function (result) {
            var blob = Array.isArray(result) ? result[0] : result;
            var newName = file.name.replace(/\.(heic|heif)$/i, ".jpg");
            var newFile = new File([blob], newName, { type: "image/jpeg" });

            var dt = new DataTransfer();
            dt.items.add(newFile);
            input.files = dt.files;

            if (progress) progress.hidden = true;
            form.submit();
        }).catch(function (err) {
            console.error("Ошибка конвертации HEIC:", err);
            if (progress) progress.hidden = true;
            alert("Не удалось сконвертировать HEIC. Попробуйте другое фото.");
        });
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';

auth_start();
$me = current_user();
require_login();

$clubId = (int)($_GET['id'] ?? 0);
if ($clubId <= 0) { http_response_code(404); exit('Клуб не найден'); }

$club = Club::findById($clubId);
if (!$club) { http_response_code(404); exit('Клуб не найден'); }

$isOwner = (int)$club['owner_id'] === (int)$me['id'];
$canManage = Club::canManage($clubId, (int)$me['id']);

if (!$canManage) { http_response_code(403); exit('Недостаточно прав'); }

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    // -------- Сохранение основных полей --------
    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            $error = 'Название: 3–120 символов';
        } else {
            try {
                Club::update($clubId, [
                    'name'        => $name,
                    'description' => trim((string)($_POST['description'] ?? '')),
                    'city'        => trim((string)($_POST['city'] ?? '')),
                    'country'     => trim((string)($_POST['country'] ?? '')),
                    'sport_type'  => (string)($_POST['sport_type'] ?? 'mixed'),
                    'visibility'  => (string)($_POST['visibility'] ?? 'public'),
                    'join_policy' => (string)($_POST['join_policy'] ?? 'open'),
                    'website_url' => trim((string)($_POST['website_url'] ?? '')) ?: null,
                ]);
                $success = 'Изменения сохранены';
                $club = Club::findById($clubId); // обновляем
            } catch (Throwable $e) {
                $error = 'Ошибка: ' . $e->getMessage();
            }
        }
    }

    // -------- Аватар --------
    if ($action === 'upload_avatar' && !empty($_FILES['avatar'])) {
        try {
            $url = upload_club_image($_FILES['avatar'], 'avatars', $clubId);
            db()->prepare('UPDATE clubs SET avatar_url = ? WHERE id = ?')->execute([$url, $clubId]);
            $success = 'Аватар обновлён';
            $club = Club::findById($clubId);
        } catch (Throwable $e) {
            $error = 'Не удалось загрузить аватар: ' . $e->getMessage();
        }
    }

    // -------- Обложка --------
    if ($action === 'upload_cover' && !empty($_FILES['cover'])) {
        try {
            $url = upload_club_image($_FILES['cover'], 'covers', $clubId, 1600);
            db()->prepare('UPDATE clubs SET cover_url = ? WHERE id = ?')->execute([$url, $clubId]);
            $success = 'Обложка обновлена';
            $club = Club::findById($clubId);
        } catch (Throwable $e) {
            $error = 'Не удалось загрузить обложку: ' . $e->getMessage();
        }
    }

    // -------- Удаление аватара/обложки --------
    if ($action === 'remove_avatar') {
        db()->prepare('UPDATE clubs SET avatar_url = NULL WHERE id = ?')->execute([$clubId]);
        $success = 'Аватар удалён';
        $club = Club::findById($clubId);
    }
    if ($action === 'remove_cover') {
        db()->prepare('UPDATE clubs SET cover_url = NULL WHERE id = ?')->execute([$clubId]);
        $success = 'Обложка удалена';
        $club = Club::findById($clubId);
    }

    // -------- Удаление клуба --------
    if ($action === 'delete' && $isOwner) {
        $confirm = trim((string)($_POST['confirm_name'] ?? ''));
        if ($confirm !== $club['name']) {
            $error = 'Для удаления введите точное название клуба';
        } else {
            Club::delete($clubId, (int)$me['id']);
            flash('Клуб удалён', 'success');
            redirect(url('clubs.php'));
        }
    }
}

/**
 * Загрузка картинки клуба: валидация MIME, ресайз, сохранение в uploads/clubs/{type}/.
 */
function upload_club_image(array $file, string $type, int $clubId, int $maxWidth = 800): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Ошибка загрузки: код ' . $file['error']);
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('Файл больше 8 МБ');
    }

    $info = getimagesize($file['tmp_name']);
    if (!$info) throw new RuntimeException('Не картинка');

    $mime = $info['mime'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Разрешены только JPG, PNG, WebP');
    }
    $ext = $allowed[$mime];

    // Читаем и ресайзим
    $src = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => imagecreatefrompng($file['tmp_name']),
        'image/webp' => imagecreatefromwebp($file['tmp_name']),
    };
    if (!$src) throw new RuntimeException('Не удалось открыть картинку');

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w > $maxWidth) {
        $nh = (int)round($h * $maxWidth / $w);
        $dst = imagecreatetruecolor($maxWidth, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    $dir = __DIR__ . '/assets/uploads/clubs/' . $type;
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $filename = 'club' . $clubId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $path = $dir . '/' . $filename;

    match ($mime) {
        'image/jpeg' => imagejpeg($src, $path, 88),
        'image/png'  => imagepng($src, $path, 8),
        'image/webp' => imagewebp($src, $path, 88),
    };
    imagedestroy($src);

    return url('assets/uploads/clubs/' . $type . '/' . $filename);
}

$pageTitle = 'Настройки клуба';
$extraCss = [url('assets/css/clubs.css')];
require __DIR__ . '/includes/header.php';
?>

<section class="form-page form-page--wide">
    <div class="form-card">
        <h1 class="form-card__title">Настройки клуба</h1>
        <p class="form-card__subtitle">
            <a href="<?= e(url('club.php?id=' . $clubId)) ?>">← вернуться к клубу</a>
        </p>

        <?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>

        <!-- ============ Обложка ============ -->
        <div class="club-edit-media">
            <div class="club-edit-cover" style="background-image:url('<?= e((string)$club['cover_url']) ?>')">
                <?php if (empty($club['cover_url'])): ?>
                    <span class="muted">Обложка не установлена</span>
                <?php endif; ?>
            </div>
            <div class="club-edit-cover-actions">
                <form method="post" enctype="multipart/form-data" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_cover">
                    <label class="btn btn--ghost btn--sm">
                        📷 Заменить обложку
                        <input type="file" name="cover" accept="image/*" hidden onchange="this.form.submit()">
                    </label>
                </form>
                <?php if (!empty($club['cover_url'])): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Удалить обложку?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_cover">
                        <button class="btn btn--ghost btn--sm">Удалить</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ Аватар ============ -->
        <div class="club-edit-avatar-row">
            <div class="club-edit-avatar">
                <?php if (!empty($club['avatar_url'])): ?>
                    <img src="<?= e($club['avatar_url']) ?>" alt="">
                <?php else: ?>
                    <?= e(mb_substr((string)$club['name'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div>
                <form method="post" enctype="multipart/form-data" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_avatar">
                    <label class="btn btn--ghost btn--sm">
                        📷 Заменить аватар
                        <input type="file" name="avatar" accept="image/*" hidden onchange="this.form.submit()">
                    </label>
                </form>
                <?php if (!empty($club['avatar_url'])): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Удалить аватар?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_avatar">
                        <button class="btn btn--ghost btn--sm">Удалить</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <hr style="margin:24px 0;border:none;border-top:1px solid var(--border)">

        <!-- ============ Основная форма ============ -->
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="field">
                <label>Название *</label>
                <input type="text" name="name" value="<?= e($club['name']) ?>" required maxlength="120">
            </div>

            <div class="field">
                <label>Описание</label>
                <textarea name="description" rows="5" maxlength="2000"><?= e((string)$club['description']) ?></textarea>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Город</label>
                    <input type="text" name="city" value="<?= e((string)$club['city']) ?>" maxlength="120">
                </div>
                <div class="field">
                    <label>Страна</label>
                    <input type="text" name="country" value="<?= e((string)$club['country']) ?>" maxlength="120">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Вид спорта</label>
                    <select name="sport_type">
                        <?php foreach ([
                            'mixed'=>'Смешанный','run'=>'Бег','ride'=>'Велосипед',
                            'swim'=>'Плавание','ski'=>'Лыжи','walk'=>'Ходьба','hike'=>'Хайкинг'
                        ] as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= $club['sport_type'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Видимость</label>
                    <select name="visibility">
                        <option value="public"  <?= $club['visibility'] === 'public' ? 'selected' : '' ?>>Публичный</option>
                        <option value="private" <?= $club['visibility'] === 'private' ? 'selected' : '' ?>>Приватный</option>
                        <option value="hidden"  <?= $club['visibility'] === 'hidden' ? 'selected' : '' ?>>Скрытый</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Как вступать</label>
                    <select name="join_policy">
                        <option value="open"    <?= $club['join_policy'] === 'open' ? 'selected' : '' ?>>Свободно</option>
                        <option value="request" <?= $club['join_policy'] === 'request' ? 'selected' : '' ?>>По заявке</option>
                        <option value="invite"  <?= $club['join_policy'] === 'invite' ? 'selected' : '' ?>>Только по приглашению</option>
                    </select>
                </div>
                <div class="field">
                    <label>Сайт</label>
                    <input type="url" name="website_url" value="<?= e((string)$club['website_url']) ?>" placeholder="https://...">
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary">Сохранить</button>
                <a class="btn btn--ghost" href="<?= e(url('club.php?id=' . $clubId)) ?>">Отмена</a>
            </div>
        </form>

        <?php if ($isOwner): ?>
            <div class="danger-zone">
                <div class="danger-zone__title">Удаление клуба</div>
                <p>Это действие необратимо. Все посты, события и членство будут удалены.</p>
                <form method="post" onsubmit="return confirm('Точно удалить клуб? Это нельзя отменить.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <div class="field">
                        <label>Введите название клуба для подтверждения:</label>
                        <input type="text" name="confirm_name" placeholder="<?= e($club['name']) ?>" required>
                    </div>
                    <button class="btn btn--danger">Удалить клуб навсегда</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
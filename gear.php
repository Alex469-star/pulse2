<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Gear.php';

auth_start();
$me = require_login();

$errors = [];
$editItem = null;

// ---- Пути для фото ----
$uploadsDir = __DIR__ . '/assets/uploads/gear';
$uploadsUrl = url('assets/uploads/gear');
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

// ============================================================
// РЕЖИМ РЕДАКТИРОВАНИЯ
// ============================================================
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editItem = Gear::findById($editId);
    if (!$editItem || (int)$editItem['user_id'] !== (int)$me['id']) {
        flash('Инвентарь не найден', 'error');
        redirect(url('gear.php'));
    }
}

// ============================================================
// POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---------- ОТЛАДКА (временно) ----------
    // Пишем в storage/gear-debug.log всё, что приходит в POST.
    // Уберём, когда разберёмся с проблемой.
    try {
        $logDir = __DIR__ . '/storage';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        file_put_contents(
            $logDir . '/gear-debug.log',
            date('c') . ' ' . json_encode([
                'action'   => $_POST['action'] ?? null,
                'gear_id'  => $_POST['gear_id'] ?? null,
                'has_csrf' => isset($_POST['csrf']),
                'csrf_len' => isset($_POST['csrf']) ? strlen((string)$_POST['csrf']) : 0,
                'uri'      => $_SERVER['REQUEST_URI'] ?? '',
            ], JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
    } catch (Throwable $e) {
        // молча
    }

    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    // ---------- Создание ----------
    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $type = (string)($_POST['type'] ?? 'other');

        if ($name === '') {
            $errors['name'] = 'Введите название';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'Максимум 120 символов';
        }

        $allowedTypes = ['bike', 'shoes', 'skis', 'other'];
        if (!in_array($type, $allowedTypes, true)) $type = 'other';

        $photoUrl = null;
        if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            $up = gear_handle_upload($_FILES['photo'], $uploadsDir, $uploadsUrl, (int)$me['id']);
            if ($up['error']) {
                $errors['photo'] = $up['error'];
            } else {
                $photoUrl = $up['url'];
            }
        }

        if (!$errors) {
            try {
                Gear::create((int)$me['id'], [
                    'type'          => $type,
                    'name'          => $name,
                    'brand'         => trim((string)($_POST['brand'] ?? '')) ?: null,
                    'model'         => trim((string)($_POST['model'] ?? '')) ?: null,
                    'purchase_date' => !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null,
                    'notes'         => trim((string)($_POST['notes'] ?? '')) ?: null,
                    'photo_url'     => $photoUrl,
                ]);
                flash('Инвентарь добавлен', 'success');
                redirect(url('gear.php'));
            } catch (Throwable $e) {
                $errors['_general'] = 'Не удалось сохранить: ' . $e->getMessage();
            }
        }
    }

    // ---------- Обновление ----------
    if ($action === 'update') {
        $gearId = (int)($_POST['gear_id'] ?? 0);
        $item = Gear::findById($gearId);

        if (!$item || (int)$item['user_id'] !== (int)$me['id']) {
            flash('Инвентарь не найден', 'error');
            redirect(url('gear.php'));
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $type = (string)($_POST['type'] ?? 'other');

        if ($name === '') {
            $errors['name'] = 'Введите название';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'Максимум 120 символов';
        }

        $allowedTypes = ['bike', 'shoes', 'skis', 'other'];
        if (!in_array($type, $allowedTypes, true)) $type = 'other';

        $photoUrl = $item['photo_url'];
        if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            $up = gear_handle_upload($_FILES['photo'], $uploadsDir, $uploadsUrl, (int)$me['id']);
            if ($up['error']) {
                $errors['photo'] = $up['error'];
            } else {
                if (!empty($item['photo_url'])) {
                    gear_delete_file($item['photo_url'], $uploadsDir, $uploadsUrl);
                }
                $photoUrl = $up['url'];
            }
        }

        if (!empty($_POST['remove_photo']) && !$errors) {
            if (!empty($item['photo_url'])) {
                gear_delete_file($item['photo_url'], $uploadsDir, $uploadsUrl);
            }
            $photoUrl = null;
        }

        if (!$errors) {
            try {
                $fields = [
                    'type'          => $type,
                    'name'          => $name,
                    'brand'         => trim((string)($_POST['brand'] ?? '')) ?: null,
                    'model'         => trim((string)($_POST['model'] ?? '')) ?: null,
                    'purchase_date' => !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null,
                    'notes'         => trim((string)($_POST['notes'] ?? '')) ?: null,
                    'photo_url'     => $photoUrl,
                ];

                if (isset($_POST['is_retired'])) {
                    $fields['is_retired'] = $_POST['is_retired'] ? 1 : 0;
                }

                Gear::update($gearId, (int)$me['id'], $fields);
                flash('Изменения сохранены', 'success');
                redirect(url('gear.php'));
            } catch (Throwable $e) {
                $errors['_general'] = 'Не удалось сохранить: ' . $e->getMessage();
            }
        }

        $editItem = $item;
        $editId = $gearId;
    }

    // ---------- Обновить фото (из карточки) ----------
    if ($action === 'update_photo') {
        $gearId = (int)($_POST['gear_id'] ?? 0);
        $item = Gear::findById($gearId);

        if ($item && (int)$item['user_id'] === (int)$me['id']) {
            if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
                $up = gear_handle_upload($_FILES['photo'], $uploadsDir, $uploadsUrl, (int)$me['id']);
                if ($up['error']) {
                    flash($up['error'], 'error');
                } else {
                    if (!empty($item['photo_url'])) {
                        gear_delete_file($item['photo_url'], $uploadsDir, $uploadsUrl);
                    }
                    Gear::update($gearId, (int)$me['id'], ['photo_url' => $up['url']]);
                    flash('Фото обновлено', 'success');
                }
            } else {
                flash('Выберите файл', 'error');
            }
        }
        redirect(url('gear.php'));
    }

    // ---------- Удалить фото ----------
    if ($action === 'remove_photo') {
        $gearId = (int)($_POST['gear_id'] ?? 0);
        $item = Gear::findById($gearId);
        if ($item && (int)$item['user_id'] === (int)$me['id']) {
            if (!empty($item['photo_url'])) {
                gear_delete_file($item['photo_url'], $uploadsDir, $uploadsUrl);
            }
            Gear::update($gearId, (int)$me['id'], ['photo_url' => null]);
            flash('Фото удалено', 'success');
        }
        redirect(url('gear.php'));
    }

    // ---------- Списать / вернуть ----------
    if ($action === 'toggle_retired') {
        $gearId = (int)($_POST['gear_id'] ?? 0);
        if ($gearId > 0) {
            try {
                Gear::toggleRetired($gearId, (int)$me['id']);
                flash('Статус изменён', 'success');
            } catch (Throwable $e) {
                flash('Ошибка: ' . $e->getMessage(), 'error');
            }
        }
        redirect(url('gear.php'));
    }

    // ---------- Пересчитать пробег ----------
    if ($action === 'recalc_distance') {
        $gearId = (int)($_POST['gear_id'] ?? 0);
        if ($gearId > 0) {
            try {
                $total = Gear::recalcDistance($gearId, (int)$me['id']);
                $item = Gear::findById($gearId);
                $label = $item ? $item['name'] : ('#' . $gearId);
                flash('Пробег «' . $label . '»: ' . format_distance($total), 'success');
            } catch (Throwable $e) {
                flash('Ошибка пересчёта: ' . $e->getMessage(), 'error');
            }
        }
        redirect(url('gear.php'));
    }

    // ---------- Удалить ----------
    if ($action === 'delete') {
        $gearId = (int)($_POST['gear_id'] ?? 0);
        $item = Gear::findById($gearId);
        if ($item && (int)$item['user_id'] === (int)$me['id']) {
            if (!empty($item['photo_url'])) {
                gear_delete_file($item['photo_url'], $uploadsDir, $uploadsUrl);
            }
            Gear::delete($gearId, (int)$me['id']);
            flash('Инвентарь удалён', 'success');
        }
        redirect(url('gear.php'));
    }
}

// ============================================================
// ЗАГРУЗКА СПИСКА
// ============================================================
$gearList = [];
$stats = ['total' => 0, 'active' => 0, 'retired' => 0, 'distance_m' => 0, 'activities' => 0];

try {
    $gearList = Gear::allForUserWithStats((int)$me['id']);
    foreach ($gearList as $g) {
        $stats['total']++;
        if ((int)$g['is_retired']) $stats['retired']++; else $stats['active']++;
        $stats['distance_m'] += (float)($g['distance_m'] ?? 0);
        $stats['activities'] += (int)($g['activities_count'] ?? 0);
    }
} catch (Throwable $e) {
    $errors['_general'] = 'Не удалось загрузить инвентарь: ' . $e->getMessage();
}

$pageTitle = $editItem ? 'Редактировать инвентарь' : 'Инвентарь';

// heic2any — конвертация HEIC на клиенте
$extraJs = array_merge($extraJs ?? [], [
    'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js',
]);

require __DIR__ . '/includes/header.php';

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

function gear_handle_upload(array $file, string $dir, string $urlBase, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['url' => null, 'error' => 'Ошибка загрузки (код ' . (int)$file['error'] . ')'];
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['url' => null, 'error' => 'Файл больше 5 МБ'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return ['url' => null, 'error' => 'Файл не является изображением'];

    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($info['mime'], $allowedMime, true)) {
        return ['url' => null, 'error' => 'Поддерживаются JPG, PNG, WEBP'];
    }

    $ext = match ($info['mime']) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $filename = 'g' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['url' => null, 'error' => 'Не удалось сохранить файл'];
    }

    gear_resize_image($target, 1200, 800);
    return ['url' => $urlBase . '/' . $filename, 'error' => null];
}

function gear_resize_image(string $path, int $maxW, int $maxH): void
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

function gear_delete_file(string $url, string $dir, string $urlBase): void
{
    if (strpos($url, $urlBase) === false) return;
    $filename = basename(parse_url($url, PHP_URL_PATH) ?? '');
    if ($filename === '') return;
    $path = $dir . '/' . $filename;
    if (is_file($path)) @unlink($path);
}

function gear_icon(string $type): string
{
    return match ($type) {
        'bike' => '🚴', 'shoes' => '👟', 'skis' => '⛷️', default => '🎒',
    };
}
function gear_label(string $type): string
{
    return match ($type) {
        'bike' => 'Велосипед', 'shoes' => 'Кроссовки', 'skis' => 'Лыжи', default => 'Другое',
    };
}
function gear_wear_percent(float $distanceM, string $type): int
{
    $threshold = match ($type) {
        'shoes' => 800_000,
        'bike'  => 5_000_000,
        'skis'  => 1_500_000,
        default => 2_000_000,
    };
    if ($threshold <= 0) return 0;
    return max(0, min(100, (int)round(($distanceM / $threshold) * 100)));
}
function gear_age(?string $date): ?string
{
    if (!$date) return null;
    $ts = strtotime($date);
    if (!$ts) return null;
    $days = (int)floor((time() - $ts) / 86400);
    if ($days < 30) return $days . ' дн';
    $months = (int)floor($days / 30);
    if ($months < 12) return $months . ' мес';
    $years = (int)floor($days / 365);
    $remMonths = (int)floor(($days % 365) / 30);
    return $years . ' г' . ($remMonths > 0 ? ' ' . $remMonths . ' мес' : '');
}
?>

<section class="gear-page">
    <header class="gear-page__head">
        <div>
            <h1 class="section-title" style="text-align:left;margin-bottom:4px">
                <?= $editItem ? 'Редактировать инвентарь' : 'Инвентарь' ?>
            </h1>
            <p class="muted">
                <?= $editItem
                    ? 'Измените данные и сохраните'
                    : 'Учёт велосипедов, кроссовок, лыж и другого снаряжения' ?>
            </p>
        </div>
        <?php if (!$editItem): ?>
            <button type="button" class="btn btn--primary" id="toggle-form">+ Добавить</button>
        <?php else: ?>
            <a href="<?= e(url('gear.php')) ?>" class="btn btn--ghost">← К списку</a>
        <?php endif; ?>
    </header>

    <?php if (!empty($errors['_general'])): ?>
        <div class="alert alert--error"><?= e($errors['_general']) ?></div>
    <?php endif; ?>

    <?php if ($editItem): ?>
        <!-- ============================================== -->
        <!-- ФОРМА РЕДАКТИРОВАНИЯ                             -->
        <!-- ============================================== -->
        <div class="form-card gear-form">
            <form method="post" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="gear_id" value="<?= (int)$editItem['id'] ?>">

                <?php if (!empty($editItem['photo_url'])): ?>
                    <div class="gear-edit-photo">
                        <img src="<?= e($editItem['photo_url']) ?>" alt="">
                        <label class="gear-edit-photo__remove">
                            <input type="checkbox" name="remove_photo" value="1">
                            Удалить фото
                        </label>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="field">
                        <label for="name">Название</label>
                        <input type="text" id="name" name="name" required maxlength="120"
                               value="<?= e($editItem['name']) ?>">
                        <?php if (!empty($errors['name'])): ?>
                            <span class="field__error"><?= e($errors['name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="type">Тип</label>
                        <select id="type" name="type">
                            <option value="bike"  <?= $editItem['type'] === 'bike'  ? 'selected' : '' ?>>🚴 Велосипед</option>
                            <option value="shoes" <?= $editItem['type'] === 'shoes' ? 'selected' : '' ?>>👟 Кроссовки</option>
                            <option value="skis"  <?= $editItem['type'] === 'skis'  ? 'selected' : '' ?>>⛷️ Лыжи</option>
                            <option value="other" <?= $editItem['type'] === 'other' ? 'selected' : '' ?>>🎒 Другое</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="brand">Бренд</label>
                        <input type="text" id="brand" name="brand" maxlength="120"
                               value="<?= e($editItem['brand'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="model">Модель</label>
                        <input type="text" id="model" name="model" maxlength="120"
                               value="<?= e($editItem['model'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="purchase_date">Дата покупки</label>
                        <input type="date" id="purchase_date" name="purchase_date"
                               max="<?= date('Y-m-d') ?>"
                               value="<?= e($editItem['purchase_date'] ?? '') ?>">
                        <span class="field__hint">Пробег считается только с этой даты</span>
                    </div>
                    <div class="field field--checkbox" style="align-self:end">
                        <label>
                            <input type="checkbox" name="is_retired" value="1"
                                   <?= (int)$editItem['is_retired'] ? 'checked' : '' ?>>
                            Списано (не в работе)
                        </label>
                    </div>
                </div>

                <div class="field">
                    <label for="notes">Заметки</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="500"><?= e($editItem['notes'] ?? '') ?></textarea>
                </div>

                <div class="field">
                    <label for="photo">Заменить фото</label>
                    <input type="file" id="photo" name="photo" accept="image/*,.heic,.heif">
                    <?php if (!empty($errors['photo'])): ?>
                        <span class="field__error"><?= e($errors['photo']) ?></span>
                    <?php endif; ?>
                    <span class="field__hint">JPG, PNG, WEBP · до 5 МБ. Оставьте пустым, чтобы не менять.</span>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary btn--large">Сохранить</button>
                    <a href="<?= e(url('gear.php')) ?>" class="btn btn--ghost btn--large">Отмена</a>
                </div>
            </form>

            <div class="danger-zone">
                <h3 class="danger-zone__title">Опасная зона</h3>
                <p class="muted">Удаление необратимо. Привязанные активности потеряют привязку к этому инвентарю.</p>
                <form method="post" onsubmit="return confirm('Удалить <?= e($editItem['name']) ?>? Это необратимо.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="gear_id" value="<?= (int)$editItem['id'] ?>">
                    <button class="btn btn--danger">🗑 Удалить инвентарь</button>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- ============================================== -->
        <!-- СПИСОК + ФОРМА ДОБАВЛЕНИЯ                        -->
        <!-- ============================================== -->

        <?php if ($stats['total'] > 0): ?>
            <div class="gear-summary">
                <div class="gear-summary__cell">
                    <span class="gear-summary__value"><?= (int)$stats['total'] ?></span>
                    <span class="gear-summary__label">всего</span>
                </div>
                <div class="gear-summary__cell">
                    <span class="gear-summary__value"><?= (int)$stats['active'] ?></span>
                    <span class="gear-summary__label">в работе</span>
                </div>
                <div class="gear-summary__cell">
                    <span class="gear-summary__value"><?= (int)$stats['retired'] ?></span>
                    <span class="gear-summary__label">списано</span>
                </div>
                <div class="gear-summary__cell">
                    <span class="gear-summary__value"><?= e(format_distance($stats['distance_m'])) ?></span>
                    <span class="gear-summary__label">пробег</span>
                </div>
                <div class="gear-summary__cell">
                    <span class="gear-summary__value"><?= (int)$stats['activities'] ?></span>
                    <span class="gear-summary__label">активностей</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-card gear-form" id="gear-form" style="display:none">
            <h2 class="form-card__title" style="font-size:18px">Новый инвентарь</h2>

            <form method="post" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">

                <div class="form-row">
                    <div class="field">
                        <label for="name">Название</label>
                        <input type="text" id="name" name="name" required maxlength="120" placeholder="Trek Domane">
                        <?php if (!empty($errors['name'])): ?>
                            <span class="field__error"><?= e($errors['name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="type">Тип</label>
                        <select id="type" name="type">
                            <option value="bike">🚴 Велосипед</option>
                            <option value="shoes">👟 Кроссовки</option>
                            <option value="skis">⛷️ Лыжи</option>
                            <option value="other">🎒 Другое</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="brand">Бренд</label>
                        <input type="text" id="brand" name="brand" maxlength="120" placeholder="Trek">
                    </div>
                    <div class="field">
                        <label for="model">Модель</label>
                        <input type="text" id="model" name="model" maxlength="120" placeholder="Domane SL 5">
                    </div>
                </div>

                <div class="field">
                    <label for="purchase_date">Дата покупки</label>
                    <input type="date" id="purchase_date" name="purchase_date" max="<?= date('Y-m-d') ?>">
                    <span class="field__hint">Пробег считается только с этой даты</span>
                </div>

                <div class="field">
                    <label for="notes">Заметки</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="500" placeholder="Например: обслужен 1 мая"></textarea>
                </div>

                <div class="field">
                    <label for="photo">Фото</label>
                    <input type="file" id="photo" name="photo" accept="image/*,.heic,.heif">
                    <?php if (!empty($errors['photo'])): ?>
                        <span class="field__error"><?= e($errors['photo']) ?></span>
                    <?php endif; ?>
                    <span class="field__hint">JPG, PNG, WEBP, HEIC · до 5 МБ</span>
                </div>

                <div id="heic-progress" class="heic-progress" hidden>
                    <span class="heic-progress__spinner"></span>
                    <span class="heic-progress__text">Конвертирую HEIC-фото…</span>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Добавить</button>
                    <button type="button" class="btn btn--ghost" id="cancel-form">Отмена</button>
                </div>
            </form>
        </div>

        <?php if (!$gearList): ?>
            <div class="empty">
                <p>Пока нет инвентаря.</p>
                <p class="muted" style="margin-top:8px">
                    Добавьте велосипед, кроссовки или лыжи, чтобы вести учёт пробега и износа.
                </p>
                <button type="button" class="btn btn--primary" onclick="document.getElementById('toggle-form').click()">
                    + Добавить инвентарь
                </button>
            </div>
        <?php else: ?>
            <div class="gear-grid">
                <?php foreach ($gearList as $g): ?>
                    <?php
                        $wear = gear_wear_percent((float)($g['distance_m'] ?? 0), (string)$g['type']);
                        $wearClass = $wear >= 90 ? 'is-danger' : ($wear >= 70 ? 'is-warning' : '');
                        $age = gear_age($g['purchase_date'] ?? null);
                        $isRetired = (int)$g['is_retired'] === 1;
                        $hasPurchaseDate = !empty($g['purchase_date']);
                    ?>
                    <article class="gear-card <?= $isRetired ? 'gear-card--retired' : '' ?>">
                        <div class="gear-card__photo">
                            <?php if (!empty($g['photo_url'])): ?>
                                <img src="<?= e($g['photo_url']) ?>" alt="<?= e($g['name']) ?>">
                            <?php else: ?>
                                <span class="gear-card__photo-placeholder"><?= e(gear_icon((string)$g['type'])) ?></span>
                            <?php endif; ?>

                            <?php if ($isRetired): ?>
                                <span class="gear-card__retired-badge">Списано</span>
                            <?php endif; ?>

                            <form method="post" enctype="multipart/form-data" class="gear-card__photo-upload">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_photo">
                                <input type="hidden" name="gear_id" value="<?= (int)$g['id'] ?>">
                                <label class="gear-card__photo-label" title="<?= !empty($g['photo_url']) ? 'Заменить фото' : 'Загрузить фото' ?>">
                                    📷
                                    <input type="file" name="photo" accept="image/*,.heic,.heif" onchange="this.form.submit()">
                                </label>
                            </form>
                        </div>

                        <div class="gear-card__body">
                            <div class="gear-card__head">
                                <div>
                                    <div class="gear-card__type">
                                        <?= e(gear_icon((string)$g['type'])) ?> <?= e(gear_label((string)$g['type'])) ?>
                                    </div>
                                    <h3 class="gear-card__name"><?= e($g['name']) ?></h3>
                                    <?php if (!empty($g['brand']) || !empty($g['model'])): ?>
                                        <p class="gear-card__brand muted">
                                            <?= e(trim(($g['brand'] ?? '') . ' ' . ($g['model'] ?? ''))) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="gear-card__metrics">
                                <div class="gear-metric">
                                    <span class="gear-metric__value"><?= e(format_distance((float)($g['distance_m'] ?? 0))) ?></span>
                                    <span class="gear-metric__label">
                                        пробег<?= $hasPurchaseDate ? ' с ' . e(date('d.m.Y', strtotime((string)$g['purchase_date']))) : '' ?>
                                    </span>
                                </div>
                                <div class="gear-metric">
                                    <span class="gear-metric__value"><?= (int)($g['activities_count'] ?? 0) ?></span>
                                    <span class="gear-metric__label">активностей</span>
                                </div>
                                <?php if ($age): ?>
                                    <div class="gear-metric">
                                        <span class="gear-metric__value"><?= e($age) ?></span>
                                        <span class="gear-metric__label">возраст</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- <div class="gear-wear">
                                <div class="gear-wear__head">
                                    <span class="muted">Износ</span>
                                    <span class="gear-wear__percent <?= $wearClass ?>"><?= $wear ?>%</span>
                                </div>
                                <div class="gear-wear__bar">
                                    <div class="gear-wear__fill <?= $wearClass ?>" style="width: <?= $wear ?>%"></div>
                                </div>
                            </div> -->

                            <?php if (!empty($g['notes'])): ?>
                                <div class="gear-card__notes"><?= nl2br(e($g['notes'])) ?></div>
                            <?php endif; ?>

                            <div class="gear-card__actions">
                                <a href="<?= e(url('gear.php?edit=' . (int)$g['id'])) ?>" class="action">
                                    ✏️ Редактировать
                                </a>

                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="recalc_distance">
                                    <input type="hidden" name="gear_id" value="<?= (int)$g['id'] ?>">
                                    <button class="action" title="Пересчитать пробег с даты покупки">
                                        🔄 Пересчитать
                                    </button>
                                </form>

                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_retired">
                                    <input type="hidden" name="gear_id" value="<?= (int)$g['id'] ?>">
                                    <button class="action">
                                        <?= $isRetired ? '↩ Вернуть' : '⏸ Списать' ?>
                                    </button>
                                </form>

                                <?php if (!empty($g['photo_url'])): ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Удалить фото?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_photo">
                                        <input type="hidden" name="gear_id" value="<?= (int)$g['id'] ?>">
                                        <button class="action">🗑 Фото</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('Удалить <?= e($g['name']) ?>? Активности потеряют привязку.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="gear_id" value="<?= (int)$g['id'] ?>">
                                    <button class="action action--danger">🗑 Удалить</button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<script>
(function () {
    var form = document.getElementById('gear-form');
    var toggle = document.getElementById('toggle-form');
    var cancel = document.getElementById('cancel-form');
    if (!form || !toggle) return;

    toggle.addEventListener('click', function () {
        if (form.style.display === 'none' || form.style.display === '') {
            form.style.display = 'block';
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            toggle.textContent = '✕ Закрыть';
        } else {
            form.style.display = 'none';
            toggle.textContent = '+ Добавить';
        }
    });

    if (cancel) {
        cancel.addEventListener('click', function () {
            form.style.display = 'none';
            toggle.textContent = '+ Добавить';
        });
    }
})();
</script>

<script>
window.addEventListener('load', function () {
    "use strict";

    if (typeof heic2any === "undefined") {
        console.warn("heic2any не загрузился — HEIC не будет конвертироваться");
    }

    var progress = document.getElementById("heic-progress");
    function showProgress() { if (progress) progress.hidden = false; }
    function hideProgress() { if (progress) progress.hidden = true; }

    function convertFile(file) {
        var name = (file.name || "").toLowerCase();
        var isHeic = name.endsWith(".heic") || name.endsWith(".heif");
        if (!isHeic || typeof heic2any === "undefined") {
            return Promise.resolve(file);
        }
        return heic2any({ blob: file, toType: "image/jpeg", quality: 0.85 })
            .then(function (result) {
                var blob = Array.isArray(result) ? result[0] : result;
                var newName = file.name.replace(/\.(heic|heif)$/i, ".jpg");
                return new File([blob], newName, { type: "image/jpeg" });
            })
            .catch(function (err) {
                console.error("HEIC convert error:", err);
                return file;
            });
    }

    document.querySelectorAll("form").forEach(function (form) {
        var input = form.querySelector('input[type="file"][name="photo"]');
        if (!input) return;
        if (input.hasAttribute("onchange")) return;

        form.addEventListener("submit", function (e) {
            if (!input.files || !input.files.length) return;

            var hasHeic = Array.from(input.files).some(function (f) {
                var n = (f.name || "").toLowerCase();
                return n.endsWith(".heic") || n.endsWith(".heif");
            });
            if (!hasHeic) return;

            e.preventDefault();
            showProgress();

            var converted = [];
            var chain = Promise.resolve();
            Array.from(input.files).forEach(function (file) {
                chain = chain.then(function () {
                    return convertFile(file).then(function (f) { converted.push(f); });
                });
            });

            chain.then(function () {
                var dt = new DataTransfer();
                converted.forEach(function (f) { dt.items.add(f); });
                input.files = dt.files;
                hideProgress();
                form.submit();
            });
        });
    });

    document.querySelectorAll('input[type="file"][name="photo"][onchange]').forEach(function (input) {
        input.removeAttribute("onchange");

        input.addEventListener("change", function () {
            if (!input.files || !input.files.length) return;
            var form = input.closest("form");
            if (!form) return;

            var hasHeic = Array.from(input.files).some(function (f) {
                var n = (f.name || "").toLowerCase();
                return n.endsWith(".heic") || n.endsWith(".heif");
            });

            if (!hasHeic) {
                form.submit();
                return;
            }

            showProgress();
            var converted = [];
            var chain = Promise.resolve();
            Array.from(input.files).forEach(function (file) {
                chain = chain.then(function () {
                    return convertFile(file).then(function (f) { converted.push(f); });
                });
            });

            chain.then(function () {
                var dt = new DataTransfer();
                converted.forEach(function (f) { dt.items.add(f); });
                input.files = dt.files;
                hideProgress();
                form.submit();
            });
        });
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';
require_once __DIR__ . '/models/ClubEvent.php';
require_once __DIR__ . '/includes/ImageUploader.php';

auth_start();
$me = current_user();
require_login();

$clubId = (int)($_GET['club_id'] ?? 0);
$club = Club::findById($clubId);
if (!$club) { http_response_code(404); exit('Клуб не найден'); }

if (!Club::canManage($clubId, (int)$me['id'])) {
    http_response_code(403); exit('Недостаточно прав');
}

$error = null;
$old = [
    'title' => '',
    'description' => '',
    'type' => 'training',
    'visibility' => 'members',
    'starts_at' => date('Y-m-d\TH:i', time() + 86400),
    'ends_at' => '',
    'location' => '',
    'city' => $club['city'] ?? '',
    'lat' => '',
    'lng' => '',
    'max_attendees' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old = array_merge($old, array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST));

    $title = trim((string)($_POST['title'] ?? ''));
    $startsAt = trim((string)($_POST['starts_at'] ?? ''));
    $endsAt = trim((string)($_POST['ends_at'] ?? ''));

    if (mb_strlen($title) < 3) {
        $error = 'Название минимум 3 символа';
    } elseif ($startsAt === '' || strtotime($startsAt) === false) {
        $error = 'Укажите корректную дату и время начала';
    } elseif ($endsAt !== '' && strtotime($endsAt) !== false && strtotime($endsAt) < strtotime($startsAt)) {
        $error = 'Окончание не может быть раньше начала';
    } else {
        // ---- Обложка ----
        $coverUrl = null;
        if (!empty($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
            try {
                $coverUrl = ImageUploader::save(
                    $_FILES['cover'],
                    'clubs/events',
                    1600, 1200, 10 * 1024 * 1024
                );
            } catch (Throwable $ex) {
                $error = 'Обложка: ' . $ex->getMessage();
            }
        }

        if ($error === null) {
            try {
                $eventId = ClubEvent::create($clubId, (int)$me['id'], [
                    'title'         => $title,
                    'description'   => trim((string)($_POST['description'] ?? '')),
                    'cover_url'     => $coverUrl,
                    'type'          => (string)($_POST['type'] ?? 'training'),
                    'visibility'    => (string)($_POST['visibility'] ?? 'members'),
                    'starts_at'     => date('Y-m-d H:i:s', strtotime($startsAt)),
                    'ends_at'       => $endsAt !== '' ? date('Y-m-d H:i:s', strtotime($endsAt)) : null,
                    'location'      => trim((string)($_POST['location'] ?? '')) ?: null,
                    'city'          => trim((string)($_POST['city'] ?? '')) ?: null,
                    'lat'           => ($_POST['lat'] ?? '') !== '' ? (float)$_POST['lat'] : null,
                    'lng'           => ($_POST['lng'] ?? '') !== '' ? (float)$_POST['lng'] : null,
                    'max_attendees' => ($_POST['max_attendees'] ?? '') !== '' ? (int)$_POST['max_attendees'] : null,
                ]);

                // ---- Дополнительные фото ----
                if (!empty($_FILES['photos']['name'][0])) {
                    $files = club_event_collect_files($_FILES['photos']);
                    $order = 0;
                    foreach ($files as $file) {
                        if ($file['error'] !== UPLOAD_ERR_OK) continue;
                        try {
                            $url = ImageUploader::save($file, 'clubs/events', 2000, 2000, 10 * 1024 * 1024);
                            ClubEvent::addPhoto($eventId, (int)$me['id'], $url, $order++);
                        } catch (Throwable $ex) {
                            // пропускаем плохой файл, продолжаем
                        }
                    }
                }

                flash('Событие создано', 'success');
                redirect(url('club-event.php?id=' . $eventId));
            } catch (Throwable $ex) {
                $error = 'Не удалось сохранить: ' . $ex->getMessage();
            }
        }
    }
}

/** Преобразует $_FILES['photos'] в плоский список */
function club_event_collect_files(array $arr): array
{
    $out = [];
    $count = is_array($arr['name']) ? count($arr['name']) : 0;
    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name'     => $arr['name'][$i],
            'type'     => $arr['type'][$i],
            'tmp_name' => $arr['tmp_name'][$i],
            'error'    => $arr['error'][$i],
            'size'     => $arr['size'][$i],
        ];
    }
    return $out;
}

$pageTitle = 'Создать событие — ' . $club['name'];
$extraCss = [url('assets/css/clubs.css')];
require __DIR__ . '/includes/header.php';
?>

<section class="form-page form-page--wide">
    <div class="form-card">
        <h1 class="form-card__title">Новое событие</h1>
        <p class="form-card__subtitle">
            <a href="<?= e(url('club-events.php?club_id=' . $clubId)) ?>">← к списку событий</a>
        </p>

        <?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="field">
                <label>Название *</label>
                <input type="text" name="title" value="<?= e($old['title']) ?>" required maxlength="190">
            </div>

            <div class="field">
                <label>Описание</label>
                <textarea name="description" rows="5" maxlength="5000"><?= e($old['description']) ?></textarea>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Тип</label>
                    <select name="type">
                        <option value="training" <?= $old['type'] === 'training' ? 'selected' : '' ?>>Тренировка</option>
                        <option value="race"     <?= $old['type'] === 'race' ? 'selected' : '' ?>>Соревнование</option>
                        <option value="meeting"  <?= $old['type'] === 'meeting' ? 'selected' : '' ?>>Встреча</option>
                        <option value="other"    <?= $old['type'] === 'other' ? 'selected' : '' ?>>Другое</option>
                    </select>
                </div>
                <div class="field">
                    <label>Видимость</label>
                    <select name="visibility">
                        <option value="members" <?= $old['visibility'] === 'members' ? 'selected' : '' ?>>Участникам клуба</option>
                        <option value="public"  <?= $old['visibility'] === 'public' ? 'selected' : '' ?>>Публичное</option>
                        <option value="admins"  <?= $old['visibility'] === 'admins' ? 'selected' : '' ?>>Только админам</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Начало *</label>
                    <input type="datetime-local" name="starts_at" value="<?= e($old['starts_at']) ?>" required>
                </div>
                <div class="field">
                    <label>Окончание</label>
                    <input type="datetime-local" name="ends_at" value="<?= e($old['ends_at']) ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Место (текстом)</label>
                    <input type="text" name="location" value="<?= e($old['location']) ?>" maxlength="255"
                           placeholder="Парк Горького, главный вход">
                </div>
                <div class="field">
                    <label>Город</label>
                    <input type="text" name="city" value="<?= e($old['city']) ?>" maxlength="120">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Широта</label>
                    <input type="text" name="lat" value="<?= e($old['lat']) ?>" placeholder="55.751244">
                </div>
                <div class="field">
                    <label>Долгота</label>
                    <input type="text" name="lng" value="<?= e($old['lng']) ?>" placeholder="37.618423">
                </div>
            </div>

            <div class="field">
                <label>Максимум участников</label>
                <input type="number" name="max_attendees" value="<?= e($old['max_attendees']) ?>" min="0" placeholder="Без ограничений">
            </div>

            <div class="field">
                <label>Обложка</label>
                <input type="file" name="cover" accept="image/*,image/heic,image/heif,.heic,.heif">
                <div class="field__hint">
                    JPG, PNG, WebP или HEIC. Максимум 10 МБ. Рекомендуется 1600×900.
                </div>
            </div>

            <div class="field">
                <label>Фотографии (до 10)</label>
                <input type="file" name="photos[]" accept="image/*,image/heic,image/heif,.heic,.heif" multiple>
                <div class="field__hint">
                    HEIC с iPhone поддерживается — конвертируется автоматически.
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary">Создать событие</button>
                <a class="btn btn--ghost" href="<?= e(url('club-events.php?club_id=' . $clubId)) ?>">Отмена</a>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';
require_once __DIR__ . '/models/ClubEvent.php';

// Мягко подключаем ImageUploader
$hasUploader = false;
if (is_file(__DIR__ . '/includes/ImageUploader.php')) {
    require_once __DIR__ . '/includes/ImageUploader.php';
    $hasUploader = class_exists('ImageUploader');
}

auth_start();
$me = current_user();
require_login();

$eventId = (int)($_GET['id'] ?? 0);
$event = ClubEvent::findById($eventId);
if (!$event) { http_response_code(404); exit('Событие не найдено'); }

if (!ClubEvent::canManage($eventId, (int)$me['id'])) {
    http_response_code(403); exit('Недостаточно прав');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $title = trim((string)($_POST['title'] ?? ''));
        $startsAt = trim((string)($_POST['starts_at'] ?? ''));
        $endsAt = trim((string)($_POST['ends_at'] ?? ''));

        if (mb_strlen($title) < 3) {
            $error = 'Название минимум 3 символа';
        } elseif ($startsAt === '' || strtotime($startsAt) === false) {
            $error = 'Некорректная дата начала';
        } elseif ($endsAt !== '' && strtotime($endsAt) < strtotime($startsAt)) {
            $error = 'Окончание раньше начала';
        } else {
            $data = [
                'title'         => $title,
                'description'   => trim((string)($_POST['description'] ?? '')),
                'type'          => (string)($_POST['type'] ?? 'training'),
                'visibility'    => (string)($_POST['visibility'] ?? 'members'),
                'status'        => (string)($_POST['status'] ?? 'scheduled'),
                'starts_at'     => date('Y-m-d H:i:s', strtotime($startsAt)),
                'ends_at'       => $endsAt !== '' ? date('Y-m-d H:i:s', strtotime($endsAt)) : null,
                'location'      => trim((string)($_POST['location'] ?? '')) ?: null,
                'city'          => trim((string)($_POST['city'] ?? '')) ?: null,
                'lat'           => ($_POST['lat'] ?? '') !== '' ? (float)$_POST['lat'] : null,
                'lng'           => ($_POST['lng'] ?? '') !== '' ? (float)$_POST['lng'] : null,
                'max_attendees' => ($_POST['max_attendees'] ?? '') !== '' ? (int)$_POST['max_attendees'] : null,
            ];

            // ---- Новая обложка ----
            $coverFile = $_FILES['cover'] ?? null;
            $coverUploaded = false;

            if ($coverFile && ($coverFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                // Пользователь выбрал файл
                if ($coverFile['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Обложка: ' . club_event_upload_error((int)$coverFile['error']);
                } elseif (!$hasUploader) {
                    $error = 'Обложка: класс ImageUploader не найден. Проверьте includes/ImageUploader.php';
                } else {
                    try {
                        $data['cover_url'] = ImageUploader::save(
                            $coverFile,
                            'clubs/events',
                            1600, 1200,
                            10 * 1024 * 1024
                        );
                        $coverUploaded = true;
                    } catch (Throwable $e) {
                        $error = 'Обложка: ' . $e->getMessage();
                    }
                }
            }

            // ---- Удалить обложку ----
            if (isset($_POST['remove_cover'])) {
                $data['cover_url'] = null;
            }

            if ($error === null) {
                try {
                    ClubEvent::update($eventId, $data);
                    flash(
                        $coverUploaded ? 'Сохранено, обложка обновлена' : 'Сохранено',
                        'success'
                    );
                    redirect(url('club-event.php?id=' . $eventId));
                } catch (Throwable $e) {
                    $error = 'Не удалось сохранить: ' . $e->getMessage();
                }
            }
        }
    }

    // ---- Дополнительные фото ----
    if ($action === 'upload_photos' && !empty($_FILES['photos'])) {
        if (!$hasUploader) {
            $error = 'Класс ImageUploader не найден';
        } else {
            $files = club_event_collect_files_admin($_FILES['photos']);
            $uploaded = 0;
            $errors = [];
            foreach ($files as $file) {
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = $file['name'] . ': ' . club_event_upload_error((int)$file['error']);
                    continue;
                }
                try {
                    $url = ImageUploader::save($file, 'clubs/events', 2000, 2000, 10 * 1024 * 1024);
                    ClubEvent::addPhoto($eventId, (int)$me['id'], $url, $uploaded);
                    $uploaded++;
                } catch (Throwable $e) {
                    $errors[] = $file['name'] . ': ' . $e->getMessage();
                }
            }
            if ($uploaded > 0) {
                flash('Загружено фотографий: ' . $uploaded, 'success');
                redirect(url('club-event.php?id=' . $eventId . '#photos'));
            }
            if ($errors) $error = implode('; ', $errors);
        }
    }

    // ---- Удаление фото ----
    if ($action === 'delete_photo' && !empty($_POST['photo_id'])) {
        ClubEvent::deletePhoto((int)$_POST['photo_id'], $eventId, (int)$me['id'], true);
        flash('Фото удалено', 'success');
        redirect(url('club-event-edit.php?id=' . $eventId . '#photos'));
    }

    // ---- Удаление события ----
    if ($action === 'delete') {
        ClubEvent::delete($eventId);
        flash('Событие удалено', 'success');
        redirect(url('club-events.php?club_id=' . (int)$event['club_id']));
    }
}

// Обновляем данные после возможных изменений
$event = ClubEvent::findById($eventId);
$photos = ClubEvent::photos($eventId);

if (!function_exists('club_event_collect_files_admin')) {
    function club_event_collect_files_admin(array $arr): array
    {
        $out = [];
        $count = is_array($arr['name']) ? count($arr['name']) : 0;
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'name' => $arr['name'][$i], 'type' => $arr['type'][$i],
                'tmp_name' => $arr['tmp_name'][$i], 'error' => $arr['error'][$i],
                'size' => $arr['size'][$i],
            ];
        }
        return $out;
    }
}

if (!function_exists('club_event_upload_error')) {
    function club_event_upload_error(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'файл превышает upload_max_filesize',
            UPLOAD_ERR_PARTIAL   => 'файл загрузился частично',
            UPLOAD_ERR_NO_FILE   => 'файл не выбран',
            UPLOAD_ERR_NO_TMP_DIR => 'нет временной папки',
            UPLOAD_ERR_CANT_WRITE => 'не удалось записать',
            UPLOAD_ERR_EXTENSION => 'загрузка остановлена расширением',
            default              => 'ошибка ' . $code,
        };
    }
}

$pageTitle = 'Редактировать событие';
$extraCss = [url('assets/css/clubs.css')];
require __DIR__ . '/includes/header.php';
?>

<section class="form-page form-page--wide">
    <div class="form-card">
        <h1 class="form-card__title">Редактирование события</h1>
        <p class="form-card__subtitle">
            <a href="<?= e(url('club-event.php?id=' . $eventId)) ?>">← к событию</a>
        </p>

        <?php if (!$hasUploader): ?>
            <div class="alert alert--error">
                <strong>Внимание:</strong> класс <code>ImageUploader</code> не найден.
                Файл <code>includes/ImageUploader.php</code> отсутствует или с ошибкой.
                Загрузка обложек и фото недоступна.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="field">
                <label>Название *</label>
                <input type="text" name="title" value="<?= e($event['title']) ?>" required maxlength="190">
            </div>

            <div class="field">
                <label>Описание</label>
                <textarea name="description" rows="5" maxlength="5000"><?= e((string)$event['description']) ?></textarea>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Тип</label>
                    <select name="type">
                        <?php foreach (['training'=>'Тренировка','race'=>'Соревнование','meeting'=>'Встреча','other'=>'Другое'] as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= $event['type'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Видимость</label>
                    <select name="visibility">
                        <?php foreach (['members'=>'Участникам','public'=>'Публичное','admins'=>'Только админам'] as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= $event['visibility'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Статус</label>
                    <select name="status">
                        <?php foreach (['scheduled'=>'Запланировано','cancelled'=>'Отменено','completed'=>'Завершено'] as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= $event['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Максимум участников</label>
                    <input type="number" name="max_attendees" min="0"
                           value="<?= $event['max_attendees'] !== null ? (int)$event['max_attendees'] : '' ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Начало *</label>
                    <input type="datetime-local" name="starts_at"
                           value="<?= e(date('Y-m-d\TH:i', strtotime((string)$event['starts_at']))) ?>" required>
                </div>
                <div class="field">
                    <label>Окончание</label>
                    <input type="datetime-local" name="ends_at"
                           value="<?= $event['ends_at'] ? e(date('Y-m-d\TH:i', strtotime((string)$event['ends_at']))) : '' ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Место</label>
                    <input type="text" name="location" value="<?= e((string)$event['location']) ?>" maxlength="255">
                </div>
                <div class="field">
                    <label>Город</label>
                    <input type="text" name="city" value="<?= e((string)$event['city']) ?>" maxlength="120">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Широта</label>
                    <input type="text" name="lat" value="<?= e((string)$event['lat']) ?>">
                </div>
                <div class="field">
                    <label>Долгота</label>
                    <input type="text" name="lng" value="<?= e((string)$event['lng']) ?>">
                </div>
            </div>

            <div class="field">
                <label>Обложка</label>
                <?php if (!empty($event['cover_url'])): ?>
                    <div class="club-event__cover-preview">
                        <img src="<?= e($event['cover_url']) ?>" alt="">
                        <label class="btn btn--ghost btn--sm">
                            <input type="checkbox" name="remove_cover" value="1"> Удалить
                        </label>
                    </div>
                <?php endif; ?>
                <input type="file" name="cover"
                       accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,.jpg,.jpeg,.png,.webp">
                <div class="field__hint">
                    JPG, PNG, WebP или <strong>HEIC</strong> (с iPhone). Максимум 10 МБ.
                    <?php if (!empty($event['cover_url'])): ?>Оставьте пустым, чтобы не менять.<?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary" <?= !$hasUploader ? 'disabled' : '' ?>>Сохранить</button>
                <a class="btn btn--ghost" href="<?= e(url('club-event.php?id=' . $eventId)) ?>">Отмена</a>
            </div>
        </form>

        <div class="danger-zone" style="margin-top:24px">
            <div class="danger-zone__title">Удаление события</div>
            <p>Это действие необратимо. Все фото и записи участников будут удалены.</p>
            <form method="post" onsubmit="return confirm('Удалить событие?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="btn btn--danger">Удалить событие</button>
            </form>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';
require_once __DIR__ . '/models/ClubEvent.php';
require_once __DIR__ . '/includes/ImageUploader.php';

auth_start();
$me = current_user();

$eventId = (int)($_GET['id'] ?? 0);
$event = ClubEvent::findById($eventId);
if (!$event) { http_response_code(404); exit('Событие не найдено'); }

$clubId = (int)$event['club_id'];
$club = Club::findById($clubId);

$role = $me ? Club::roleOf($clubId, (int)$me['id']) : null;
$canManage = $me && ClubEvent::canManage($eventId, (int)$me['id']);

// Приватность
if ($event['visibility'] === 'admins' && !$canManage) {
    http_response_code(403); exit('Доступ запрещён');
}
if ($event['visibility'] === 'members' && !$role && !$canManage) {
    http_response_code(403); exit('Доступ только для участников клуба');
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    // ---- RSVP ----
    if ($action === 'rsvp' && $role) {
        $status = (string)($_POST['status'] ?? 'going');
        $comment = trim((string)($_POST['comment'] ?? '')) ?: null;
        try {
            ClubEvent::setAttendance($eventId, (int)$me['id'], $status, $comment);
            $success = 'Ваш ответ сохранён';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    // ---- Загрузка фото (участник клуба) ----
    if ($action === 'upload_photos' && $role && !empty($_FILES['photos'])) {
        $files = club_event_collect_files($_FILES['photos']);
        $uploaded = 0;
        $errors = [];
        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) continue;
            try {
                $url = ImageUploader::save($file, 'clubs/events', 2000, 2000, 10 * 1024 * 1024);
                ClubEvent::addPhoto($eventId, (int)$me['id'], $url, $uploaded);
                $uploaded++;
            } catch (Throwable $e) {
                $errors[] = $file['name'] . ': ' . $e->getMessage();
            }
        }
        if ($uploaded > 0) {
            $success = 'Загружено фотографий: ' . $uploaded;
        }
        if ($errors) {
            $error = implode('; ', $errors);
        }
    }

    // ---- Удаление фото ----
    if ($action === 'delete_photo' && !empty($_POST['photo_id'])) {
        $ok = ClubEvent::deletePhoto((int)$_POST['photo_id'], $eventId, (int)($me['id'] ?? 0), $canManage);
        if ($ok) $success = 'Фото удалено';
        else $error = 'Не удалось удалить';
    }

    if ($success) flash($success, 'success');
    if ($error) flash($error, 'error');
    redirect(url('club-event.php?id=' . $eventId . '#photos'));
}

// Данные
$photos = ClubEvent::photos($eventId);
$attendees = ClubEvent::attendees($eventId);
$myStatus = $me ? ClubEvent::myStatus($eventId, (int)$me['id']) : null;

$going = array_values(array_filter($attendees, fn($a) => $a['status'] === 'going'));
$maybe = array_values(array_filter($attendees, fn($a) => $a['status'] === 'maybe'));

$starts = strtotime((string)$event['starts_at']);
$ends = $event['ends_at'] ? strtotime((string)$event['ends_at']) : null;
$isPast = $starts < time();

function club_event_type_icon2(string $t): string {
    return match ($t) { 'training'=>'🏃','race'=>'🏆','meeting'=>'☕', default=>'📅' };
}

function club_event_collect_files(array $arr): array {
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

$pageTitle = $event['title'];
$extraCss = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    url('assets/css/clubs.css'),
];
$extraJs = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

require __DIR__ . '/includes/header.php';
?>

<section class="club-event">
    <a class="club-event__back muted" href="<?= e(url('club-events.php?club_id=' . $clubId)) ?>">
        ← События клуба «<?= e($club['name']) ?>»
    </a>

    <header class="club-event__hero">
        <div class="club-event__cover"
             style="background-image:url('<?= e((string)($event['cover_url'] ?? '')) ?>')">
            <?php if (empty($event['cover_url'])): ?>
                <span class="club-event__cover-placeholder">
                    <?= e(club_event_type_icon2((string)$event['type'])) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="club-event__head">
            <div>
                <span class="club-event__type">
                    <?= e(club_event_type_icon2((string)$event['type'])) ?>
                    <?= e(ucfirst((string)$event['type'])) ?>
                </span>
                <?php if ($event['status'] === 'cancelled'): ?>
                    <span class="admin-badge admin-badge--danger">Отменено</span>
                <?php endif; ?>
                <h1 class="club-event__title"><?= e($event['title']) ?></h1>
                <div class="club-event__meta muted">
                    📅 <?= e(date('d.m.Y H:i', $starts)) ?>
                    <?php if ($ends): ?> — <?= e(date('H:i', $ends)) ?><?php endif; ?>
                    <?php if ($event['city']): ?> · 📍 <?= e($event['city']) ?><?php endif; ?>
                    · Создал <a href="<?= e(url('profile.php?u=' . urlencode((string)$event['creator_username']))) ?>"><?= e($event['creator_display_name']) ?></a>
                </div>
            </div>

            <?php if ($canManage): ?>
                <div class="club-event__actions">
                    <a class="btn btn--ghost btn--sm"
                       href="<?= e(url('club-event-edit.php?id=' . $eventId)) ?>">✏️ Редактировать</a>
                    <form method="post" action="<?= e(url('api/club-event-delete.php')) ?>"
                          onsubmit="return confirm('Удалить событие? Это необратимо.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <button class="btn btn--ghost btn--sm">🗑 Удалить</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($event['description'])): ?>
            <div class="club-event__description">
                <?= nl2br(e((string)$event['description'])) ?>
            </div>
        <?php endif; ?>

        <div class="club-event__facts">
            <div><span class="muted">Идут</span> <strong><?= count($going) ?></strong><?= $event['max_attendees'] ? ' / ' . (int)$event['max_attendees'] : '' ?></div>
            <div><span class="muted">Возможно</span> <strong><?= count($maybe) ?></strong></div>
            <?php if ($event['location']): ?>
                <div><span class="muted">Место</span> <strong><?= e($event['location']) ?></strong></div>
            <?php endif; ?>
        </div>

        <?php if ($event['lat'] && $event['lng']): ?>
            <div id="event-map" class="club-event__map"></div>
            <script>
            window.__EVENT_POINT__ = { lat: <?= (float)$event['lat'] ?>, lng: <?= (float)$event['lng'] ?> };
            </script>
        <?php endif; ?>
    </header>

    <?php if ($role && $event['status'] !== 'cancelled' && !$isPast): ?>
        <div class="club-event__rsvp">
            <form method="post" class="club-event__rsvp-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rsvp">
                <button name="status" value="going"   class="btn <?= $myStatus === 'going' ? 'btn--primary' : 'btn--ghost' ?>">✅ Иду</button>
                <button name="status" value="maybe"   class="btn <?= $myStatus === 'maybe' ? 'btn--primary' : 'btn--ghost' ?>">❔ Возможно</button>
                <button name="status" value="declined" class="btn <?= $myStatus === null ? 'btn--ghost' : 'btn--ghost' ?>">✕ Не смогу</button>
            </form>
        </div>
    <?php endif; ?>

    <section class="club-event__section" id="photos">
        <div class="club-event__section-head">
            <h2>Фотографии <span class="muted">(<?= count($photos) ?>)</span></h2>
            <?php if ($role): ?>
                <form method="post" enctype="multipart/form-data" class="club-event__upload-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_photos">
                    <label class="btn btn--ghost btn--sm">
                        📷 Добавить фото
                        <input type="file" name="photos[]" accept="image/*,image/heic,image/heif,.heic,.heif"
                               multiple hidden onchange="this.form.submit()">
                    </label>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$photos): ?>
            <p class="muted">Пока нет фотографий.</p>
        <?php else: ?>
            <div class="event-photos">
                <?php foreach ($photos as $ph): ?>
                    <div class="event-photo">
                        <a href="<?= e($ph['url']) ?>" class="event-photo__link" data-photo-url="<?= e($ph['url']) ?>">
                            <img src="<?= e($ph['url']) ?>" alt="" loading="lazy">
                        </a>
                        <?php if ($canManage || (int)$ph['user_id'] === (int)($me['id'] ?? 0)): ?>
                            <form method="post" class="event-photo__delete"
                                  onsubmit="return confirm('Удалить фото?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_photo">
                                <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
                                <button title="Удалить">×</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="club-event__section">
        <h2>Участники</h2>
        <?php if (!$attendees): ?>
            <p class="muted">Пока никто не записался.</p>
        <?php else: ?>
            <div class="event-attendees">
                <?php foreach ($attendees as $a): ?>
                    <a class="event-attendee"
                       href="<?= e(url('profile.php?u=' . urlencode((string)$a['username']))) ?>">
                        <span class="avatar avatar--sm">
                            <?php if (!empty($a['avatar_url'])): ?>
                                <img src="<?= e($a['avatar_url']) ?>" alt="">
                            <?php else: ?>
                                <?= e(mb_substr((string)$a['display_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </span>
                        <span><?= e($a['display_name']) ?></span>
                        <span class="event-attendee__status event-attendee__status--<?= e($a['status']) ?>">
                            <?= $a['status'] === 'going' ? '✅' : ($a['status'] === 'maybe' ? '❔' : '✕') ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>

<script>
(function () {
    if (typeof L === "undefined") return;
    var el = document.getElementById("event-map");
    var p = window.__EVENT_POINT__;
    if (!el || !p) return;
    var map = L.map(el, { scrollWheelZoom: false }).setView([p.lat, p.lng], 15);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
    L.marker([p.lat, p.lng]).addTo(map);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
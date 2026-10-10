<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';
require_once __DIR__ . '/models/ClubEvent.php';
require_once __DIR__ . '/models/ClubEventComment.php';
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

// ---- Данные ----
$photos = ClubEvent::photos($eventId);
$attendees = ClubEvent::attendees($eventId);
$myStatus = $me ? ClubEvent::myStatus($eventId, (int)$me['id']) : null;

$going = array_values(array_filter($attendees, fn($a) => $a['status'] === 'going'));
$maybe = array_values(array_filter($attendees, fn($a) => $a['status'] === 'maybe'));

$starts = strtotime((string)$event['starts_at']);
$ends = $event['ends_at'] ? strtotime((string)$event['ends_at']) : null;
$isPast = $starts < time();

$eventComments = ClubEventComment::forEvent($eventId);

function club_event_type_icon2(string $t): string {
    return match ($t) { 'training'=>'🏃','race'=>'🏆','meeting'=>'☕', default=>'📅' };
}

/**
 * Рекурсивный рендер дерева комментариев события.
 */
function render_event_comment(array $c, array $tree, ?array $me, bool $canManage, int $depth = 0): void
{
    $children  = $tree[(int)$c['id']] ?? [];
    $meId      = $me ? (int)$me['id'] : 0;
    $canEdit   = $meId > 0 && (int)$c['user_id'] === $meId;
    $canDelete = $canEdit || $canManage;
    ?>
    <div class="comment <?= $depth > 0 ? 'comment--reply' : '' ?>"
         id="comment-<?= (int)$c['id'] ?>"
         data-comment-id="<?= (int)$c['id'] ?>">
        <span class="avatar avatar--sm">
            <?php if (!empty($c['avatar_url'])): ?>
                <img src="<?= e($c['avatar_url']) ?>" alt="">
            <?php else: ?>
                <?= e(mb_substr((string)$c['display_name'], 0, 1)) ?>
            <?php endif; ?>
        </span>
        <div class="comment__body">
            <div class="comment__head">
                <a href="<?= e(url('profile.php?u=' . urlencode((string)$c['username']))) ?>">
                    <strong><?= e($c['display_name']) ?></strong>
                </a>
                <span class="comment__time muted"><?= e(time_ago((string)$c['created_at'])) ?></span>
                <?php if (!empty($c['edited_at'])): ?>
                    <span class="comment__edited muted"
                          title="Изменено <?= e(date('d.m.Y H:i', strtotime((string)$c['edited_at']))) ?>">ред.</span>
                <?php endif; ?>
                <?php if ($meId > 0): ?>
                    <button type="button"
                            class="comment__reply js-event-reply-btn"
                            data-comment-id="<?= (int)$c['id'] ?>"
                            data-display-name="<?= e($c['display_name']) ?>"
                            title="Ответить">↩ Ответить</button>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                    <button type="button"
                            class="comment__edit js-event-edit-btn"
                            data-comment-id="<?= (int)$c['id'] ?>"
                            data-body="<?= e($c['body']) ?>"
                            title="Редактировать">✏️</button>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <button type="button"
                            class="comment__delete js-event-delete-btn"
                            data-comment-id="<?= (int)$c['id'] ?>"
                            title="Удалить">×</button>
                <?php endif; ?>
            </div>
            <div class="comment__text js-comment-text"><?= nl2br(e($c['body'])) ?></div>

            <?php if ($children): ?>
                <div class="comment__children">
                    <?php foreach ($children as $child): ?>
                        <?php render_event_comment($child, $tree, $me, $canManage, $depth + 1); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Дерево комментариев
$tree = [];
foreach ($eventComments as $c) {
    $pid = isset($c['parent_id']) && $c['parent_id'] !== null ? (int)$c['parent_id'] : 0;
    $tree[$pid][] = $c;
}
$roots = $tree[0] ?? [];

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
                    <form method="post" action="<?= e(url('club-event-delete.php')) ?>"
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
                <button name="status" value="declined" class="btn btn--ghost">✕ Не смогу</button>
            </form>
        </div>
    <?php endif; ?>

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

    <!-- ============ КОММЕНТАРИИ ============ -->
    <section class="club-event__section" id="comments">
        <h2>Комментарии <span class="muted" id="event-comments-count">(<?= count($eventComments) ?>)</span></h2>

        <div class="event-comments" id="event-comments">
            <?php if (!$roots): ?>
                <p class="muted" id="event-comments-empty">Пока нет комментариев. Будьте первым.</p>
            <?php else: ?>
                <?php foreach ($roots as $c): ?>
                    <?php render_event_comment($c, $tree, $me, (bool)$canManage); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($me): ?>
            <form class="comment-form" id="event-comment-form"
                  method="post"
                  action="javascript:void(0);"
                  data-event-id="<?= $eventId ?>"
                  data-api-add="<?= e(url('api/club-event-comment.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="parent_id" id="event-comment-parent-id" value="">
                <div class="comment-form__reply-hint" id="event-comment-reply-hint" hidden>
                    Ответ на <strong id="event-comment-reply-name"></strong>
                    <button type="button" class="comment-form__reply-cancel" id="event-comment-reply-cancel" aria-label="Отменить ответ">×</button>
                </div>
                <textarea name="body" rows="2" placeholder="Написать комментарий..." required maxlength="4000"></textarea>
                <button class="btn btn--primary" type="submit">Отправить</button>
            </form>
        <?php else: ?>
            <div class="activity-view__login-cta">
                <a href="<?= e(url('login.php')) ?>" class="btn btn--primary">Войдите</a>, чтобы оставить комментарий.
            </div>
        <?php endif; ?>
    </section>
</section>

<!-- ============================================================
     JS: карта события
     ============================================================ -->
<?php if ($event['lat'] && $event['lng']): ?>
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
<?php endif; ?>

<!-- ============================================================
     JS: комментарии
     ============================================================ -->
<script>
(function () {
    "use strict";

    var form = document.getElementById("event-comment-form");
    if (!form) return;

    var apiAdd      = form.dataset.apiAdd;
    var eventId     = form.dataset.eventId;
    var apiEdit     = <?= json_encode(url('api/club-event-comment-edit.php')) ?>;
    var apiDelete   = <?= json_encode(url('api/club-event-comment-delete.php')) ?>;
    var csrf        = <?= json_encode(csrf_token()) ?>;
    var commentsEl  = document.getElementById("event-comments");
    var countEl     = document.getElementById("event-comments-count");
    var emptyEl     = document.getElementById("event-comments-empty");

    var parentInput = document.getElementById("event-comment-parent-id");
    var hint        = document.getElementById("event-comment-reply-hint");
    var hintName    = document.getElementById("event-comment-reply-name");
    var cancelBtn   = document.getElementById("event-comment-reply-cancel");
    var textarea    = form.querySelector("textarea[name=body]");

    if (!apiAdd)     { console.error("event-comment-form: data-api-add пустой"); return; }
    if (!eventId)    { console.error("event-comment-form: data-event-id пустой"); return; }
    if (!commentsEl) { console.error("event-comments не найден"); return; }

    function esc(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/\x27/g, "&#039;");
    }

    function updateCount(delta) {
        if (!countEl) return;
        var m = countEl.textContent.match(/\d+/);
        var n = (m ? parseInt(m[0], 10) : 0) + delta;
        countEl.textContent = "(" + Math.max(0, n) + ")";
    }

    function bumpEmpty() {
        if (emptyEl && commentsEl.querySelectorAll(".comment").length > 0) {
            emptyEl.remove();
        }
    }

    // --- Ответ ---
    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-event-reply-btn");
        if (!btn) return;
        e.preventDefault();
        parentInput.value = btn.dataset.commentId;
        hintName.textContent = btn.dataset.displayName || "";
        hint.hidden = false;
        textarea.focus();
        textarea.placeholder = "Ответ " + (btn.dataset.displayName || "") + "...";
    });

    if (cancelBtn) {
        cancelBtn.addEventListener("click", function () {
            parentInput.value = "";
            hint.hidden = true;
            textarea.placeholder = "Написать комментарий...";
        });
    }

    // --- Отправка ---
    form.addEventListener("submit", function (e) {
        e.preventDefault();
        var body = textarea.value.trim();
        if (!body) return;

        var submitBtn = form.querySelector("button[type=submit]");
        if (submitBtn) submitBtn.disabled = true;

        var payload = {
            event_id: parseInt(eventId, 10),
            body: body,
            parent_id: parentInput.value ? parseInt(parentInput.value, 10) : null
        };

        fetch(apiAdd, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
                "Accept": "application/json"
            },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.ok) { alert((res && res.error) || "Ошибка"); return; }
            var c = res.data.comment;

            var av = c.avatar_url
                ? "<img src=\"" + esc(c.avatar_url) + "\" alt=\"\">"
                : esc(c.initial);

            var commentHtml =
                "<div class=\"comment\" id=\"comment-" + c.id + "\" data-comment-id=\"" + c.id + "\">" +
                    "<span class=\"avatar avatar--sm\">" + av + "</span>" +
                    "<div class=\"comment__body\">" +
                        "<div class=\"comment__head\">" +
                            "<a href=\"" + esc(c.profile_url) + "\"><strong>" + esc(c.display_name) + "</strong></a>" +
                            "<span class=\"comment__time muted\">" + esc(c.time_ago) + "</span>" +
                            "<button type=\"button\" class=\"comment__reply js-event-reply-btn\" data-comment-id=\"" + c.id + "\" data-display-name=\"" + esc(c.display_name) + "\">↩ Ответить</button>" +
                            "<button type=\"button\" class=\"comment__edit js-event-edit-btn\" data-comment-id=\"" + c.id + "\" data-body=\"" + esc(c.body) + "\">✏️</button>" +
                            "<button type=\"button\" class=\"comment__delete js-event-delete-btn\" data-comment-id=\"" + c.id + "\">×</button>" +
                        "</div>" +
                        "<div class=\"comment__text js-comment-text\">" + esc(c.body).replace(/\n/g, "<br>") + "</div>" +
                    "</div>" +
                "</div>";

            if (c.parent_id) {
                var parentEl = commentsEl.querySelector(".comment[data-comment-id=\"" + c.parent_id + "\"]");
                if (parentEl) {
                    var childrenWrap = parentEl.querySelector(":scope > .comment__body > .comment__children");
                    if (!childrenWrap) {
                        childrenWrap = document.createElement("div");
                        childrenWrap.className = "comment__children";
                        parentEl.querySelector(":scope > .comment__body").appendChild(childrenWrap);
                    }
                    childrenWrap.insertAdjacentHTML("beforeend", commentHtml);
                } else {
                    commentsEl.insertAdjacentHTML("beforeend", commentHtml);
                }
            } else {
                commentsEl.insertAdjacentHTML("beforeend", commentHtml);
            }

            textarea.value = "";
            parentInput.value = "";
            hint.hidden = true;
            textarea.placeholder = "Написать комментарий...";
            updateCount(1);
            bumpEmpty();
        })
        .catch(function () { alert("Ошибка отправки"); })
        .finally(function () { if (submitBtn) submitBtn.disabled = false; });
    });

    // --- Редактирование ---
    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-event-edit-btn");
        if (!btn) return;
        e.preventDefault();
        var commentId = btn.dataset.commentId;
        var current = btn.dataset.body || "";

        var wrap = btn.closest(".comment");
        if (!wrap) return;
        var textEl = wrap.querySelector(".js-comment-text");
        if (!textEl) return;
        if (textEl.dataset.editing === "1") return;
        textEl.dataset.editing = "1";

        var newBody = prompt("Редактировать комментарий:", current);
        if (newBody === null) { textEl.dataset.editing = "0"; return; }
        newBody = newBody.trim();
        if (newBody === "" || newBody === current) { textEl.dataset.editing = "0"; return; }

        fetch(apiEdit, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
                "Accept": "application/json"
            },
            body: JSON.stringify({ comment_id: parseInt(commentId, 10), body: newBody })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.ok) { alert((res && res.error) || "Ошибка"); return; }
            textEl.innerHTML = esc(res.data.comment.body).replace(/\n/g, "<br>");

            var head = wrap.querySelector(".comment__head");
            if (head && !head.querySelector(".comment__edited")) {
                var ed = document.createElement("span");
                ed.className = "comment__edited muted";
                ed.textContent = "ред.";
                head.appendChild(ed);
            }
            btn.dataset.body = res.data.comment.body;
        })
        .catch(function () { alert("Ошибка сохранения"); })
        .finally(function () { textEl.dataset.editing = "0"; });
    });

    // --- Удаление ---
    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-event-delete-btn");
        if (!btn) return;
        e.preventDefault();
        if (!confirm("Удалить комментарий? Ответы тоже будут удалены.")) return;

        var commentId = btn.dataset.commentId;
        fetch(apiDelete, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
                "Accept": "application/json"
            },
            body: JSON.stringify({ comment_id: parseInt(commentId, 10) })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.ok) { alert((res && res.error) || "Ошибка"); return; }
            var wrap = btn.closest(".comment");
            if (wrap) {
                var n = wrap.querySelectorAll(".comment").length + 1;
                wrap.remove();
                updateCount(-n);
            }
        })
        .catch(function () { alert("Ошибка удаления"); });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
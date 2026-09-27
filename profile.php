<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/models/Follow.php';
require_once __DIR__ . '/models/Route.php';
require_once __DIR__ . '/models/Segment.php';

auth_start();
$me = current_user();

// ---- Какой профиль открываем ----
$username = trim((string)($_GET['u'] ?? ''));
if ($username === '' && $me) $username = (string)$me['username'];
if ($username === '') redirect(url('index.php'));

$user  = null;
$error = null;

try {
    $user = User::findByUsername($username);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (!$user) {
    http_response_code(404);
    $pageTitle = 'Пользователь не найден';
    require __DIR__ . '/includes/header.php';
    echo '<section class="form-page"><div class="form-card">';
    echo '<h1 class="form-card__title">Пользователь не найден</h1>';
    echo '<p>Такого профиля не существует или он был удалён.</p>';
    echo '<p style="margin-top:16px"><a href="' . e(url('feed.php')) . '" class="btn btn--primary">В ленту</a></p>';
    echo '</div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$isMe = $me && (int)$me['id'] === (int)$user['id'];

// ---- Приватность ----
$isPublicProfile = (int)$user['is_public'] === 1;
$isFollowing     = $me ? Follow::isFollowing((int)$me['id'], (int)$user['id']) : false;

$privateProfile = false;
$activities = [];
$leaderSegments = [];
$followers = [];
$following = [];
$followersCount = 0;
$followingCount = 0;

if (!$isPublicProfile && !$isMe) {
    $privateProfile = true;
    $stats = ['activities' => 0, 'distance_m' => 0, 'followers' => 0, 'following' => 0];
} else {
    $stats = ['activities' => 0, 'distance_m' => 0, 'followers' => 0, 'following' => 0];
    try {
        $stats = User::stats((int)$user['id']);
    } catch (Throwable $e) { /* нули */ }

    try {
        $followers      = Follow::followers((int)$user['id'], 6, 0);
        $following      = Follow::following((int)$user['id'], 6, 0);
        $followersCount = Follow::followersCount((int)$user['id']);
        $followingCount = Follow::followingCount((int)$user['id']);
    } catch (Throwable $e) {
        $followers = $following = [];
        $followersCount = $followingCount = 0;
    }

    try {
        $activities = Activity::byUser((int)$user['id'], 20);
    } catch (Throwable $e) { $activities = []; }

    try {
        $leaderSegments = profile_get_leader_segments((int)$user['id'], 20);
    } catch (Throwable $e) {
        $leaderSegments = [];
    }
}

// ---- Подписка ----
$isFollowedByMe = false;
try {
    if ($me && !$isMe) {
        $isFollowedByMe = Follow::isFollowing((int)$me['id'], (int)$user['id']);
    }
} catch (Throwable $e) { $isFollowedByMe = false; }

// ---- POST: подписка/отписка ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me && !$isMe) {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'follow') {
        try {
            Follow::follow((int)$me['id'], (int)$user['id']);
            flash('Вы подписались на @' . $user['username'], 'success');
        } catch (Throwable $e) {
            flash('Ошибка подписки: ' . $e->getMessage(), 'error');
        }
        redirect(url('profile.php?u=' . urlencode((string)$user['username'])));
    }

    if ($action === 'unfollow') {
        try {
            Follow::unfollow((int)$me['id'], (int)$user['id']);
            flash('Вы отписались от @' . $user['username'], 'success');
        } catch (Throwable $e) {
            flash('Ошибка отписки: ' . $e->getMessage(), 'error');
        }
        redirect(url('profile.php?u=' . urlencode((string)$user['username'])));
    }
}

// ---- Упрощённые треки для карт в профиле ----
$tracksByActivity = [];
if ($activities) {
    foreach ($activities as $a) {
        if (empty($a['track_json'])) {
            $tracksByActivity[(int)$a['id']] = [];
            continue;
        }
        $decoded = json_decode((string)$a['track_json'], true);
        if (!is_array($decoded) || count($decoded) < 2) {
            $tracksByActivity[(int)$a['id']] = [];
            continue;
        }

        $points = [];
        foreach ($decoded as $p) {
            if (isset($p['lat'], $p['lng'])) {
                $points[] = [
                    'lat' => round((float)$p['lat'], 5),
                    'lng' => round((float)$p['lng'], 5),
                ];
            }
        }

        if (count($points) > 200) {
            $step = (int)ceil(count($points) / 200);
            $simplified = [];
            for ($i = 0; $i < count($points); $i += $step) {
                $simplified[] = $points[$i];
            }
            if (end($simplified) !== end($points)) {
                $simplified[] = end($points);
            }
            $points = $simplified;
        }

        $tracksByActivity[(int)$a['id']] = $points;
    }
}

// ---- Фото активностей одним запросом ----
$photosByActivity = [];
if ($activities) {
    try {
        $ids = array_column($activities, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT * FROM activity_photos
             WHERE activity_id IN ($ph)
             ORDER BY activity_id ASC, order_index ASC, id ASC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $photosByActivity[(int)$row['activity_id']][] = $row;
        }
    } catch (Throwable $e) {
        $photosByActivity = [];
    }
}

$pageTitle = $user['display_name'];

$extraCss = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'];
$extraJs  = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

$inlineJs = '
window.__PROFILE_TRACKS__ = ' . json_encode($tracksByActivity, JSON_UNESCAPED_UNICODE) . ';
(function () {
    if (typeof L === "undefined") return;
    var tracks = window.__PROFILE_TRACKS__ || {};

    function initMap(el) {
        if (el.dataset.initialized === "1") return;
        var id = el.dataset.activityId;
        var pts = tracks[id] || [];
        if (!pts.length) return;

        el.dataset.initialized = "1";

        var map = L.map(el, {
            zoomControl: false,
            attributionControl: false,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            dragging: false,
            touchZoom: false,
            boxZoom: false,
            keyboard: false
        });

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
        var latlngs = pts.map(function(p) { return [p.lat, p.lng]; });
        var line = L.polyline(latlngs, { color: "#ff5a1f", weight: 4 }).addTo(map);
        L.circleMarker(latlngs[0], {
            radius: 4, color: "#0a7a3a", fillColor: "#0a7a3a", fillOpacity: 1
        }).addTo(map);
        L.circleMarker(latlngs[latlngs.length - 1], {
            radius: 4, color: "#b3261e", fillColor: "#b3261e", fillOpacity: 1
        }).addTo(map);
        map.fitBounds(line.getBounds(), { padding: [10, 10] });
    }

    var maps = document.querySelectorAll(".profile-map");
    if ("IntersectionObserver" in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    initMap(entry.target);
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: "200px" });
        maps.forEach(function (el) { io.observe(el); });
    } else {
        maps.forEach(initMap);
    }
})();

/* ---- КАРУСЕЛИ ФОТО В ПРОФИЛЕ ---- */
(function () {
    "use strict";

    function initGallery(el) {
        if (el.dataset.galleryInit === "1") return;
        el.dataset.galleryInit = "1";

        var track = el.querySelector(".feed-gallery__track");
        var prev = el.querySelector(".feed-gallery__nav--prev");
        var next = el.querySelector(".feed-gallery__nav--next");
        var counter = el.querySelector(".feed-gallery__counter");
        var items = track ? track.querySelectorAll(".feed-gallery__item") : [];
        if (!track || !items.length) return;

        function step() {
            var first = items[0];
            var rect = first.getBoundingClientRect();
            var style = window.getComputedStyle(track);
            var gap = parseInt(style.columnGap || style.gap || "0", 10) || 0;
            return rect.width + gap;
        }
        function update() {
            if (!counter) return;
            var s = step();
            var idx = Math.round(track.scrollLeft / s);
            if (idx < 0) idx = 0;
            if (idx > items.length - 1) idx = items.length - 1;
            counter.textContent = (idx + 1) + " / " + items.length;
            if (prev) prev.disabled = track.scrollLeft <= 1;
            if (next) next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
        }
        if (prev) prev.addEventListener("click", function (e) { e.preventDefault(); e.stopPropagation(); track.scrollBy({ left: -step(), behavior: "smooth" }); });
        if (next) next.addEventListener("click", function (e) { e.preventDefault(); e.stopPropagation(); track.scrollBy({ left: step(), behavior: "smooth" }); });
        track.addEventListener("scroll", function () {
            if (track._scrollRAF) cancelAnimationFrame(track._scrollRAF);
            track._scrollRAF = requestAnimationFrame(update);
        }, { passive: true });
        update();
    }

    document.querySelectorAll(".feed-gallery[data-gallery]").forEach(initGallery);
})();

/* ---- ЛАЙТБОКС ---- */
(function () {
    "use strict";
    if (!document.getElementById("feed-lightbox")) {
        var lb = document.createElement("div");
        lb.className = "lightbox";
        lb.id = "feed-lightbox";
        lb.hidden = true;
        lb.innerHTML =
            "<button class=\"lightbox__close\" id=\"feed-lb-close\" aria-label=\"Закрыть\">×</button>" +
            "<button class=\"lightbox__prev\" id=\"feed-lb-prev\" aria-label=\"Предыдущее\">‹</button>" +
            "<button class=\"lightbox__next\" id=\"feed-lb-next\" aria-label=\"Следующее\">›</button>" +
            "<div class=\"lightbox__counter\" id=\"feed-lb-counter\"></div>" +
            "<div class=\"lightbox__img-wrap\"><img src=\"\" alt=\"\" id=\"feed-lb-img\"></div>";
        document.body.appendChild(lb);
    }

    var lbImg = document.getElementById("feed-lb-img");
    var lbCounter = document.getElementById("feed-lb-counter");
    var btnClose = document.getElementById("feed-lb-close");
    var btnPrev = document.getElementById("feed-lb-prev");
    var btnNext = document.getElementById("feed-lb-next");
    var currentList = [];
    var currentIndex = 0;

    function show(index) {
        if (!currentList.length) return;
        if (index < 0) index = currentList.length - 1;
        if (index >= currentList.length) index = 0;
        currentIndex = index;
        lbImg.src = currentList[index];
        lbCounter.textContent = (index + 1) + " / " + currentList.length;
    }
    function open(list, index) {
        currentList = list;
        show(index);
        document.getElementById("feed-lightbox").hidden = false;
        document.body.style.overflow = "hidden";
    }
    function close() {
        document.getElementById("feed-lightbox").hidden = true;
        document.body.style.overflow = "";
        lbImg.src = "";
        currentList = [];
    }

    document.addEventListener("click", function (e) {
        if (e.target.closest(".feed-gallery__nav")) return;
        var item = e.target.closest(".feed-gallery__item");
        if (!item) return;
        e.preventDefault();
        var gallery = item.closest(".feed-gallery");
        var items = gallery ? gallery.querySelectorAll(".feed-gallery__item") : [item];
        var list = [];
        var index = 0;
        items.forEach(function (el, i) {
            list.push(el.dataset.photoUrl || el.getAttribute("href"));
            if (el === item) index = i;
        });
        open(list, index);
    });

    btnClose.addEventListener("click", close);
    btnPrev.addEventListener("click", function (e) { e.stopPropagation(); show(currentIndex - 1); });
    btnNext.addEventListener("click", function (e) { e.stopPropagation(); show(currentIndex + 1); });
    document.getElementById("feed-lightbox").addEventListener("click", function (e) { if (e.target === this) close(); });

    document.addEventListener("keydown", function (e) {
        if (document.getElementById("feed-lightbox").hidden) return;
        if (e.key === "Escape") close();
        else if (e.key === "ArrowLeft") show(currentIndex - 1);
        else if (e.key === "ArrowRight") show(currentIndex + 1);
    });

    var touchStartX = 0;
    document.getElementById("feed-lightbox").addEventListener("touchstart", function (e) {
        touchStartX = e.touches[0].clientX;
    }, { passive: true });
    document.getElementById("feed-lightbox").addEventListener("touchend", function (e) {
        var diff = e.changedTouches[0].clientX - touchStartX;
        if (Math.abs(diff) < 50) return;
        if (diff > 0) show(currentIndex - 1);
        else show(currentIndex + 1);
    });
})();

/* ---- МОДАЛКА ЛАЙКНУВШИХ ---- */
(function () {
    "use strict";

    var apiUrl = ' . json_encode(url('api/activity-likers.php')) . ';
    var cache = {};

    function el(id) { return document.getElementById(id); }

    function ensureModal() {
        if (el("likers-modal")) return;

        var modal = document.createElement("div");
        modal.className = "likers-modal";
        modal.id = "likers-modal";
        modal.hidden = true;
        modal.innerHTML =
            "<div class=\"likers-modal__backdrop\" data-close></div>" +
            "<div class=\"likers-modal__panel\" role=\"dialog\" aria-modal=\"true\">" +
                "<div class=\"likers-modal__head\">" +
                    "<h3 class=\"likers-modal__title\">Лайкнули</h3>" +
                    "<button type=\"button\" class=\"likers-modal__close\" data-close aria-label=\"Закрыть\">×</button>" +
                "</div>" +
                "<div class=\"likers-modal__body\" id=\"likers-modal-body\"></div>" +
            "</div>";

        document.body.appendChild(modal);

        modal.addEventListener("click", function (e) {
            if (e.target.closest("[data-close]")) close();
        });
        document.addEventListener("keydown", function (e) {
            if (!modal.hidden && e.key === "Escape") close();
        });
    }

    function open(activityId) {
        ensureModal();
        var modal = el("likers-modal");
        var body = el("likers-modal-body");
        modal.hidden = false;
        document.body.style.overflow = "hidden";

        if (cache[activityId]) {
            body.innerHTML = cache[activityId];
            return;
        }

        body.innerHTML = "<div class=\"likers-modal__loading\">Загрузка…</div>";

        fetch(apiUrl + "?activity_id=" + encodeURIComponent(activityId), {
            credentials: "same-origin",
            headers: { "Accept": "application/json" }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.ok) {
                throw new Error((res && res.error) || "Ошибка");
            }
            var data = res.data || {};
            var likers = data.likers || [];
            var html = renderList(likers);
            cache[activityId] = html;
            body.innerHTML = html;
        })
        .catch(function (err) {
            body.innerHTML = "<div class=\"likers-modal__empty\">Не удалось загрузить список</div>";
            console.error(err);
        });
    }

    function renderList(likers) {
        if (!likers.length) {
            return "<div class=\"likers-modal__empty\">Пока никто не лайкнул</div>";
        }
        var html = "<ul class=\"likers-list\">";
        likers.forEach(function (u) {
            var avatar = u.avatar_url
                ? "<img src=\"" + escapeAttr(u.avatar_url) + "\" alt=\"\">"
                : escapeHtml(u.initial || "?");
            html +=
                "<li class=\"likers-list__item\">" +
                    "<a class=\"likers-list__link\" href=\"" + escapeAttr(u.profile_url) + "\">" +
                        "<span class=\"avatar avatar--sm\">" + avatar + "</span>" +
                        "<span class=\"likers-list__info\">" +
                            "<span class=\"likers-list__name\">" + escapeHtml(u.display_name) + "</span>" +
                            "<span class=\"likers-list__username\">@" + escapeHtml(u.username) + "</span>" +
                        "</span>" +
                    "</a>" +
                "</li>";
        });
        html += "</ul>";
        return html;
    }

    function close() {
        var modal = el("likers-modal");
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = "";
    }

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/\x27/g, "&#039;");
    }
    function escapeAttr(s) { return escapeHtml(s); }

    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-likers-btn");
        if (!btn) return;
        e.preventDefault();
        var aid = parseInt(btn.dataset.activityId, 10);
        if (!aid) return;
        open(aid);
    });
})();
';

require __DIR__ . '/includes/header.php';

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

function profile_get_leader_segments(int $userId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));

    $sql = "
        SELECT s.id, s.name, s.type, s.distance_m, s.elevation_gain_m, s.created_at,
               u.username AS creator_username,
               (
                   SELECT MIN(e1.elapsed_time_sec)
                   FROM segment_efforts e1
                   WHERE e1.segment_id = s.id AND e1.user_id = :me
               ) AS my_best
        FROM segments s
        JOIN users u ON u.id = s.creator_id
        WHERE EXISTS (
            SELECT 1 FROM segment_efforts e2
            WHERE e2.segment_id = s.id AND e2.user_id = :me2
        )
        ORDER BY s.created_at DESC
        LIMIT 200
    ";

    $stmt = db()->prepare($sql);
    $stmt->bindValue(':me', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':me2', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $candidates = $stmt->fetchAll();

    if (!$candidates) return [];

    $leaders = [];

    foreach ($candidates as $s) {
        $myBest = (int)$s['my_best'];
        if ($myBest <= 0) continue;

        $check = db()->prepare(
            'SELECT MIN(elapsed_time_sec)
             FROM segment_efforts
             WHERE segment_id = ? AND user_id != ?'
        );
        $check->execute([(int)$s['id'], $userId]);
        $othersBest = $check->fetchColumn();

        if ($othersBest !== null && (int)$othersBest < $myBest) {
            continue;
        }

        $secondBest = null;
        $gap = null;

        if ($othersBest !== null) {
            $secondBest = (int)$othersBest;
            $gap = $secondBest - $myBest;
        }

        $cnt = db()->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM segment_efforts WHERE segment_id = ?'
        );
        $cnt->execute([(int)$s['id']]);
        $athletes = (int)$cnt->fetchColumn();

        $leaders[] = [
            'id'                 => (int)$s['id'],
            'name'               => (string)$s['name'],
            'type'               => (string)$s['type'],
            'distance_m'         => (float)$s['distance_m'],
            'elevation_gain_m'   => $s['elevation_gain_m'] !== null ? (float)$s['elevation_gain_m'] : null,
            'creator_username'   => (string)$s['creator_username'],
            'best_time'          => $myBest,
            'second_best'        => $secondBest,
            'gap_sec'            => $gap,
            'athletes'           => $athletes,
        ];

        if (count($leaders) >= $limit) break;
    }

    return $leaders;
}

function profile_segment_icon(string $type): string
{
    return match ($type) {
        'run'  => '🏃',
        'ride' => '🚴',
        'swim' => '🏊',
        'ski'  => '⛷️',
        'walk' => '🚶',
        'hike' => '🥾',
        default => '📦',
    };
}

function profile_segment_label(string $type): string
{
    return match ($type) {
        'run'  => 'Бег',
        'ride' => 'Велосипед',
        'swim' => 'Плавание',
        'ski'  => 'Лыжи',
        'walk' => 'Ходьба',
        'hike' => 'Хайкинг',
        default => 'Другое',
    };
}

function profile_format_elapsed(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}

function profile_format_gap(int $gapSec): string
{
    if ($gapSec <= 0) return '—';
    if ($gapSec < 60) return '+' . $gapSec . ' с';
    $m = intdiv($gapSec, 60);
    $s = $gapSec % 60;
    return '+' . $m . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
}

function activity_icon(string $type): string
{
    return match ($type) {
        'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
        'walk' => '🚶', 'hike' => '🥾', default => '📦',
    };
}
function activity_label(string $type): string
{
    return match ($type) {
        'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
        'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
        default => 'Другое',
    };
}
?>

<section class="profile-page">
    <!-- Шапка профиля -->
    <header class="profile-hero">
        <div class="profile-hero__cover"></div>
        <div class="profile-hero__body">
            <div class="profile-hero__avatar">
                <?php if (!empty($user['avatar_url'])): ?>
                    <img src="<?= e($user['avatar_url']) ?>" alt="">
                <?php else: ?>
                    <?= e(mb_substr((string)$user['display_name'], 0, 1)) ?>
                <?php endif; ?>
            </div>

            <div class="profile-hero__top">
                <div>
                    <div class="profile-hero__username">@<?= e($user['username']) ?></div>
                    <h1 class="profile-hero__name"><?= e($user['display_name']) ?></h1>
                    <div class="profile-hero__meta">
                        <?php if (!empty($user['city']) || !empty($user['country'])): ?>
                            <span>📍 <?= e(trim(($user['city'] ?? '') . ', ' . ($user['country'] ?? ''), ', ')) ?></span>
                        <?php endif; ?>
                        <span>📅 С нами с <?= e(date('F Y', strtotime((string)$user['created_at']))) ?></span>
                        <?php if (!$isPublicProfile): ?>
                            <span>🔒 Приватный профиль</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="profile-hero__actions">
                    <?php if ($isMe): ?>
                        <a href="<?= e(url('profile-edit.php')) ?>" class="btn btn--ghost btn--sm">⚙️ Настройки</a>
                        <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--primary btn--sm">+ Загрузить</a>
                    <?php elseif ($me): ?>
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $isFollowedByMe ? 'unfollow' : 'follow' ?>">
                            <button class="btn <?= $isFollowedByMe ? 'btn--ghost' : 'btn--primary' ?> btn--sm">
                                <?= $isFollowedByMe ? 'Отписаться' : '+ Подписаться' ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <a href="<?= e(url('register.php')) ?>" class="btn btn--primary btn--sm">Подписаться</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($user['bio'])): ?>
                <p class="profile-hero__bio"><?= nl2br(e((string)$user['bio'])) ?></p>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($privateProfile): ?>
        <div class="empty">
            <p>Это приватный профиль.</p>
            <p class="muted" style="margin-top:8px">
                Подпишитесь, чтобы видеть активности этого спортсмена.
            </p>
        </div>
    <?php else: ?>

        <!-- Цифры -->
        <div class="profile-numbers">
            <div class="profile-numbers__cell">
                <span class="profile-numbers__value"><?= (int)$stats['activities'] ?></span>
                <span class="profile-numbers__label">Активностей</span>
            </div>
            <div class="profile-numbers__cell">
                <span class="profile-numbers__value"><?= e(format_distance((float)$stats['distance_m'])) ?></span>
                <span class="profile-numbers__label">Всего дистанции</span>
            </div>
            <a href="<?= e(url('profile-followers.php?u=' . urlencode((string)$user['username']))) ?>"
               class="profile-numbers__cell profile-numbers__cell--link">
                <span class="profile-numbers__value"><?= (int)$stats['followers'] ?></span>
                <span class="profile-numbers__label">Подписчиков</span>
            </a>
            <a href="<?= e(url('profile-following.php?u=' . urlencode((string)$user['username']))) ?>"
               class="profile-numbers__cell profile-numbers__cell--link">
                <span class="profile-numbers__value"><?= (int)$stats['following'] ?></span>
                <span class="profile-numbers__label">Подписок</span>
            </a>
        </div>

        <!-- ============ ПОДПИСЧИКИ И ПОДПИСКИ ============ -->
        <?php if ($followers || $following): ?>
            <div class="profile-connections">
                <?php if ($followers): ?>
                    <div class="profile-connections__block">
                        <div class="profile-connections__head">
                            <h3 class="profile-connections__title">
                                Подписчики
                                <span class="profile-connections__count"><?= (int)$followersCount ?></span>
                            </h3>
                            <?php if ($followersCount > 6): ?>
                                <a href="<?= e(url('profile-followers.php?u=' . urlencode((string)$user['username']))) ?>"
                                   class="profile-connections__more">Показать всех →</a>
                            <?php endif; ?>
                        </div>
                        <div class="profile-connections__list">
                            <?php foreach ($followers as $f): ?>
                                <a href="<?= e(url('profile.php?u=' . urlencode((string)$f['username']))) ?>"
                                   class="connection-chip">
                                    <span class="avatar avatar--sm">
                                        <?php if (!empty($f['avatar_url'])): ?>
                                            <img src="<?= e($f['avatar_url']) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(mb_substr((string)$f['display_name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="connection-chip__name"><?= e($f['display_name']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($following): ?>
                    <div class="profile-connections__block">
                        <div class="profile-connections__head">
                            <h3 class="profile-connections__title">
                                Подписки
                                <span class="profile-connections__count"><?= (int)$followingCount ?></span>
                            </h3>
                            <?php if ($followingCount > 6): ?>
                                <a href="<?= e(url('profile-following.php?u=' . urlencode((string)$user['username']))) ?>"
                                   class="profile-connections__more">Показать всех →</a>
                            <?php endif; ?>
                        </div>
                        <div class="profile-connections__list">
                            <?php foreach ($following as $f): ?>
                                <a href="<?= e(url('profile.php?u=' . urlencode((string)$f['username']))) ?>"
                                   class="connection-chip">
                                    <span class="avatar avatar--sm">
                                        <?php if (!empty($f['avatar_url'])): ?>
                                            <img src="<?= e($f['avatar_url']) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(mb_substr((string)$f['display_name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="connection-chip__name"><?= e($f['display_name']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ============ СЕГМЕНТЫ, ГДЕ ЛИДЕР ============ -->
        <?php if ($leaderSegments): ?>
            <section class="profile-section profile-section--leaders">
                <div class="profile-section__head">
                    <h2 class="profile-section__title">
                        🏆 <?= $isMe ? 'Сегменты, где вы лидер' : 'Сегменты, где лидер' ?>
                    </h2>
                    <span class="profile-section__count"><?= count($leaderSegments) ?></span>
                </div>

                <div class="leader-segments">
                    <?php foreach ($leaderSegments as $s): ?>
                        <a class="leader-segment" href="<?= e(url('segment.php?id=' . (int)$s['id'])) ?>">
                            <div class="leader-segment__rank">🥇</div>

                            <div class="leader-segment__body">
                                <div class="leader-segment__head">
                                    <span class="leader-segment__type">
                                        <?= e(profile_segment_icon($s['type'])) ?>
                                        <?= e(profile_segment_label($s['type'])) ?>
                                    </span>
                                    <?php if ($s['athletes'] > 1): ?>
                                        <span class="muted">
                                            👥 <?= (int)$s['athletes'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="leader-segment__name"><?= e($s['name']) ?></div>

                                <div class="leader-segment__meta">
                                    <span><?= e(format_distance((float)$s['distance_m'])) ?></span>
                                    <?php if (!empty($s['elevation_gain_m'])): ?>
                                        <span>↑<?= (int)$s['elevation_gain_m'] ?> м</span>
                                    <?php endif; ?>
                                    <span>от @<?= e($s['creator_username']) ?></span>
                                </div>
                            </div>

                            <div class="leader-segment__stats">
                                <div class="leader-segment__time">
                                    <?= e(profile_format_elapsed((int)$s['best_time'])) ?>
                                </div>
                                <?php if ($s['gap_sec'] !== null && $s['gap_sec'] > 0): ?>
                                    <div class="leader-segment__gap" title="Отрыв от второго места">
                                        отрыв <?= e(profile_format_gap((int)$s['gap_sec'])) ?>
                                    </div>
                                <?php elseif ($s['second_best'] === null): ?>
                                    <div class="leader-segment__gap leader-segment__gap--solo">
                                        единственный
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php elseif ($isMe): ?>
            <section class="profile-section">
                <div class="empty">
                    <p>Пока нет сегментов, где вы лидер.</p>
                    <p class="muted" style="margin-top:8px">
                        Проходите сегменты быстрее всех — и они появятся здесь.
                    </p>
                    <a href="<?= e(url('segments.php')) ?>" class="btn btn--primary">Найти сегменты</a>
                </div>
            </section>
        <?php endif; ?>

        <!-- Активности -->
        <h2 class="profile-section-title">
            Активности
            <?php if (count($activities) >= 20): ?>
                <span class="muted" style="font-weight:500;font-size:14px">(последние 20)</span>
            <?php endif; ?>
        </h2>

        <?php if (!$activities): ?>
            <div class="empty">
                <p>Пока нет активностей.</p>
                <?php if ($isMe): ?>
                    <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--primary">Загрузить первую</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="profile-activities">
                <?php foreach ($activities as $a): ?>
                    <?php
                        $hasTrack = !empty($tracksByActivity[(int)$a['id']]);
                        $aid = (int)$a['id'];
                        $actPhotos = $photosByActivity[$aid] ?? [];
                        $likesCount = (int)($a['likes_count'] ?? 0);
                    ?>
                    <article class="profile-activity-card">
                        <div class="profile-activity-card__head">
                            <span class="activity-type">
                                <?= e(activity_icon((string)$a['type'])) ?> <?= e(activity_label((string)$a['type'])) ?>
                            </span>
                            <span class="muted"><?= e(time_ago((string)$a['created_at'])) ?></span>
                        </div>

                        <a href="<?= e(url('activity.php?id=' . $aid)) ?>"
                           class="profile-activity-card__title-link">
                            <h3 class="profile-activity-card__title"><?= e($a['title']) ?></h3>
                        </a>

                        <?php if ($hasTrack): ?>
                            <a href="<?= e(url('activity.php?id=' . $aid)) ?>"
                               class="profile-activity-card__map-link">
                                <div class="profile-map"
                                     data-activity-id="<?= $aid ?>"
                                     data-initialized="0"></div>
                            </a>
                        <?php else: ?>
                            <div class="profile-activity-card__no-map">
                                <span class="muted">Нет GPS-трека</span>
                            </div>
                        <?php endif; ?>

                        <?php if ($actPhotos): ?>
                            <div class="feed-gallery" data-gallery>
                                <div class="feed-gallery__track">
                                    <?php foreach ($actPhotos as $ph): ?>
                                        <a href="<?= e($ph['url']) ?>"
                                           class="feed-gallery__item"
                                           data-photo-url="<?= e($ph['url']) ?>">
                                            <img src="<?= e($ph['url']) ?>" alt="" loading="lazy" draggable="false">
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($actPhotos) > 1): ?>
                                    <button type="button" class="feed-gallery__nav feed-gallery__nav--prev" aria-label="Предыдущее">‹</button>
                                    <button type="button" class="feed-gallery__nav feed-gallery__nav--next" aria-label="Следующее">›</button>
                                    <div class="feed-gallery__counter">1 / <?= count($actPhotos) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="profile-activity-card__stats">
                            <div class="stat">
                                <span class="stat__value"><?= e(format_distance((float)$a['distance_m'])) ?></span>
                                <span class="stat__label">Дистанция</span>
                            </div>
                            <div class="stat">
                                <span class="stat__value"><?= e(format_duration((int)$a['duration_sec'])) ?></span>
                                <span class="stat__label">Время</span>
                            </div>
                            <div class="stat">
                                <span class="stat__value">
                                    <?php if ($a['type'] === 'ride' && $a['avg_speed_mps']): ?>
                                        <?= number_format((float)$a['avg_speed_mps'] * 3.6, 1, '.', '') ?> км/ч
                                    <?php else: ?>
                                        <?= e(format_pace((float)$a['distance_m'], (int)$a['duration_sec'])) ?>
                                    <?php endif; ?>
                                </span>
                                <span class="stat__label"><?= $a['type'] === 'ride' ? 'Средняя' : 'Темп' ?></span>
                            </div>
                            <div class="stat">
                                <span class="stat__value">
                                    <?= $a['elevation_gain_m'] ? (int)$a['elevation_gain_m'] . ' м' : '—' ?>
                                </span>
                                <span class="stat__label">Набор</span>
                            </div>
                        </div>

                        <div class="profile-activity-card__actions">
                            <?php if ($likesCount > 0): ?>
                                <button type="button"
                                        class="action js-likers-btn"
                                        data-activity-id="<?= $aid ?>"
                                        data-likers-count="<?= $likesCount ?>">
                                    <span class="action__icon">♥</span>
                                    <span><?= $likesCount ?></span>
                                </button>
                            <?php else: ?>
                                <span class="action">
                                    <span class="action__icon">♥</span>
                                    <span>0</span>
                                </span>
                            <?php endif; ?>
                            <a class="action" href="<?= e(url('activity.php?id=' . $aid . '#comments')) ?>">
    <span class="action__icon">💬</span>
    <span><?= (int)($a['comments_count'] ?? 0) ?></span>
</a>
                            <a class="action" href="<?= e(url('activity.php?id=' . $aid)) ?>">
                                <span class="action__icon">🔗</span>
                                <span>Открыть</span>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
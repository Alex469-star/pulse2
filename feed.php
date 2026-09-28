<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/models/Post.php';
require_once __DIR__ . '/models/User.php';

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

function feed_simplify_track(?string $trackJson, int $maxPoints = 200): array
{
    if (empty($trackJson)) return [];
    $raw = json_decode($trackJson, true);
    if (!is_array($raw) || count($raw) < 2) return [];

    $points = [];
    foreach ($raw as $p) {
        if (isset($p['lat'], $p['lng'])) {
            $points[] = ['lat' => round((float)$p['lat'], 5), 'lng' => round((float)$p['lng'], 5)];
        }
    }
    if (count($points) > $maxPoints) {
        $step = (int)ceil(count($points) / $maxPoints);
        $out = [];
        for ($i = 0; $i < count($points); $i += $step) $out[] = $points[$i];
        if (end($out) !== end($points)) $out[] = end($points);
        return $out;
    }
    return $points;
}

function feed_fetch_unified(int $viewerId, string $tab, string $type, int $limit, int $offset): array
{
    $union = [];
    $bindSets = ['act' => [], 'post' => []];

    if ($tab === 'mine') {
        $whereActivity = ' AND a.user_id = :me_a';
        $wherePost     = ' AND p.user_id = :me_p';
        $bindSets['act']['me_a']  = $viewerId;
        $bindSets['post']['me_p'] = $viewerId;
    } elseif ($tab === 'following') {
        $whereActivity = ' AND a.visibility IN ("public","followers")
                           AND a.user_id IN (SELECT following_id FROM follows WHERE follower_id = :fo_a)';
        $wherePost     = ' AND p.visibility IN ("public","followers")
                           AND p.user_id IN (SELECT following_id FROM follows WHERE follower_id = :fo_p)';
        $bindSets['act']['fo_a']  = $viewerId;
        $bindSets['post']['fo_p'] = $viewerId;
    } else {
        $whereActivity = ' AND (a.visibility = "public"
                                OR a.user_id = :me_a
                                OR (a.visibility = "followers" AND a.user_id IN (
                                     SELECT following_id FROM follows WHERE follower_id = :fo_a
                                )))';
        $wherePost     = ' AND (p.visibility = "public"
                                OR p.user_id = :me_p
                                OR (p.visibility = "followers" AND p.user_id IN (
                                     SELECT following_id FROM follows WHERE follower_id = :fo_p
                                )))';
        $bindSets['act']['me_a']  = $viewerId;
        $bindSets['act']['fo_a']  = $viewerId;
        $bindSets['post']['me_p'] = $viewerId;
        $bindSets['post']['fo_p'] = $viewerId;
    }

    $aType = ($type !== '') ? $type : '';
    $sqlA = 'SELECT
                "activity" AS kind,
                a.id AS id,
                a.user_id AS user_id,
                a.created_at AS sort_at,
                a.type AS type,
                a.title AS title,
                a.description AS description,
                a.distance_m AS distance_m,
                a.duration_sec AS duration_sec,
                a.avg_speed_mps AS avg_speed_mps,
                a.elevation_gain_m AS elevation_gain_m,
                a.track_json AS track_json,
                a.visibility AS visibility,
                u.username, u.display_name, u.avatar_url,
                (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id) AS likes_count,
                (SELECT COUNT(*) FROM activity_comments c WHERE c.activity_id = a.id) AS comments_count,
                (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id AND l.user_id = :vid) AS liked_by_me
             FROM activities a
             JOIN users u ON u.id = a.user_id
             WHERE 1=1' . $whereActivity;
    if ($aType !== '') $sqlA .= ' AND a.type = :atype';
    $union[] = $sqlA;

    $sqlP = 'SELECT
                "post" AS kind,
                p.id AS id,
                p.user_id AS user_id,
                p.created_at AS sort_at,
                NULL AS type,
                p.title AS title,
                p.body AS description,
                NULL AS distance_m,
                NULL AS duration_sec,
                NULL AS avg_speed_mps,
                NULL AS elevation_gain_m,
                NULL AS track_json,
                p.visibility AS visibility,
                u.username, u.display_name, u.avatar_url,
                (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id) AS likes_count,
                (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id) AS comments_count,
                (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id AND l.user_id = :vid2) AS liked_by_me
             FROM posts p
             JOIN users u ON u.id = p.user_id
             WHERE 1=1' . $wherePost;
    $union[] = $sqlP;

    $sql = '(' . implode(') UNION ALL (', $union) . ') ORDER BY sort_at DESC LIMIT :lim OFFSET :off';
    $stmt = db()->prepare($sql);

    $stmt->bindValue(':vid', $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(':vid2', $viewerId, PDO::PARAM_INT);

    foreach ($bindSets['act'] as $name => $val) {
        $stmt->bindValue(':' . $name, $val, PDO::PARAM_INT);
    }
    foreach ($bindSets['post'] as $name => $val) {
        $stmt->bindValue(':' . $name, $val, PDO::PARAM_INT);
    }
    if ($aType !== '') $stmt->bindValue(':atype', $aType);

    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function feed_activity_icon(string $t): string
{
    return match ($t) {
        'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
        'walk' => '🚶', 'hike' => '🥾', default => '📦',
    };
}
function feed_activity_label(string $t): string
{
    return match ($t) {
        'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
        'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
        default => 'Другое',
    };
}

// ============================================================
// ОСНОВНАЯ ЛОГИКА
// ============================================================

auth_start();
$me = require_login();

$tab  = (string)($_GET['tab'] ?? 'all');
$type = (string)($_GET['type'] ?? '');

$allowedTabs = ['all', 'following', 'mine'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'all';

$allowedTypes = ['', 'run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
if (!in_array($type, $allowedTypes, true)) $type = '';

$limit = 50;
$rows = [];
$error = null;

try {
    $rows = feed_fetch_unified((int)$me['id'], $tab, $type, $limit, 0);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$activityIds = [];
$postIds = [];
foreach ($rows as $r) {
    if ($r['kind'] === 'activity') $activityIds[] = (int)$r['id'];
    else $postIds[] = (int)$r['id'];
}

// Комментарии к активностям
$commentsByActivity = [];
if ($activityIds) {
    $ph = implode(',', array_fill(0, count($activityIds), '?'));
    $stmt = db()->prepare(
        "SELECT c.*, u.username, u.display_name, u.avatar_url
         FROM activity_comments c JOIN users u ON u.id = c.user_id
         WHERE c.activity_id IN ($ph) ORDER BY c.created_at ASC"
    );
    $stmt->execute($activityIds);
    foreach ($stmt->fetchAll() as $c) $commentsByActivity[(int)$c['activity_id']][] = $c;
}

// Комментарии к постам
$commentsByPost = [];
if ($postIds) {
    $ph = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = db()->prepare(
        "SELECT c.*, u.username, u.display_name, u.avatar_url
         FROM post_comments c JOIN users u ON u.id = c.user_id
         WHERE c.post_id IN ($ph) ORDER BY c.created_at ASC"
    );
    $stmt->execute($postIds);
    foreach ($stmt->fetchAll() as $c) $commentsByPost[(int)$c['post_id']][] = $c;
}

// Фото постов
$photosByPost = [];
if ($postIds) {
    $ph = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM post_photos WHERE post_id IN ($ph)
         ORDER BY post_id ASC, order_index ASC, id ASC"
    );
    $stmt->execute($postIds);
    foreach ($stmt->fetchAll() as $ph2) $photosByPost[(int)$ph2['post_id']][] = $ph2;
}

// Фото активностей — одним запросом
$photosByActivity = [];
if ($activityIds) {
    $ph = implode(',', array_fill(0, count($activityIds), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM activity_photos WHERE activity_id IN ($ph)
         ORDER BY activity_id ASC, order_index ASC, id ASC"
    );
    $stmt->execute($activityIds);
    foreach ($stmt->fetchAll() as $ph2) $photosByActivity[(int)$ph2['activity_id']][] = $ph2;
}

// Треки активностей
$tracksByActivity = [];
foreach ($rows as $r) {
    if ($r['kind'] === 'activity') {
        $tracksByActivity[(int)$r['id']] = feed_simplify_track($r['track_json'] ?? null);
    }
}

// Статистика сайдбара
$myStats = ['activities' => 0, 'distance_m' => 0];
try {
    $stats = User::stats((int)$me['id']);
    $myStats['activities'] = (int)($stats['activities'] ?? 0);
    $myStats['distance_m'] = (float)($stats['distance_m'] ?? 0);
} catch (Throwable $e) {}

$hasMore = count($rows) === $limit;

$pageTitle = 'Лента';

$extraCss = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'];
$extraJs  = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

$apiLikeAct      = url('api/like.php');
$apiCommentAct   = url('api/comment.php');
$apiLikePost     = url('api/post-like.php');
$apiCommentPost  = url('api/post-comment.php');
$apiFeed         = url('api/feed.php');
$csrfToken       = csrf_token();

$inlineJs = '
window.__FEED_TRACKS__ = ' . json_encode($tracksByActivity, JSON_UNESCAPED_UNICODE) . ';
window.__API_LIKE_ACT__ = ' . json_encode($apiLikeAct) . ';
window.__API_COMMENT_ACT__ = ' . json_encode($apiCommentAct) . ';
window.__API_LIKE_POST__ = ' . json_encode($apiLikePost) . ';
window.__API_COMMENT_POST__ = ' . json_encode($apiCommentPost) . ';
window.__API_FEED__ = ' . json_encode($apiFeed) . ';
window.__FEED_TAB__ = ' . json_encode($tab) . ';
window.__FEED_TYPE__ = ' . json_encode($type) . ';
window.__FEED_HAS_MORE__ = ' . ($hasMore ? 'true' : 'false') . ';
window.__CSRF__ = ' . json_encode($csrfToken) . ';

(function () {
    if (typeof L === "undefined") return;
    window.__feedRegisterTrack = function (id, pts) { window.__FEED_TRACKS__[id] = pts; };
    window.__feedInitMap = function (el) {
        if (el.dataset.initialized === "1") return;
        var id = el.dataset.activityId;
        var pts = window.__FEED_TRACKS__[id] || [];
        if (!pts.length) return;
        el.dataset.initialized = "1";
        var map = L.map(el, { zoomControl:false, attributionControl:false, scrollWheelZoom:false, doubleClickZoom:false, dragging:false, touchZoom:false, boxZoom:false, keyboard:false });
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
        var ll = pts.map(function (p) { return [p.lat, p.lng]; });
        var line = L.polyline(ll, { color: "#ff5a1f", weight: 4, opacity: 0.95 }).addTo(map);
        L.circleMarker(ll[0], { radius:4, color:"#0a7a3a", fillColor:"#0a7a3a", fillOpacity:1 }).addTo(map);
        L.circleMarker(ll[ll.length-1], { radius:4, color:"#b3261e", fillColor:"#b3261e", fillOpacity:1 }).addTo(map);
        map.fitBounds(line.getBounds(), { padding: [10, 10] });
    };
    var maps = document.querySelectorAll(".feed-map");
    if ("IntersectionObserver" in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { if (e.isIntersecting) { window.__feedInitMap(e.target); io.unobserve(e.target); } });
        }, { rootMargin: "200px" });
        maps.forEach(function (el) { io.observe(el); });
    } else maps.forEach(window.__feedInitMap);
})();

(function () {
    "use strict";
    var API = window.__API_FEED__, TAB = window.__FEED_TAB__, TYPE = window.__FEED_TYPE__;
    var CSRF = window.__CSRF__;
    var page = 1, loading = false;
    var hasMore = !!window.__FEED_HAS_MORE__;
    var listEl = document.getElementById("feed-list");
    var moreBtn = document.getElementById("feed-more-btn");
    var moreWrap = document.getElementById("feed-more");
    var loaderEl = document.getElementById("feed-loader");
    var sentinel = document.getElementById("feed-sentinel");
    if (!listEl || !moreBtn) return;

    function esc(s) {
        return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/\\x27/g,"&#39;");
    }
    function buildCommentsHtml(comments, kind) {
        if (!comments || !comments.length) return "";
        var cls = kind === "activity" ? "activity-card__comments" : "post-card__comments";
        var out = "<div class=\\"" + cls + "\\">";
        comments.forEach(function (c) {
            var av = c.avatar_url ? "<img src=\\"" + esc(c.avatar_url) + "\\" alt=\\"\\">" : esc(c.initial);
            var del = c.can_delete ? "<form method=\\"post\\" style=\\"display:inline\\" onsubmit=\\"return confirm(\\"Удалить?\\")\\"><input type=\\"hidden\\" name=\\"csrf\\" value=\\"" + CSRF + "\\"><input type=\\"hidden\\" name=\\"action\\" value=\\"delete_comment\\"><input type=\\"hidden\\" name=\\"comment_id\\" value=\\"" + c.id + "\\"><button class=\\"comment__delete\\">×</button></form>" : "";
            out += "<div class=\\"comment\\"><span class=\\"avatar avatar--sm\\">" + av + "</span><div class=\\"comment__body\\"><div class=\\"comment__head\\"><a href=\\"" + esc(c.profile_url) + "\\"><strong>" + esc(c.display_name) + "</strong></a><span class=\\"comment__time muted\\">" + esc(c.time_ago) + "</span>" + del + "</div><div>" + esc(c.body).replace(/\\n/g,"<br>") + "</div></div></div>";
        });
        return out + "</div>";
    }
    function buildActivityGallery(photos) {
        if (!photos || !photos.length) return "";
        var html = "<div class=\\"feed-gallery\\" data-gallery><div class=\\"feed-gallery__track\\">";
        photos.forEach(function (ph) {
            html += "<a href=\\"" + ph.url + "\\" class=\\"feed-gallery__item\\" data-photo-url=\\"" + ph.url + "\\"><img src=\\"" + ph.url + "\\" alt=\\"\\" loading=\\"lazy\\" draggable=\\"false\\"></a>";
        });
        html += "</div>";
        if (photos.length > 1) {
            html += "<button type=\\"button\\" class=\\"feed-gallery__nav feed-gallery__nav--prev\\" aria-label=\\"Предыдущее\\">‹</button>";
            html += "<button type=\\"button\\" class=\\"feed-gallery__nav feed-gallery__nav--next\\" aria-label=\\"Следующее\\">›</button>";
            html += "<div class=\\"feed-gallery__counter\\">1 / " + photos.length + "</div>";
        }
        html += "</div>";
        return html;
    }
    function buildActivityHtml(a) {
        var track = (a.track && a.track.length >= 2) ? "<a href=\\"" + a.url + "\\" class=\\"activity-card__map-link\\"><div class=\\"feed-map\\" data-activity-id=\\"" + a.id + "\\" data-initialized=\\"0\\"></div></a>" : "";
        var desc = (a.description && a.description.length) ? "<div class=\\"activity-card__description\\">" + esc(a.description).replace(/\\n/g,"<br>") + "</div>" : "";
        var vis = a.visibility === "private" ? " · 🔒" : (a.visibility === "followers" ? " · 👥" : "");
        var av = a.author.avatar_url ? "<img src=\\"" + esc(a.author.avatar_url) + "\\" alt=\\"\\">" : esc(a.author.initial);
        return "<article class=\\"activity-card\\" id=\\"activity-" + a.id + "\\">" +
            "<div class=\\"activity-card__head\\">" +
                "<a class=\\"activity-card__user\\" href=\\"" + esc(a.author.profile_url) + "\\"><span class=\\"avatar avatar--sm\\">" + av + "</span>" +
                "<span class=\\"activity-card__meta\\"><span class=\\"activity-card__name\\">" + esc(a.author.display_name) + "</span>" +
                "<span class=\\"activity-card__sub muted\\">@" + esc(a.author.username) + " · " + esc(a.time_ago) + vis + "</span></span></a>" +
                "<span class=\\"activity-type\\">" + a.type_icon + " " + esc(a.type_label) + "</span>" +
            "</div>" +
            "<a href=\\"" + a.url + "\\" class=\\"activity-card__title-link\\"><h3 class=\\"activity-card__title\\">" + esc(a.title) + "</h3></a>" +
            desc +
            track +
            buildActivityGallery(a.photos) +
            "<div class=\\"activity-card__stats\\">" +
                "<div class=\\"stat\\"><span class=\\"stat__value\\">" + esc(a.distance) + "</span><span class=\\"stat__label\\">Дистанция</span></div>" +
                "<div class=\\"stat\\"><span class=\\"stat__value\\">" + esc(a.duration) + "</span><span class=\\"stat__label\\">Время</span></div>" +
                "<div class=\\"stat\\"><span class=\\"stat__value\\">" + esc(a.pace_or_speed) + "</span><span class=\\"stat__label\\">" + esc(a.pace_label) + "</span></div>" +
                "<div class=\\"stat\\"><span class=\\"stat__value\\">" + esc(a.elevation) + "</span><span class=\\"stat__label\\">Набор</span></div>" +
            "</div>" +
            "<div class=\\"activity-card__actions\\">" +
                "<button type=\\"button\\" class=\\"action js-like-btn " + (a.liked_by_me ? "is-active" : "") + "\\" data-kind=\\"activity\\" data-id=\\"" + a.id + "\\"><span class=\\"action__icon\\">♥</span><span class=\\"js-like-count\\">" + a.likes_count + "</span></button>" +
                "<a class=\\"action\\" href=\\"" + a.url + "#comments\\"><span class=\\"action__icon\\">💬</span><span class=\\"js-comment-count\\">" + a.comments_count + "</span></a>" +
                "<a class=\\"action\\" href=\\"" + a.url + "\\"><span class=\\"action__icon\\">🔗</span><span>Открыть</span></a>" +
            "</div>" +
            buildCommentsHtml(a.comments, "activity") +
            "<form class=\\"comment-form js-comment-form\\" data-kind=\\"activity\\" data-id=\\"" + a.id + "\\"><input type=\\"text\\" name=\\"body\\" placeholder=\\"Написать комментарий...\\" required maxlength=\\"1000\\"><button type=\\"submit\\">Отправить</button></form>" +
        "</article>";
    }
    function buildPostHtml(p) {
        var photos = "";
        if (p.photos && p.photos.length) {
            var cls = p.photos.length === 1 ? "single" : "multi";
            photos = "<div class=\\"post-card__photos post-card__photos--" + cls + "\\">";
            p.photos.forEach(function (ph) { photos += "<a href=\\"" + p.url + "\\" class=\\"post-card__photo\\"><img src=\\"" + esc(ph.url) + "\\" alt=\\"\\"></a>"; });
            photos += "</div>";
        }
        var body = p.description ? esc(p.description.length > 400 ? p.description.substring(0,400) + "…" : p.description).replace(/\\n/g,"<br>") : "";
        var vis = p.visibility === "private" ? " · 🔒" : (p.visibility === "followers" ? " · 👥" : "");
        var av = p.author.avatar_url ? "<img src=\\"" + esc(p.author.avatar_url) + "\\" alt=\\"\\">" : esc(p.author.initial);
        return "<article class=\\"post-card\\" id=\\"post-" + p.id + "\\">" +
            "<div class=\\"post-card__head\\">" +
                "<a class=\\"post-card__user\\" href=\\"" + esc(p.author.profile_url) + "\\"><span class=\\"avatar avatar--sm\\">" + av + "</span>" +
                "<span class=\\"activity-card__meta\\"><span class=\\"activity-card__name\\">" + esc(p.author.display_name) + "</span>" +
                "<span class=\\"activity-card__sub muted\\">@" + esc(p.author.username) + " · " + esc(p.time_ago) + vis + "</span></span></a>" +
                "<span class=\\"activity-type activity-type--post\\">📖 Запись</span>" +
            "</div>" +
            "<a href=\\"" + p.url + "\\" class=\\"post-card__title-link\\"><h3 class=\\"post-card__title\\">" + esc(p.title) + "</h3></a>" +
            photos + (body ? "<div class=\\"post-card__body\\">" + body + "</div>" : "") +
            "<div class=\\"activity-card__actions\\">" +
                "<button type=\\"button\\" class=\\"action js-like-btn " + (p.liked_by_me ? "is-active" : "") + "\\" data-kind=\\"post\\" data-id=\\"" + p.id + "\\"><span class=\\"action__icon\\">♥</span><span class=\\"js-like-count\\">" + p.likes_count + "</span></button>" +
                "<a class=\\"action\\" href=\\"" + p.url + "#comments\\"><span class=\\"action__icon\\">💬</span><span class=\\"js-comment-count\\">" + p.comments_count + "</span></a>" +
                "<a class=\\"action\\" href=\\"" + p.url + "\\"><span class=\\"action__icon\\">📖</span><span>Читать</span></a>" +
            "</div>" +
            buildCommentsHtml(p.comments, "post") +
            "<form class=\\"comment-form js-comment-form\\" data-kind=\\"post\\" data-id=\\"" + p.id + "\\"><input type=\\"text\\" name=\\"body\\" placeholder=\\"Написать комментарий...\\" required maxlength=\\"1000\\"><button type=\\"submit\\">Отправить</button></form>" +
        "</article>";
    }
    function loadMore() {
        if (loading || !hasMore) return;
        loading = true;
        if (loaderEl) loaderEl.style.display = "flex";
        if (moreBtn) moreBtn.disabled = true;
        page += 1;
        var url = API + "?tab=" + encodeURIComponent(TAB) + "&type=" + encodeURIComponent(TYPE) + "&page=" + page;
        fetch(url, { credentials: "same-origin", headers: { "Accept": "application/json" } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.ok) return;
            var items = res.data.items || [];
            hasMore = !!res.data.has_more;
            if (items.length) {
                var html = "";
                items.forEach(function (it) {
                    if (it.kind === "activity") { window.__feedRegisterTrack(String(it.id), it.track || []); html += buildActivityHtml(it); }
                    else html += buildPostHtml(it);
                });
                listEl.insertAdjacentHTML("beforeend", html);
                listEl.querySelectorAll(".feed-map[data-initialized=\'0\']").forEach(function (el) { window.__feedInitMap(el); });
            }
            if (!hasMore) { if (moreWrap) moreWrap.style.display = "none"; }
            else if (moreBtn) moreBtn.disabled = false;
        })
        .catch(function (e) { console.error(e); })
        .finally(function () {
            loading = false;
            if (loaderEl) loaderEl.style.display = "none";
            if (moreBtn && hasMore) moreBtn.disabled = false;
        });
    }
    if (moreBtn) moreBtn.addEventListener("click", function (e) { e.preventDefault(); loadMore(); });
    if (sentinel && "IntersectionObserver" in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { if (e.isIntersecting && hasMore && !loading) loadMore(); });
        }, { rootMargin: "400px" });
        io.observe(sentinel);
    }
})();

(function () {
    "use strict";
    var LIKE_ACT = window.__API_LIKE_ACT__, LIKE_POST = window.__API_LIKE_POST__, CSRF = window.__CSRF__;
    function post(url, data) {
        return fetch(url, { method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/json","X-CSRF-Token":CSRF,"Accept":"application/json"}, body: JSON.stringify(data) })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); });
    }
    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-like-btn");
        if (!btn) return;
        e.preventDefault();
        if (btn.dataset.loading === "1") return;
        btn.dataset.loading = "1";
        var kind = btn.dataset.kind, id = btn.dataset.id;
        var countEl = btn.querySelector(".js-like-count");
        var wasActive = btn.classList.contains("is-active");
        var oldCount = parseInt(countEl.textContent, 10) || 0;
        btn.classList.toggle("is-active", !wasActive);
        countEl.textContent = wasActive ? Math.max(0, oldCount - 1) : oldCount + 1;
        var url = kind === "post" ? LIKE_POST : LIKE_ACT;
        var payload = kind === "post" ? { post_id: id } : { activity_id: id };
        post(url, payload)
            .then(function (res) {
                if (!res.ok || !res.body.ok) { btn.classList.toggle("is-active", wasActive); countEl.textContent = oldCount; return; }
                btn.classList.toggle("is-active", !!res.body.data.liked);
                countEl.textContent = res.body.data.count;
            })
            .catch(function () { btn.classList.toggle("is-active", wasActive); countEl.textContent = oldCount; })
            .finally(function () { btn.dataset.loading = "0"; });
    });
})();

(function () {
    "use strict";
    var ACT = window.__API_COMMENT_ACT__, POST = window.__API_COMMENT_POST__, CSRF = window.__CSRF__;
    function post(url, data) {
        return fetch(url, { method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/json","X-CSRF-Token":CSRF,"Accept":"application/json"}, body: JSON.stringify(data) })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); });
    }
    function esc(s) { return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/\\x27/g,"&#39;"); }
    document.addEventListener("submit", function (e) {
        var form = e.target.closest(".js-comment-form");
        if (!form) return;
        e.preventDefault();
        var kind = form.dataset.kind, id = form.dataset.id;
        var input = form.querySelector("input, textarea");
        var body = input.value.trim();
        if (!body) return;
        var submitBtn = form.querySelector("button[type=submit]");
        if (submitBtn) submitBtn.disabled = true;
        var url = kind === "post" ? POST : ACT;
        var payload = kind === "post" ? { post_id: id, body: body } : { activity_id: id, body: body };
        post(url, payload)
            .then(function (res) {
                if (!res.ok || !res.body.ok) { alert(res.body.error || "Ошибка"); return; }
                var c = res.body.data.comment;
                var card = form.closest(".activity-card, .post-card");
                var cls = kind === "post" ? "post-card__comments" : "activity-card__comments";
                var container = card.querySelector("." + cls);
                if (!container) {
                    container = document.createElement("div");
                    container.className = cls;
                    form.parentNode.insertBefore(container, form);
                }
                var av = c.avatar_url ? "<img src=\\"" + esc(c.avatar_url) + "\\" alt=\\"\\">" : esc(c.initial);
                container.insertAdjacentHTML("beforeend",
                    "<div class=\\"comment\\"><span class=\\"avatar avatar--sm\\">" + av + "</span><div class=\\"comment__body\\"><div class=\\"comment__head\\"><a href=\\"" + esc(c.profile_url) + "\\"><strong>" + esc(c.display_name) + "</strong></a><span class=\\"comment__time muted\\">" + esc(c.time_ago) + "</span></div><div>" + esc(c.body).replace(/\\n/g,"<br>") + "</div></div></div>");
                var counter = card.querySelector(".js-comment-count");
                if (counter) counter.textContent = res.body.data.count;
                input.value = "";
            })
            .catch(function () { alert("Ошибка отправки"); })
            .finally(function () { if (submitBtn) submitBtn.disabled = false; });
    });
})();

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

    if ("MutationObserver" in window) {
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (!(node instanceof Element)) return;
                    if (node.matches && node.matches(".feed-gallery[data-gallery]")) initGallery(node);
                    if (node.querySelectorAll) node.querySelectorAll(".feed-gallery[data-gallery]").forEach(initGallery);
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();

/* ЛАЙТБОКС */
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
';

require __DIR__ . '/includes/header.php';
?>

<div class="feed-layout">
    <aside class="feed-sidebar">
        <div class="sidebar-card sidebar-card--user">
            <a href="<?= e(url('profile.php?u=' . urlencode((string)$me['username']))) ?>" class="sidebar-user">
                <span class="sidebar-user__avatar">
                    <?php if (!empty($me['avatar_url'])): ?>
                        <img src="<?= e($me['avatar_url']) ?>" alt="">
                    <?php else: ?>
                        <?= e(mb_substr((string)$me['display_name'], 0, 1)) ?>
                    <?php endif; ?>
                </span>
                <span class="sidebar-user__info">
                    <span class="sidebar-user__name"><?= e($me['display_name']) ?></span>
                    <span class="sidebar-user__meta">@<?= e($me['username']) ?></span>
                </span>
				
            </a>
            <div class="sidebar-user__stats">
                <a href="<?= e(url('profile.php?u=' . urlencode((string)$me['username']))) ?>" class="sidebar-user__stat">
                    <span class="sidebar-user__stat-value"><?= (int)$myStats['activities'] ?></span>
                    <span class="sidebar-user__stat-label">Активностей</span>
                </a>
                <a href="<?= e(url('profile.php?u=' . urlencode((string)$me['username']))) ?>" class="sidebar-user__stat">
                    <span class="sidebar-user__stat-value"><?= e(format_distance($myStats['distance_m'])) ?></span>
                    <span class="sidebar-user__stat-label">Всего</span>
                </a>
            </div>
        </div>

        <div class="sidebar-card">
            <h3 class="sidebar-card__title">Действия</h3>
            <nav class="sidebar-nav">
                <a href="<?= e(url('activity-upload.php')) ?>" class="sidebar-nav__link"><span class="sidebar-nav__icon">📂</span><span>Активность</span></a>
                <a href="<?= e(url('post-create.php')) ?>" class="sidebar-nav__link"><span class="sidebar-nav__icon">✏️</span><span>Запись в блог</span></a>
                <a href="<?= e(url('route-create.php')) ?>" class="sidebar-nav__link"><span class="sidebar-nav__icon">🗺️</span><span>Маршрут</span></a>
                <a href="<?= e(url('segment-create.php')) ?>" class="sidebar-nav__link"><span class="sidebar-nav__icon">⚡</span><span>Сегмент</span></a>
            </nav>
        </div>
    </aside>

    <div class="feed-main">
        <header class="feed-page__head">
            <h1 class="feed-page__title">Лента</h1>
            <div class="feed-tabs">
                <?php
                    $tabLabels = [
                        'all'       => 'Всё',
                        'following' => 'Подписки',
                        'mine'      => 'Мои',
                    ];
                ?>
                <?php foreach ($tabLabels as $key => $label): ?>
                    <?php $qs = http_build_query(['tab' => $key, 'type' => $type]); ?>
                    <a href="?<?= e($qs) ?>" class="feed-tab <?= $tab === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </header>

        <div class="feed-filters">
            <?php
                $typeFilters = [
                    ''      => 'Все',
                    'run'   => '🏃 Бег',
                    'ride'  => '🚴 Вело',
                    'swim'  => '🏊 Плавание',
                    'ski'   => '⛷️ Лыжи',
                    'walk'  => '🚶 Ходьба',
                    'hike'  => '🥾 Хайкинг',
                ];
            ?>
            <?php foreach ($typeFilters as $key => $label): ?>
                <?php $qs = http_build_query(['tab' => $tab, 'type' => $key]); ?>
                <a href="?<?= e($qs) ?>" class="feed-filter <?= $type === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($error !== null): ?>
            <div class="alert alert--error"><strong>Ошибка:</strong> <?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!$rows): ?>
            <div class="empty">
                <?php if ($tab === 'mine'): ?>
                    <p>У вас пока нет ни активностей, ни записей.</p>
                    <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--primary">Загрузить активность</a>
                    <a href="<?= e(url('post-create.php')) ?>" class="btn btn--ghost">Написать в блог</a>
                <?php elseif ($tab === 'following'): ?>
                    <p>В подписках пока пусто.</p>
                    <a href="<?= e(url('search.php')) ?>" class="btn btn--primary">Найти людей</a>
                <?php else: ?>
                    <p>В ленте пока нет записей.</p>
                    <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--primary">Загрузить активность</a>
                    <a href="<?= e(url('post-create.php')) ?>" class="btn btn--ghost">Написать в блог</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="feed__list" id="feed-list">
                <?php foreach ($rows as $r): ?>
                    <?php $sortAt = (string)($r['sort_at'] ?? ''); ?>
                    <?php if ($r['kind'] === 'activity'): ?>
                        <?php
                            $aid = (int)$r['id'];
                            $hasTrack = !empty($tracksByActivity[$aid]);
                            $feedActPhotos = $photosByActivity[$aid] ?? [];
                        ?>
                        <article class="activity-card" id="activity-<?= $aid ?>">
                            <div class="activity-card__head">
                                <a class="activity-card__user" href="<?= e(url('profile.php?u=' . urlencode((string)$r['username']))) ?>">
                                    <span class="avatar avatar--sm">
                                        <?php if (!empty($r['avatar_url'])): ?>
                                            <img src="<?= e($r['avatar_url']) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(mb_substr((string)$r['display_name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="activity-card__meta">
                                        <span class="activity-card__name"><?= e($r['display_name']) ?></span>
                                        <span class="activity-card__sub muted">
                                            @<?= e($r['username']) ?> · <?= e(time_ago($sortAt)) ?>
                                            <?php if ($r['visibility'] === 'private'): ?> · 🔒<?php endif; ?>
                                            <?php if ($r['visibility'] === 'followers'): ?> · 👥<?php endif; ?>
                                        </span>
                                    </span>
                                </a>
                                <span class="activity-type"><?= e(feed_activity_icon((string)$r['type'])) ?> <?= e(feed_activity_label((string)$r['type'])) ?></span>
                            </div>

                            <a href="<?= e(url('activity.php?id=' . $aid)) ?>" class="activity-card__title-link">
                                <h3 class="activity-card__title"><?= e($r['title']) ?></h3>
                            </a>

                            <?php if (!empty($r['description'])): ?>
                                <div class="activity-card__description"><?= nl2br(e((string)$r['description'])) ?></div>
                            <?php endif; ?>

                            <?php if ($hasTrack): ?>
                                <a href="<?= e(url('activity.php?id=' . $aid)) ?>" class="activity-card__map-link">
                                    <div class="feed-map" data-activity-id="<?= $aid ?>" data-initialized="0"></div>
                                </a>
                            <?php endif; ?>

                            <?php if ($feedActPhotos): ?>
                                <div class="feed-gallery" data-gallery>
                                    <div class="feed-gallery__track">
                                        <?php foreach ($feedActPhotos as $ph): ?>
                                            <a href="<?= e($ph['url']) ?>"
                                               class="feed-gallery__item"
                                               data-photo-url="<?= e($ph['url']) ?>">
                                                <img src="<?= e($ph['url']) ?>" alt="" loading="lazy" draggable="false">
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($feedActPhotos) > 1): ?>
                                        <button type="button" class="feed-gallery__nav feed-gallery__nav--prev" aria-label="Предыдущее">‹</button>
                                        <button type="button" class="feed-gallery__nav feed-gallery__nav--next" aria-label="Следующее">›</button>
                                        <div class="feed-gallery__counter">1 / <?= count($feedActPhotos) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="activity-card__stats">
                                <div class="stat"><span class="stat__value"><?= e(format_distance((float)$r['distance_m'])) ?></span><span class="stat__label">Дистанция</span></div>
                                <div class="stat"><span class="stat__value"><?= e(format_duration((int)$r['duration_sec'])) ?></span><span class="stat__label">Время</span></div>
                                <div class="stat">
                                    <span class="stat__value">
                                        <?php if ($r['type'] === 'ride' && $r['avg_speed_mps']): ?>
                                            <?= number_format((float)$r['avg_speed_mps'] * 3.6, 1, '.', '') ?> км/ч
                                        <?php else: ?>
                                            <?= e(format_pace((float)$r['distance_m'], (int)$r['duration_sec'])) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="stat__label"><?= $r['type'] === 'ride' ? 'Средняя' : 'Темп' ?></span>
                                </div>
                                <div class="stat"><span class="stat__value"><?= $r['elevation_gain_m'] ? (int)$r['elevation_gain_m'] . ' м' : '—' ?></span><span class="stat__label">Набор</span></div>
                            </div>

                            <div class="activity-card__actions">
                                <button type="button" class="action js-like-btn <?= (int)$r['liked_by_me'] ? 'is-active' : '' ?>" data-kind="activity" data-id="<?= $aid ?>">
                                    <span class="action__icon">♥</span><span class="js-like-count"><?= (int)$r['likes_count'] ?></span>
                                </button>
                                <a class="action" href="<?= e(url('activity.php?id=' . $aid . '#comments')) ?>">
                                    <span class="action__icon">💬</span><span class="js-comment-count"><?= (int)$r['comments_count'] ?></span>
                                </a>
                                <a class="action" href="<?= e(url('activity.php?id=' . $aid)) ?>">
                                    <span class="action__icon">🔗</span><span>Открыть</span>
                                </a>
                            </div>

                            <?php $cs = $commentsByActivity[$aid] ?? []; ?>
                            <?php if ($cs): ?>
                                <div class="activity-card__comments">
                                    <?php foreach ($cs as $c): ?>
                                        <div class="comment">
                                            <span class="avatar avatar--sm">
                                                <?php if (!empty($c['avatar_url'])): ?>
                                                    <img src="<?= e($c['avatar_url']) ?>" alt="">
                                                <?php else: ?>
                                                    <?= e(mb_substr((string)$c['display_name'], 0, 1)) ?>
                                                <?php endif; ?>
                                            </span>
                                            <div class="comment__body">
                                                <div class="comment__head">
                                                    <a href="<?= e(url('profile.php?u=' . urlencode((string)$c['username']))) ?>"><strong><?= e($c['display_name']) ?></strong></a>
                                                    <span class="comment__time muted"><?= e(time_ago((string)$c['created_at'])) ?></span>
                                                </div>
                                                <div><?= nl2br(e($c['body'])) ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <form class="comment-form js-comment-form" data-kind="activity" data-id="<?= $aid ?>">
                                <input type="text" name="body" placeholder="Написать комментарий..." required maxlength="1000">
                                <button type="submit">Отправить</button>
                            </form>
                        </article>

                    <?php else: ?>
                        <?php
                            $pid = (int)$r['id'];
                            $postPhotos = $photosByPost[$pid] ?? [];
                            $postComments = $commentsByPost[$pid] ?? [];
                        ?>
                        <article class="post-card" id="post-<?= $pid ?>">
                            <div class="post-card__head">
                                <a class="post-card__user" href="<?= e(url('profile.php?u=' . urlencode((string)$r['username']))) ?>">
                                    <span class="avatar avatar--sm">
                                        <?php if (!empty($r['avatar_url'])): ?>
                                            <img src="<?= e($r['avatar_url']) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(mb_substr((string)$r['display_name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="activity-card__meta">
                                        <span class="activity-card__name"><?= e($r['display_name']) ?></span>
                                        <span class="activity-card__sub muted">
                                            @<?= e($r['username']) ?> · <?= e(time_ago($sortAt)) ?>
                                            <?php if ($r['visibility'] === 'private'): ?> · 🔒<?php endif; ?>
                                            <?php if ($r['visibility'] === 'followers'): ?> · 👥<?php endif; ?>
                                        </span>
                                    </span>
                                </a>
                                
                            </div>

                            <a href="<?= e(url('post.php?id=' . $pid)) ?>" class="post-card__title-link">
                                <h3 class="post-card__title"><?= e($r['title']) ?></h3>
                            </a>

                            <?php if ($postPhotos): ?>
    <div class="post-card__photos post-card__photos--<?= count($postPhotos) === 1 ? 'single' : 'multi' ?>">
        <?php foreach ($postPhotos as $ph): ?>
            <a href="<?= e(url('post.php?id=' . $pid)) ?>" class="post-card__photo">
                <img src="<?= e($ph['url']) ?>" alt="">
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

                            <div class="post-card__body">
                                <?= nl2br(e(mb_substr((string)$r['description'], 0, 400))) ?><?= mb_strlen((string)$r['description']) > 400 ? '…' : '' ?>
                            </div>

                            <div class="activity-card__actions">
                                <button type="button" class="action js-like-btn <?= (int)$r['liked_by_me'] ? 'is-active' : '' ?>" data-kind="post" data-id="<?= $pid ?>">
                                    <span class="action__icon">♥</span><span class="js-like-count"><?= (int)$r['likes_count'] ?></span>
                                </button>
                                <a class="action" href="<?= e(url('post.php?id=' . $pid . '#comments')) ?>">
                                    <span class="action__icon">💬</span><span class="js-comment-count"><?= (int)$r['comments_count'] ?></span>
                                </a>
                                <a class="action" href="<?= e(url('post.php?id=' . $pid)) ?>">
                                    <span class="action__icon">📖</span><span>Читать</span>
                                </a>
                            </div>

                            <?php if ($postComments): ?>
                                <div class="post-card__comments">
                                    <?php foreach ($postComments as $c): ?>
                                        <div class="comment">
                                            <span class="avatar avatar--sm">
                                                <?php if (!empty($c['avatar_url'])): ?>
                                                    <img src="<?= e($c['avatar_url']) ?>" alt="">
                                                <?php else: ?>
                                                    <?= e(mb_substr((string)$c['display_name'], 0, 1)) ?>
                                                <?php endif; ?>
                                            </span>
                                            <div class="comment__body">
                                                <div class="comment__head">
                                                    <a href="<?= e(url('profile.php?u=' . urlencode((string)$c['username']))) ?>"><strong><?= e($c['display_name']) ?></strong></a>
                                                    <span class="comment__time muted"><?= e(time_ago((string)$c['created_at'])) ?></span>
                                                </div>
                                                <div><?= nl2br(e($c['body'])) ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <form class="comment-form js-comment-form" data-kind="post" data-id="<?= $pid ?>">
                                <input type="text" name="body" placeholder="Написать комментарий..." required maxlength="1000">
                                <button type="submit">Отправить</button>
                            </form>
                        </article>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div id="feed-more" class="feed-more" <?= !$hasMore ? 'style="display:none"' : '' ?>>
                <button type="button" id="feed-more-btn" class="btn btn--ghost btn--large">Смотреть ещё</button>
                <div id="feed-loader" class="feed-loader" style="display:none">
                    <span class="feed-loader__dot"></span>
                    <span class="feed-loader__dot"></span>
                    <span class="feed-loader__dot"></span>
                </div>
                <div id="feed-sentinel" style="height:1px"></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
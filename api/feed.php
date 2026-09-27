<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../models/Post.php';

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

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

    // ---- АКТИВНОСТИ ----
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

    // ---- ПОСТЫ ----
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

function feed_simplify_track(?string $trackJson): array
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
    if (count($points) > 200) {
        $step = (int)ceil(count($points) / 200);
        $out = [];
        for ($i = 0; $i < count($points); $i += $step) $out[] = $points[$i];
        if (end($out) !== end($points)) $out[] = end($points);
        return $out;
    }
    return $points;
}

function feed_type_icon(string $type): string
{
    return match ($type) {
        'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
        'walk' => '🚶', 'hike' => '🥾', default => '📦',
    };
}
function feed_type_label(string $type): string
{
    return match ($type) {
        'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
        'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
        default => 'Другое',
    };
}
function feed_pace_label(array $a): string
{
    if (($a['type'] ?? '') === 'ride' && !empty($a['avg_speed_mps'])) {
        return number_format((float)$a['avg_speed_mps'] * 3.6, 1, '.', '') . ' км/ч';
    }
    return format_pace((float)($a['distance_m'] ?? 0), (int)($a['duration_sec'] ?? 0));
}

// ============================================================
// ОСНОВНАЯ ЛОГИКА
// ============================================================

$me = api_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Method not allowed', 405);

$tab    = (string)($_GET['tab'] ?? 'all');
$type   = (string)($_GET['type'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

$allowedTabs = ['all', 'following', 'mine'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'all';

$allowedTypes = ['', 'run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
if (!in_array($type, $allowedTypes, true)) $type = '';

try {
    $rows = feed_fetch_unified((int)$me['id'], $tab, $type, $limit, $offset);

    $activityIds = [];
    $postIds     = [];
    foreach ($rows as $r) {
        if ($r['kind'] === 'activity') $activityIds[] = (int)$r['id'];
        else $postIds[] = (int)$r['id'];
    }

    // ---- Комментарии к активностям ----
    $commentsByActivity = [];
    if ($activityIds) {
        $ph = implode(',', array_fill(0, count($activityIds), '?'));
        $stmt = db()->prepare(
            "SELECT c.*, u.username, u.display_name, u.avatar_url
             FROM activity_comments c JOIN users u ON u.id = c.user_id
             WHERE c.activity_id IN ($ph)
             ORDER BY c.created_at ASC"
        );
        $stmt->execute($activityIds);
        foreach ($stmt->fetchAll() as $c) {
            $commentsByActivity[(int)$c['activity_id']][] = [
                'id'           => (int)$c['id'],
                'body'         => (string)$c['body'],
                'created_at'   => (string)$c['created_at'],
                'username'     => (string)$c['username'],
                'display_name' => (string)$c['display_name'],
                'avatar_url'   => $c['avatar_url'] ?: null,
                'initial'      => mb_substr((string)$c['display_name'], 0, 1),
                'time_ago'     => time_ago((string)$c['created_at']),
                'profile_url'  => url('profile.php?u=' . urlencode((string)$c['username'])),
                'can_delete'   => ($me && (int)$me['id'] === (int)$c['user_id']),
            ];
        }
    }

    // ---- Комментарии к постам ----
    $commentsByPost = [];
    if ($postIds) {
        $ph = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = db()->prepare(
            "SELECT c.*, u.username, u.display_name, u.avatar_url
             FROM post_comments c JOIN users u ON u.id = c.user_id
             WHERE c.post_id IN ($ph)
             ORDER BY c.created_at ASC"
        );
        $stmt->execute($postIds);
        foreach ($stmt->fetchAll() as $c) {
            $commentsByPost[(int)$c['post_id']][] = [
                'id'           => (int)$c['id'],
                'body'         => (string)$c['body'],
                'created_at'   => (string)$c['created_at'],
                'username'     => (string)$c['username'],
                'display_name' => (string)$c['display_name'],
                'avatar_url'   => $c['avatar_url'] ?: null,
                'initial'      => mb_substr((string)$c['display_name'], 0, 1),
                'time_ago'     => time_ago((string)$c['created_at']),
                'profile_url'  => url('profile.php?u=' . urlencode((string)$c['username'])),
                'can_delete'   => ($me && (int)$me['id'] === (int)$c['user_id']),
            ];
        }
    }

    // ---- Фото активностей (одним запросом) ----
    $photosByActivity = [];
    if ($activityIds) {
        $ph = implode(',', array_fill(0, count($activityIds), '?'));
        $stmt = db()->prepare(
            "SELECT * FROM activity_photos
             WHERE activity_id IN ($ph)
             ORDER BY activity_id ASC, order_index ASC, id ASC"
        );
        $stmt->execute($activityIds);
        foreach ($stmt->fetchAll() as $ph2) {
            $photosByActivity[(int)$ph2['activity_id']][] = [
                'id'  => (int)$ph2['id'],
                'url' => (string)$ph2['url'],
            ];
        }
    }

    // ---- Фото постов ----
    $photosByPost = [];
    if ($postIds) {
        $ph = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = db()->prepare(
            "SELECT * FROM post_photos WHERE post_id IN ($ph)
             ORDER BY post_id ASC, order_index ASC, id ASC"
        );
        $stmt->execute($postIds);
        foreach ($stmt->fetchAll() as $ph2) {
            $photosByPost[(int)$ph2['post_id']][] = ['url' => (string)$ph2['url']];
        }
    }

    // ---- Формируем ответ ----
    $items = [];
    foreach ($rows as $r) {
        $id     = (int)$r['id'];
        $kind   = (string)$r['kind'];
        $sortAt = (string)($r['sort_at'] ?? '');

        if ($kind === 'activity') {
            $items[] = [
                'kind'           => 'activity',
                'id'             => $id,
                'url'            => url('activity.php?id=' . $id),
                'title'          => (string)$r['title'],
                'description'    => (string)($r['description'] ?? ''),
                'created_at'     => $sortAt,
                'time_ago'       => time_ago($sortAt),
                'visibility'     => (string)$r['visibility'],
                'likes_count'    => (int)$r['likes_count'],
                'comments_count' => (int)$r['comments_count'],
                'liked_by_me'    => (bool)$r['liked_by_me'],
                'type'           => (string)$r['type'],
                'type_icon'      => feed_type_icon((string)$r['type']),
                'type_label'     => feed_type_label((string)$r['type']),
                'distance'       => format_distance((float)$r['distance_m']),
                'duration'       => format_duration((int)$r['duration_sec']),
                'pace_or_speed'  => feed_pace_label($r),
                'pace_label'     => ($r['type'] === 'ride') ? 'Средняя' : 'Темп',
                'elevation'      => $r['elevation_gain_m'] ? (int)$r['elevation_gain_m'] . ' м' : '—',
                'track'          => feed_simplify_track($r['track_json'] ?? null),
                'comments'       => $commentsByActivity[$id] ?? [],
                'photos'         => $photosByActivity[$id] ?? [],
                'author'         => [
                    'username'     => (string)$r['username'],
                    'display_name' => (string)$r['display_name'],
                    'avatar_url'   => $r['avatar_url'] ?: null,
                    'initial'      => mb_substr((string)$r['display_name'], 0, 1),
                    'profile_url'  => url('profile.php?u=' . urlencode((string)$r['username'])),
                ],
            ];
        } else {
            $items[] = [
                'kind'           => 'post',
                'id'             => $id,
                'url'            => url('post.php?id=' . $id),
                'title'          => (string)$r['title'],
                'description'    => (string)($r['description'] ?? ''),
                'created_at'     => $sortAt,
                'time_ago'       => time_ago($sortAt),
                'visibility'     => (string)$r['visibility'],
                'likes_count'    => (int)$r['likes_count'],
                'comments_count' => (int)$r['comments_count'],
                'liked_by_me'    => (bool)$r['liked_by_me'],
                'photos'         => $photosByPost[$id] ?? [],
                'comments'       => $commentsByPost[$id] ?? [],
                'author'         => [
                    'username'     => (string)$r['username'],
                    'display_name' => (string)$r['display_name'],
                    'avatar_url'   => $r['avatar_url'] ?: null,
                    'initial'      => mb_substr((string)$r['display_name'], 0, 1),
                    'profile_url'  => url('profile.php?u=' . urlencode((string)$r['username'])),
                ],
            ];
        }
    }

    json_ok([
        'items'    => $items,
        'page'     => $page,
        'limit'    => $limit,
        'has_more' => count($rows) === $limit,
    ]);

} catch (Throwable $e) {
    json_err('Ошибка загрузки: ' . $e->getMessage(), 500);
}
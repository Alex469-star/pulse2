<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Segment.php';

$me = api_require_user();
$userId = (int)$me['id'];

$tab  = (string)($_GET['tab'] ?? 'all');
$type = (string)($_GET['type'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$allowedTabs = ['all', 'following', 'mine'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'all';

$allowedTypes = ['', 'run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
if (!in_array($type, $allowedTypes, true)) $type = '';

$perPage = 50;
$offset  = ($page - 1) * $perPage;

// ---- Тянем активности и посты в одном UNION ----
$union = [];
$bindSets = ['act' => [], 'post' => []];

if ($tab === 'mine') {
    $whereActivity = ' AND a.user_id = :me_a';
    $wherePost     = ' AND p.user_id = :me_p';
    $bindSets['act']['me_a']  = $userId;
    $bindSets['post']['me_p'] = $userId;
} elseif ($tab === 'following') {
    $whereActivity = ' AND a.visibility IN ("public","followers")
                       AND a.user_id IN (SELECT following_id FROM follows WHERE follower_id = :fo_a)';
    $wherePost     = ' AND p.visibility IN ("public","followers")
                       AND p.user_id IN (SELECT following_id FROM follows WHERE follower_id = :fo_p)';
    $bindSets['act']['fo_a']  = $userId;
    $bindSets['post']['fo_p'] = $userId;
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
    $bindSets['act']['me_a']  = $userId;
    $bindSets['act']['fo_a']  = $userId;
    $bindSets['post']['me_p'] = $userId;
    $bindSets['post']['fo_p'] = $userId;
}

$aType = ($type !== '') ? $type : '';

$sqlA = 'SELECT
            "activity" AS kind,
            a.id AS id,
            a.user_id AS user_id,
            a.started_at AS started_at,
            a.created_at AS created_at,
            COALESCE(a.started_at, a.created_at) AS sort_at,
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
            NULL AS started_at,
            p.created_at AS created_at,
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

$sql = '(' . implode(') UNION ALL (', $union) . ') ORDER BY sort_at DESC, id DESC LIMIT :lim OFFSET :off';

try {
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':vid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':vid2', $userId, PDO::PARAM_INT);

    foreach ($bindSets['act'] as $name => $val) {
        $stmt->bindValue(':' . $name, $val, PDO::PARAM_INT);
    }
    foreach ($bindSets['post'] as $name => $val) {
        $stmt->bindValue(':' . $name, $val, PDO::PARAM_INT);
    }
    if ($aType !== '') $stmt->bindValue(':atype', $aType);

    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    json_err('Ошибка выборки: ' . $e->getMessage(), 500);
}

$activityIds = [];
$postIds = [];
foreach ($rows as $r) {
    if ($r['kind'] === 'activity') $activityIds[] = (int)$r['id'];
    else $postIds[] = (int)$r['id'];
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
    foreach ($stmt->fetchAll() as $ph2) $photosByPost[(int)$ph2['post_id']][] = $ph2;
}

// ---- Фото активностей ----
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

// ---- Комментарии к активностям ----
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

// ---- Комментарии к постам ----
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

// ---- Усилия по сегментам ----
$effortsAll = [];
if ($activityIds) {
    try {
        $effortsAll = Segment::effortsWithRanks($activityIds, $userId);
    } catch (Throwable $e) {
        $effortsAll = [];
    }
}

// ============================================================
// ФОРМИРУЕМ ITEMS
// ============================================================

$items = [];

foreach ($rows as $r) {
    if ($r['kind'] === 'activity') {
        $aid = (int)$r['id'];
        $actPhotos = $photosByActivity[$aid] ?? [];
        $list = $effortsAll[$aid] ?? [];

        $cnt = 0;
        foreach ($list as $eff) {
            $rank = $eff['rank'];
            $isTop3 = ($rank !== null && $rank >= 1 && $rank <= 3);
            if ($isTop3 || !empty($eff['is_pb'])) $cnt++;
        }

        // Трек (прореживаем до 200 точек, как в feed.php)
        $track = [];
        if (!empty($r['track_json'])) {
            $raw = json_decode((string)$r['track_json'], true);
            if (is_array($raw) && count($raw) >= 2) {
                foreach ($raw as $p) {
                    if (isset($p['lat'], $p['lng'])) {
                        $track[] = ['lat' => round((float)$p['lat'], 5), 'lng' => round((float)$p['lng'], 5)];
                    }
                }
                if (count($track) > 200) {
                    $step = (int)ceil(count($track) / 200);
                    $simp = [];
                    for ($i = 0; $i < count($track); $i += $step) $simp[] = $track[$i];
                    if (end($simp) !== end($track)) $simp[] = end($track);
                    $track = $simp;
                }
            }
        }

        // Дата начала
        $startedAt = $r['started_at'] ?? $r['created_at'] ?? null;
        $startedAtFormatted = '';
        if ($startedAt) {
            $ts = strtotime((string)$startedAt);
            if ($ts !== false) $startedAtFormatted = date('d.m.Y H:i', $ts);
        }

        // Дистанция/время/темп
        $dist = (float)($r['distance_m'] ?? 0);
        $dur  = (int)($r['duration_sec'] ?? 0);
        $avgSpeed = $r['avg_speed_mps'] !== null ? (float)$r['avg_speed_mps'] : null;

        // Форматирование
        $distanceFormatted = $dist > 0
            ? ($dist < 1000 ? round($dist) . ' м' : number_format($dist / 1000, 2, '.', ' ') . ' км')
            : '—';

        $durationFormatted = '—';
        if ($dur > 0) {
            $h = intdiv($dur, 3600);
            $m = intdiv($dur % 3600, 60);
            $s = $dur % 60;
            $durationFormatted = $h > 0
                ? sprintf('%d:%02d:%02d', $h, $m, $s)
                : sprintf('%d:%02d', $m, $s);
        }

        $paceOrSpeed = '—';
        $paceLabel = 'Темп';
        if ($r['type'] === 'ride' && $avgSpeed) {
            $paceOrSpeed = number_format($avgSpeed * 3.6, 1, '.', '') . ' км/ч';
            $paceLabel = 'Средняя';
        } elseif ($dist > 0 && $dur > 0) {
            $secPerKm = $dur / ($dist / 1000);
            $mm = intdiv((int)$secPerKm, 60);
            $ss = (int)$secPerKm % 60;
            $paceOrSpeed = sprintf('%d:%02d /км', $mm, $ss);
        }

        $elevationFormatted = !empty($r['elevation_gain_m'])
            ? (int)$r['elevation_gain_m'] . ' м'
            : '—';

        $items[] = [
            'kind'                 => 'activity',
            'id'                   => $aid,
            'url'                  => app_url('activity.php?id=' . $aid),
            'title'                => (string)$r['title'],
            'description'          => (string)($r['description'] ?? ''),
            'type_icon'            => activity_type_icon((string)$r['type']),
            'type_label'           => activity_type_label((string)$r['type']),
            'visibility'           => (string)$r['visibility'],
            'author'               => [
                'username'     => (string)$r['username'],
                'display_name' => (string)$r['display_name'],
                'avatar_url'   => $r['avatar_url'] ?: null,
                'initial'      => mb_substr((string)$r['display_name'], 0, 1),
                'profile_url'  => app_url('profile.php?u=' . urlencode((string)$r['username'])),
            ],
            'time_ago'             => time_ago_api((string)$r['sort_at']),
            'started_at_formatted' => $startedAtFormatted,
            'distance'             => $distanceFormatted,
            'duration'             => $durationFormatted,
            'pace_or_speed'        => $paceOrSpeed,
            'pace_label'           => $paceLabel,
            'elevation'            => $elevationFormatted,
            'likes_count'          => (int)$r['likes_count'],
            'comments_count'       => (int)$r['comments_count'],
            'liked_by_me'          => (bool)$r['liked_by_me'],
            'track'                => $track,
            'photos'               => array_map(function ($p) {
                return ['url' => (string)$p['url']];
            }, $actPhotos),
            'comments'             => array_map(function ($c) use ($userId) {
                return [
                    'id'           => (int)$c['id'],
                    'body'         => (string)$c['body'],
                    'display_name' => (string)$c['display_name'],
                    'username'     => (string)$c['username'],
                    'avatar_url'   => $c['avatar_url'] ?: null,
                    'initial'      => mb_substr((string)$c['display_name'], 0, 1),
                    'profile_url'  => app_url('profile.php?u=' . urlencode((string)$c['username'])),
                    'time_ago'     => time_ago_api((string)$c['created_at']),
                    'can_delete'   => ((int)$c['user_id'] === $userId),
                ];
            }, $commentsByActivity[$aid] ?? []),
            'effort_count'         => $cnt,
            'efforts'              => $list,
        ];
    } else {
        $pid = (int)$r['id'];
        $postPhotos = $photosByPost[$pid] ?? [];

        $items[] = [
            'kind'          => 'post',
            'id'            => $pid,
            'url'           => app_url('post.php?id=' . $pid),
            'title'         => (string)$r['title'],
            'description'   => (string)($r['description'] ?? ''),
            'visibility'    => (string)$r['visibility'],
            'author'        => [
                'username'     => (string)$r['username'],
                'display_name' => (string)$r['display_name'],
                'avatar_url'   => $r['avatar_url'] ?: null,
                'initial'      => mb_substr((string)$r['display_name'], 0, 1),
                'profile_url'  => app_url('profile.php?u=' . urlencode((string)$r['username'])),
            ],
            'time_ago'      => time_ago_api((string)$r['sort_at']),
            'likes_count'   => (int)$r['likes_count'],
            'comments_count'=> (int)$r['comments_count'],
            'liked_by_me'   => (bool)$r['liked_by_me'],
            'photos'        => array_map(function ($p) {
                return ['url' => (string)$p['url']];
            }, $postPhotos),
            'comments'      => array_map(function ($c) use ($userId) {
                return [
                    'id'           => (int)$c['id'],
                    'body'         => (string)$c['body'],
                    'display_name' => (string)$c['display_name'],
                    'username'     => (string)$c['username'],
                    'avatar_url'   => $c['avatar_url'] ?: null,
                    'initial'      => mb_substr((string)$c['display_name'], 0, 1),
                    'profile_url'  => app_url('profile.php?u=' . urlencode((string)$c['username'])),
                    'time_ago'     => time_ago_api((string)$c['created_at']),
                    'can_delete'   => ((int)$c['user_id'] === $userId),
                ];
            }, $commentsByPost[$pid] ?? []),
        ];
    }
}

json_ok([
    'items'    => $items,
    'has_more' => count($rows) === $perPage,
    'page'     => $page,
]);

// ============================================================
// ЛОКАЛЬНЫЕ ХЕЛПЕРЫ
// ============================================================

function activity_type_icon(string $t): string {
    return match ($t) {
        'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
        'walk' => '🚶', 'hike' => '🥾', default => '📦',
    };
}
function activity_type_label(string $t): string {
    return match ($t) {
        'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
        'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
        default => 'Другое',
    };
}
function time_ago_api(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false) return 'только что';
    $diff = time() - $ts;
    if ($diff < 60) return 'только что';
    if ($diff < 3600) return floor($diff / 60) . ' мин назад';
    if ($diff < 86400) return floor($diff / 3600) . ' ч назад';
    if ($diff < 2592000) return floor($diff / 86400) . ' дн назад';
    return date('d.m.Y', $ts);
}
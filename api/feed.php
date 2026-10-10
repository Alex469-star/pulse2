<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Segment.php';

$me = api_require_user();
$userId = (int)$me['id'];

$tab  = (string)($_GET['tab'] ?? 'all');
$type = (string)($_GET['type'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$allowedTabs = ['all', 'following', 'clubs', 'mine'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'all';

$allowedTypes = ['', 'run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
if (!in_array($type, $allowedTypes, true)) $type = '';

$perPage = 50;
$offset  = ($page - 1) * $perPage;

// ============================================================
// ЕДИНЫЙ ЛОАДЕР ЛЕНТЫ (all/following/mine)
// ============================================================
if (!function_exists('api_fetch_feed_unified')) {
    function api_fetch_feed_unified(int $userId, string $tab, string $type, int $limit, int $offset): array
    {
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

        // ---- Ветка 1: активности ----
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
                    (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id AND l.user_id = :vid) AS liked_by_me,
                    NULL AS club_id, NULL AS club_name, NULL AS club_slug,
                    NULL AS event_starts_at, NULL AS event_ends_at,
                    NULL AS event_location, NULL AS event_city,
                    NULL AS event_cover_url, NULL AS event_attendees_count,
                    NULL AS event_max_attendees, NULL AS event_status
                 FROM activities a
                 JOIN users u ON u.id = a.user_id
                 WHERE 1=1' . $whereActivity;
        if ($aType !== '') $sqlA .= ' AND a.type = :atype';
        $union[] = $sqlA;

        // ---- Ветка 2: посты ----
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
                    (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id AND l.user_id = :vid2) AS liked_by_me,
                    NULL AS club_id, NULL AS club_name, NULL AS club_slug,
                    NULL AS event_starts_at, NULL AS event_ends_at,
                    NULL AS event_location, NULL AS event_city,
                    NULL AS event_cover_url, NULL AS event_attendees_count,
                    NULL AS event_max_attendees, NULL AS event_status
                 FROM posts p
                 JOIN users u ON u.id = p.user_id
                 WHERE 1=1' . $wherePost;
        $union[] = $sqlP;

        // ---- Ветка 3: события клубов (только для tab=all) ----
        $bindEventUserId = null;
        if ($tab === 'all') {
            $bindEventUserId = $userId;

            $sqlE = 'SELECT
                        "club_event" AS kind,
                        e.id AS id,
                        e.creator_id AS user_id,
                        e.starts_at AS started_at,
                        e.created_at AS created_at,
                        COALESCE(e.starts_at, e.created_at) AS sort_at,
                        e.type AS type,
                        e.title AS title,
                        e.description AS description,
                        NULL AS distance_m,
                        NULL AS duration_sec,
                        NULL AS avg_speed_mps,
                        NULL AS elevation_gain_m,
                        NULL AS track_json,
                        "public" AS visibility,
                        u.username, u.display_name, u.avatar_url,
                        0 AS likes_count,
                        (SELECT COUNT(*) FROM club_event_comments ec WHERE ec.event_id = e.id) AS comments_count,
                        0 AS liked_by_me,
                        e.club_id AS club_id,
                        c.name AS club_name,
                        c.slug AS club_slug,
                        e.starts_at AS event_starts_at,
                        e.ends_at AS event_ends_at,
                        e.location AS event_location,
                        e.city AS event_city,
                        e.cover_url AS event_cover_url,
                        e.attendees_count AS event_attendees_count,
                        e.max_attendees AS event_max_attendees,
                        e.status AS event_status
                     FROM club_events e
                     JOIN users u ON u.id = e.creator_id
                     JOIN clubs c ON c.id = e.club_id
                     JOIN club_members mine
                          ON mine.club_id = e.club_id
                         AND mine.user_id = :mine_e
                         AND mine.status = "active"
                     WHERE e.status = "scheduled"
                       AND c.is_banned = 0
                       AND e.visibility IN ("public", "members")';
            $union[] = $sqlE;
        }

        $sql = '(' . implode(') UNION ALL (', $union) . ') ORDER BY sort_at DESC, id DESC LIMIT :lim OFFSET :off';

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':vid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':vid2', $userId, PDO::PARAM_INT);

        foreach ($bindSets['act'] as $name => $val) {
            $stmt->bindValue(':' . $name, $val, PDO::PARAM_INT);
        }
        foreach ($bindSets['post'] as $name => $val) {
            $stmt->bindValue(':' . $name, $val, PDO::PARAM_INT);
        }
        if ($bindEventUserId !== null) {
            $stmt->bindValue(':mine_e', $bindEventUserId, PDO::PARAM_INT);
        }
        if ($aType !== '') $stmt->bindValue(':atype', $aType);

        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

// ============================================================
// ЛОАДЕР ДЛЯ ТАБА "МОИ КЛУБЫ" (активности + посты + события)
// ============================================================
if (!function_exists('api_fetch_feed_clubs')) {
    function api_fetch_feed_clubs(int $userId, string $type, int $limit, int $offset): array
    {
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
                    (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id AND l.user_id = :vid) AS liked_by_me,
                    NULL AS club_id, NULL AS club_name, NULL AS club_slug,
                    NULL AS event_starts_at, NULL AS event_ends_at,
                    NULL AS event_location, NULL AS event_city,
                    NULL AS event_cover_url, NULL AS event_attendees_count,
                    NULL AS event_max_attendees, NULL AS event_status
                 FROM activities a
                 JOIN users u ON u.id = a.user_id
                 JOIN club_members cm ON cm.user_id = u.id AND cm.status = "active"
                 JOIN club_members mine
                      ON mine.club_id = cm.club_id
                     AND mine.user_id = :vid2
                     AND mine.status = "active"
                 WHERE a.visibility = "public"';
        if ($aType !== '') $sqlA .= ' AND a.type = :atype';

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
                    (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id AND l.user_id = :vid3) AS liked_by_me,
                    NULL AS club_id, NULL AS club_name, NULL AS club_slug,
                    NULL AS event_starts_at, NULL AS event_ends_at,
                    NULL AS event_location, NULL AS event_city,
                    NULL AS event_cover_url, NULL AS event_attendees_count,
                    NULL AS event_max_attendees, NULL AS event_status
                 FROM posts p
                 JOIN users u ON u.id = p.user_id
                 JOIN club_members cm ON cm.user_id = u.id AND cm.status = "active"
                 JOIN club_members mine
                      ON mine.club_id = cm.club_id
                     AND mine.user_id = :vid4
                     AND mine.status = "active"
                 WHERE p.visibility = "public"';

        $sqlE = 'SELECT
                    "club_event" AS kind,
                    e.id AS id,
                    e.creator_id AS user_id,
                    e.starts_at AS started_at,
                    e.created_at AS created_at,
                    COALESCE(e.starts_at, e.created_at) AS sort_at,
                    e.type AS type,
                    e.title AS title,
                    e.description AS description,
                    NULL AS distance_m,
                    NULL AS duration_sec,
                    NULL AS avg_speed_mps,
                    NULL AS elevation_gain_m,
                    NULL AS track_json,
                    "public" AS visibility,
                    u.username, u.display_name, u.avatar_url,
                    0 AS likes_count,
                    (SELECT COUNT(*) FROM club_event_comments ec WHERE ec.event_id = e.id) AS comments_count,
                    0 AS liked_by_me,
                    e.club_id AS club_id,
                    c.name AS club_name,
                    c.slug AS club_slug,
                    e.starts_at AS event_starts_at,
                    e.ends_at AS event_ends_at,
                    e.location AS event_location,
                    e.city AS event_city,
                    e.cover_url AS event_cover_url,
                    e.attendees_count AS event_attendees_count,
                    e.max_attendees AS event_max_attendees,
                    e.status AS event_status
                 FROM club_events e
                 JOIN users u ON u.id = e.creator_id
                 JOIN clubs c ON c.id = e.club_id
                 JOIN club_members mine
                      ON mine.club_id = e.club_id
                     AND mine.user_id = :vid5
                     AND mine.status = "active"
                 WHERE e.status = "scheduled"
                   AND c.is_banned = 0
                   AND e.visibility IN ("public", "members")';

        $sql = '(' . $sqlA . ') UNION ALL (' . $sqlP . ') UNION ALL (' . $sqlE . ')
                ORDER BY sort_at DESC, id DESC LIMIT :lim OFFSET :off';

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':vid',  $userId, PDO::PARAM_INT);
        $stmt->bindValue(':vid2', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':vid3', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':vid4', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':vid5', $userId, PDO::PARAM_INT);
        if ($aType !== '') $stmt->bindValue(':atype', $aType);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

// ---- Выборка ----
try {
    if ($tab === 'clubs') {
        $rows = api_fetch_feed_clubs($userId, $type, $perPage, $offset);
    } else {
        $rows = api_fetch_feed_unified($userId, $tab, $type, $perPage, $offset);
    }
} catch (Throwable $e) {
    json_err('Ошибка выборки: ' . $e->getMessage(), 500);
}

$activityIds = [];
$postIds     = [];
$eventIds    = [];
foreach ($rows as $r) {
    if ($r['kind'] === 'activity')       $activityIds[] = (int)$r['id'];
    elseif ($r['kind'] === 'club_event') $eventIds[]    = (int)$r['id'];
    else                                 $postIds[]     = (int)$r['id'];
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

// ---- Комментарии активностей (последние 2) ----
$commentsByActivity = [];
if ($activityIds) {
    $ph = implode(',', array_fill(0, count($activityIds), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM (
            SELECT c.*, u.username, u.display_name, u.avatar_url,
                   ROW_NUMBER() OVER (PARTITION BY c.activity_id ORDER BY c.created_at DESC) AS rn
              FROM activity_comments c
              JOIN users u ON u.id = c.user_id
             WHERE c.activity_id IN ($ph)
        ) t
        WHERE rn <= 2
        ORDER BY activity_id ASC, created_at ASC"
    );
    $stmt->execute($activityIds);
    foreach ($stmt->fetchAll() as $c) $commentsByActivity[(int)$c['activity_id']][] = $c;
}

// ---- Комментарии постов (последние 2) ----
$commentsByPost = [];
if ($postIds) {
    $ph = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM (
            SELECT c.*, u.username, u.display_name, u.avatar_url,
                   ROW_NUMBER() OVER (PARTITION BY c.post_id ORDER BY c.created_at DESC) AS rn
              FROM post_comments c
              JOIN users u ON u.id = c.user_id
             WHERE c.post_id IN ($ph)
        ) t
        WHERE rn <= 2
        ORDER BY post_id ASC, created_at ASC"
    );
    $stmt->execute($postIds);
    foreach ($stmt->fetchAll() as $c) $commentsByPost[(int)$c['post_id']][] = $c;
}

// ---- Комментарии событий (последние 2) ----
$commentsByEvent = [];
if ($eventIds) {
    $ph = implode(',', array_fill(0, count($eventIds), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM (
            SELECT c.*, u.username, u.display_name, u.avatar_url,
                   ROW_NUMBER() OVER (PARTITION BY c.event_id ORDER BY c.created_at DESC) AS rn
              FROM club_event_comments c
              JOIN users u ON u.id = c.user_id
             WHERE c.event_id IN ($ph)
        ) t
        WHERE rn <= 2
        ORDER BY event_id ASC, created_at ASC"
    );
    $stmt->execute($eventIds);
    foreach ($stmt->fetchAll() as $c) $commentsByEvent[(int)$c['event_id']][] = $c;
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

        $startedAt = $r['started_at'] ?? $r['created_at'] ?? null;
        $startedAtFormatted = '';
        if ($startedAt) {
            $ts = strtotime((string)$startedAt);
            if ($ts !== false) $startedAtFormatted = date('d.m.Y H:i', $ts);
        }

        $dist = (float)($r['distance_m'] ?? 0);
        $dur  = (int)($r['duration_sec'] ?? 0);
        $avgSpeed = $r['avg_speed_mps'] !== null ? (float)$r['avg_speed_mps'] : null;

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

    } elseif ($r['kind'] === 'club_event') {
        $eid = (int)$r['id'];
        $startsAtFormatted = '';
        if (!empty($r['event_starts_at'])) {
            $ts = strtotime((string)$r['event_starts_at']);
            if ($ts !== false) $startsAtFormatted = date('d.m.Y H:i', $ts);
        }

        $items[] = [
            'kind'                 => 'club_event',
            'id'                   => $eid,
            'url'                  => app_url('club-event.php?id=' . $eid),
            'title'                => (string)$r['title'],
            'description'          => (string)($r['description'] ?? ''),
            'type_icon'            => event_type_icon((string)$r['type']),
            'type_label'           => event_type_label((string)$r['type']),
            'club'                 => [
                'id'       => (int)$r['club_id'],
                'name'     => (string)$r['club_name'],
                'slug'     => (string)$r['club_slug'],
                'url'      => app_url('club.php?id=' . (int)$r['club_id']),
            ],
            'author'               => [
                'username'     => (string)$r['username'],
                'display_name' => (string)$r['display_name'],
                'avatar_url'   => $r['avatar_url'] ?: null,
                'initial'      => mb_substr((string)$r['display_name'], 0, 1),
                'profile_url'  => app_url('profile.php?u=' . urlencode((string)$r['username'])),
            ],
            'time_ago'             => time_ago_api((string)$r['sort_at']),
            'starts_at_formatted'  => $startsAtFormatted,
            'city'                 => $r['event_city'] ?: null,
            'location'             => $r['event_location'] ?: null,
            'cover_url'            => $r['event_cover_url'] ?: null,
            'attendees_count'      => (int)$r['event_attendees_count'],
            'max_attendees'        => $r['event_max_attendees'] !== null ? (int)$r['event_max_attendees'] : null,
            'status'               => (string)$r['event_status'],
            'comments_count'       => (int)$r['comments_count'],
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
            }, $commentsByEvent[$eid] ?? []),
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
function event_type_icon(string $t): string {
    return match ($t) {
        'training' => '🏃', 'race' => '🏆', 'meeting' => '☕',
        default => '📅',
    };
}
function event_type_label(string $t): string {
    return match ($t) {
        'training' => 'Тренировка', 'race' => 'Соревнование',
        'meeting' => 'Встреча', default => 'Событие',
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
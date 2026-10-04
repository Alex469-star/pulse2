<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class Segment
{
    // ============================================================
    // СОЗДАНИЕ / ЧТЕНИЕ / УДАЛЕНИЕ
    // ============================================================

    public static function create(int $creatorId, array $d): int
    {
        $s = db()->prepare(
            'INSERT INTO segments
                (creator_id, name, type, distance_m, elevation_gain_m, track_json, is_public)
             VALUES
                (:creator_id, :name, :type, :distance_m, :elevation_gain_m, :track_json, :is_public)'
        );
        $s->execute([
            ':creator_id'       => $creatorId,
            ':name'             => $d['name'] ?? 'Сегмент',
            ':type'             => $d['type'] ?? 'run',
            ':distance_m'       => $d['distance_m'] ?? 0,
            ':elevation_gain_m' => $d['elevation_gain_m'] ?? null,
            ':track_json'       => $d['track_json'] ?? null,
            ':is_public'        => $d['is_public'] ?? 1,
        ]);
        return (int)db()->lastInsertId();
    }

    public static function findById(int $id): ?array
    {
        $s = db()->prepare(
            'SELECT s.*, u.username, u.display_name, u.avatar_url
             FROM segments s
             JOIN users u ON u.id = s.creator_id
             WHERE s.id = ? LIMIT 1'
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function byUser(int $userId, int $limit = 30, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT s.*,
                (SELECT COUNT(DISTINCT user_id) FROM segment_efforts e WHERE e.segment_id = s.id) AS athletes,
                (SELECT COUNT(*) FROM segment_efforts e WHERE e.segment_id = s.id) AS efforts
             FROM segments s
             WHERE s.creator_id = ?
             ORDER BY s.created_at DESC
             LIMIT ? OFFSET ?'
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit,  PDO::PARAM_INT);
        $s->bindValue(3, $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function publicList(string $type = '', string $sort = 'new', int $limit = 50): array
    {
        $sql = 'SELECT s.*, u.username, u.display_name,
                    (SELECT COUNT(DISTINCT user_id) FROM segment_efforts e WHERE e.segment_id = s.id) AS athletes,
                    (SELECT COUNT(*) FROM segment_efforts e WHERE e.segment_id = s.id) AS efforts
                FROM segments s
                JOIN users u ON u.id = s.creator_id
                WHERE s.is_public = 1';
        $params = [];

        if ($type !== '') {
            $sql .= ' AND s.type = :type';
            $params[':type'] = $type;
        }

        switch ($sort) {
            case 'popular': $sql .= ' ORDER BY athletes DESC, s.created_at DESC'; break;
            case 'longest': $sql .= ' ORDER BY s.distance_m DESC'; break;
            default:        $sql .= ' ORDER BY s.created_at DESC';
        }

        $sql .= ' LIMIT :lim';

        $stmt = db()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function delete(int $id, int $userId): void
    {
        db()->prepare('DELETE FROM segments WHERE id = ? AND creator_id = ?')
            ->execute([$id, $userId]);
    }

    // ============================================================
    // ПОИСК ПЕРЕСЕЧЕНИЙ
    // ============================================================

    public static function findOverlapping(
        string $type,
        array $track,
        float $radiusM = 50,
        float $lengthTolerance = 0.25
    ): ?array {
        if (count($track) < 2) return null;

        $newStart  = $track[0];
        $newFinish = $track[count($track) - 1];

        if (!isset($newStart['lat'], $newStart['lng'], $newFinish['lat'], $newFinish['lng'])) {
            return null;
        }

        $newLength = self::trackLength($track);
        if ($newLength <= 0) return null;

        $newAnchors = self::anchors($track);

        try {
            $s = db()->prepare(
                'SELECT id, name, type, distance_m, track_json, creator_id, created_at
                 FROM segments
                 WHERE type = ?
                 LIMIT 500'
            );
            $s->execute([$type]);
            $segments = $s->fetchAll();
        } catch (Throwable $e) {
            return null;
        }

        foreach ($segments as $seg) {
            $segTrack = self::parseTrackJson((string)$seg['track_json']);
            if (count($segTrack) < 2) continue;

            $segLength = self::trackLength($segTrack);
            if ($segLength <= 0) continue;

            $ratio = $newLength / $segLength;
            if ($ratio < (1 - $lengthTolerance) || $ratio > (1 + $lengthTolerance)) {
                continue;
            }

            $segAnchors = self::anchors($segTrack);

            $direct = self::anchorsMatch($newAnchors, $segAnchors, $radiusM);
            $reverseAnchors = array_reverse($segAnchors);
            $reverse = self::anchorsMatch($newAnchors, $reverseAnchors, $radiusM);

            if ($direct || $reverse) {
                $seg['_match_direction'] = $direct ? 'direct' : 'reverse';
                return $seg;
            }
        }

        return null;
    }

    private static function anchors(array $track): array
    {
        $n = count($track);
        if ($n < 2) return $track;

        $indices = [
            0,
            (int)round($n * 0.25),
            (int)round($n * 0.50),
            (int)round($n * 0.75),
            $n - 1,
        ];

        $out = [];
        foreach ($indices as $i) {
            $i = max(0, min($n - 1, $i));
            $out[] = $track[$i];
        }
        return $out;
    }

    private static function anchorsMatch(array $newAnchors, array $segAnchors, float $radiusM): bool
    {
        if (count($newAnchors) !== count($segAnchors)) return false;

        foreach ($newAnchors as $i => $a) {
            $b = $segAnchors[$i];
            if (!isset($a['lat'], $a['lng'], $b['lat'], $b['lng'])) return false;

            $d = self::haversine(
                (float)$a['lat'], (float)$a['lng'],
                (float)$b['lat'], (float)$b['lng']
            );
            if ($d > $radiusM) return false;
        }

        return true;
    }

    public static function parseTrackJson(string $json): array
    {
        if ($json === '') return [];
        $raw = json_decode($json, true);
        if (!is_array($raw)) return [];

        $points = [];
        foreach ($raw as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            $lat = (float)$p['lat'];
            $lng = (float)$p['lng'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;

            $points[] = [
                'lat' => $lat,
                'lng' => $lng,
                'ele' => isset($p['ele']) ? (float)$p['ele'] : null,
                't'   => isset($p['t'])   ? (int)$p['t']     : null,
            ];
        }
        return $points;
    }

    private static function trackLength(array $track): float
    {
        $d = 0.0;
        for ($i = 1; $i < count($track); $i++) {
            $a = $track[$i - 1];
            $b = $track[$i];
            if (!isset($a['lat'], $a['lng'], $b['lat'], $b['lng'])) continue;
            $d += self::haversine(
                (float)$a['lat'], (float)$a['lng'],
                (float)$b['lat'], (float)$b['lng']
            );
        }
        return $d;
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $R * asin(min(1.0, sqrt($a)));
    }

    // ============================================================
    // УСИЛИЯ (EFFORTS)
    // ============================================================

    public static function addEffort(
        int $segmentId,
        int $activityId,
        int $userId,
        int $elapsedSec,
        ?string $startedAt,
        bool $isAuto = false,
        ?float $matchedDistance = null,
        ?int $quality = null
    ): int {
        $s = db()->prepare(
            'INSERT INTO segment_efforts
                (segment_id, activity_id, user_id, elapsed_time_sec, is_auto,
                 matched_distance_m, match_quality, started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                elapsed_time_sec   = VALUES(elapsed_time_sec),
                matched_distance_m = VALUES(matched_distance_m),
                match_quality      = VALUES(match_quality),
                started_at         = VALUES(started_at)'
        );
        $s->execute([
            $segmentId, $activityId, $userId, $elapsedSec,
            $isAuto ? 1 : 0,
            $matchedDistance, $quality, $startedAt
        ]);
        return (int)db()->lastInsertId();
    }

    public static function effortsForActivity(int $activityId): array
    {
        $s = db()->prepare(
            'SELECT e.*, s.name, s.type, s.distance_m
             FROM segment_efforts e
             JOIN segments s ON s.id = e.segment_id
             WHERE e.activity_id = ?
             ORDER BY e.elapsed_time_sec ASC'
        );
        $s->execute([$activityId]);
        return $s->fetchAll();
    }

    public static function effortsWithRanks(array $activityIds, int $userId): array
    {
        $activityIds = array_values(array_filter(array_map('intval', $activityIds)));
        if (!$activityIds) return [];

        $ph = implode(',', array_fill(0, count($activityIds), '?'));

        $sql = "
            SELECT e.id, e.activity_id, e.segment_id, e.elapsed_time_sec,
                   e.is_auto, e.match_quality, e.started_at,
                   s.name AS segment_name, s.distance_m AS segment_distance
            FROM segment_efforts e
            JOIN segments s ON s.id = e.segment_id
            WHERE e.activity_id IN ($ph) AND e.user_id = ?
            ORDER BY e.activity_id ASC, e.elapsed_time_sec ASC
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge($activityIds, [$userId]));
        $efforts = $stmt->fetchAll();
        if (!$efforts) return [];

        $segmentIds = array_values(array_unique(array_map(function ($e) {
            return (int)$e['segment_id'];
        }, $efforts)));
        if (!$segmentIds) return [];

        $sph = implode(',', array_fill(0, count($segmentIds), '?'));

        $ownStmt = db()->prepare(
            "SELECT segment_id,
                    MIN(elapsed_time_sec) AS best,
                    COUNT(*) AS cnt
             FROM segment_efforts
             WHERE user_id = ? AND segment_id IN ($sph)
             GROUP BY segment_id"
        );
        $ownStmt->execute(array_merge([$userId], $segmentIds));

        $ownBest = [];
        $ownCount = [];
        foreach ($ownStmt->fetchAll() as $row) {
            $ownBest[(int)$row['segment_id']]  = (int)$row['best'];
            $ownCount[(int)$row['segment_id']] = (int)$row['cnt'];
        }

        $ranks = self::userRanksForSegments($segmentIds, $userId);

        $out = [];
        foreach ($efforts as $e) {
            $aid = (int)$e['activity_id'];
            $sid = (int)$e['segment_id'];
            $elapsed = (int)$e['elapsed_time_sec'];
            $best = $ownBest[$sid] ?? $elapsed;
            $cnt  = $ownCount[$sid] ?? 1;

            $isPb = ($cnt > 1 && $best === $elapsed);

            $out[$aid][] = [
                'segment_id'       => $sid,
                'segment_name'     => (string)$e['segment_name'],
                'segment_distance' => (float)$e['segment_distance'],
                'elapsed_time_sec' => $elapsed,
                'rank'             => $ranks[$sid] ?? null,
                'is_pb'            => $isPb,
                'is_auto'          => (int)$e['is_auto'] === 1,
                'match_quality'    => $e['match_quality'] !== null ? (int)$e['match_quality'] : null,
            ];
        }
        return $out;
    }

    public static function leaderboard(int $segmentId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $sql = '
            SELECT
                u.id AS user_id,
                u.username,
                u.display_name,
                u.avatar_url,
                MIN(e.elapsed_time_sec) AS best_time,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(e.activity_id ORDER BY e.elapsed_time_sec ASC),
                    ",", 1
                ) AS activity_id,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(e.started_at ORDER BY e.elapsed_time_sec ASC),
                    ",", 1
                ) AS started_at
            FROM segment_efforts e
            JOIN users u ON u.id = e.user_id
            WHERE e.segment_id = :sid
            GROUP BY u.id, u.username, u.display_name, u.avatar_url
            ORDER BY best_time ASC
            LIMIT ' . $limit;

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':sid', $segmentId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $segment = self::findById($segmentId);
        $distance = $segment ? (float)$segment['distance_m'] : 0.0;

        foreach ($rows as &$r) {
            $r['activity_id']  = (int)$r['activity_id'];
            $r['best_time']    = (int)$r['best_time'];
            $r['avg_speed_mps'] = ($distance > 0 && $r['best_time'] > 0)
                ? $distance / $r['best_time']
                : null;
        }
        unset($r);

        return $rows;
    }

    public static function userRank(int $segmentId, int $userId): ?int
    {
        $best = self::userBestEffort($segmentId, $userId);
        if (!$best) return null;

        $s = db()->prepare(
            'SELECT COUNT(*) + 1
             FROM (
                SELECT MIN(elapsed_time_sec) AS best
                FROM segment_efforts
                WHERE segment_id = ?
                GROUP BY user_id
             ) t
             WHERE t.best < ?'
        );
        $s->execute([$segmentId, (int)$best['elapsed_time_sec']]);
        return (int)$s->fetchColumn();
    }

    public static function userRanksForSegments(array $segmentIds, int $userId): array
    {
        $segmentIds = array_values(array_filter(array_map('intval', $segmentIds)));
        if (!$segmentIds) return [];

        $ph = implode(',', array_fill(0, count($segmentIds), '?'));

        $sql = "
            SELECT segment_id, MIN(elapsed_time_sec) AS best
            FROM segment_efforts
            WHERE user_id = ? AND segment_id IN ($ph)
            GROUP BY segment_id
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge([$userId], $segmentIds));

        $myBest = [];
        foreach ($stmt->fetchAll() as $row) {
            $myBest[(int)$row['segment_id']] = (int)$row['best'];
        }
        if (!$myBest) return [];

        $ranks = [];
        $rankStmt = db()->prepare(
            'SELECT COUNT(*) + 1
             FROM (
                SELECT MIN(elapsed_time_sec) AS best
                FROM segment_efforts
                WHERE segment_id = ?
                GROUP BY user_id
             ) t
             WHERE t.best < ?'
        );

        foreach ($myBest as $segmentId => $bestTime) {
            $rankStmt->execute([$segmentId, $bestTime]);
            $ranks[$segmentId] = (int)$rankStmt->fetchColumn();
        }

        return $ranks;
    }

    public static function bestTime(int $segmentId): ?int
    {
        $s = db()->prepare(
            'SELECT MIN(elapsed_time_sec)
             FROM segment_efforts
             WHERE segment_id = ?'
        );
        $s->execute([$segmentId]);
        $val = $s->fetchColumn();
        return $val !== false && $val !== null ? (int)$val : null;
    }

    public static function bestTimesForSegments(array $segmentIds): array
    {
        $segmentIds = array_values(array_filter(array_map('intval', $segmentIds)));
        if (!$segmentIds) return [];

        $ph = implode(',', array_fill(0, count($segmentIds), '?'));

        $sql = "SELECT segment_id, MIN(elapsed_time_sec) AS best
                FROM segment_efforts
                WHERE segment_id IN ($ph)
                GROUP BY segment_id";

        $stmt = db()->prepare($sql);
        $stmt->execute($segmentIds);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['segment_id']] = (int)$row['best'];
        }
        return $out;
    }

    public static function userBestEffort(int $segmentId, int $userId): ?array
    {
        $s = db()->prepare(
            'SELECT * FROM segment_efforts
             WHERE segment_id = ? AND user_id = ?
             ORDER BY elapsed_time_sec ASC, id ASC
             LIMIT 1'
        );
        $s->execute([$segmentId, $userId]);
        return $s->fetch() ?: null;
    }

    public static function personalBest(int $segmentId, int $userId): ?array
    {
        return self::userBestEffort($segmentId, $userId);
    }

    public static function stats(int $segmentId): array
    {
        $s = db()->prepare(
            'SELECT COUNT(*) AS efforts, COUNT(DISTINCT user_id) AS athletes
             FROM segment_efforts WHERE segment_id = ?'
        );
        $s->execute([$segmentId]);
        $row = $s->fetch() ?: ['efforts' => 0, 'athletes' => 0];
        return [
            'efforts'  => (int)$row['efforts'],
            'athletes' => (int)$row['athletes'],
        ];
    }

    // ============================================================
    // ЛИДЕРСТВО (для уведомлений)
    // ============================================================

    /**
     * Кто сейчас лидер сегмента — по данным segment_efforts.
     */
    public static function currentLeader(int $segmentId): ?int
    {
        $s = db()->prepare(
            'SELECT user_id
             FROM segment_efforts
             WHERE segment_id = ?
             ORDER BY elapsed_time_sec ASC, id ASC
             LIMIT 1'
        );
        $s->execute([$segmentId]);
        $uid = $s->fetchColumn();
        return $uid !== false ? (int)$uid : null;
    }

    /**
     * Сохранённый в БД лидер сегмента (кто был лидером на прошлой итерации).
     */
    private static function storedLeader(int $segmentId): ?int
    {
        $s = db()->prepare('SELECT current_leader_id FROM segments WHERE id = ? LIMIT 1');
        $s->execute([$segmentId]);
        $val = $s->fetchColumn();
        return $val !== false && $val !== null ? (int)$val : null;
    }

    /**
     * Обновляет сохранённого лидера в БД.
     */
    private static function saveStoredLeader(int $segmentId, ?int $leaderId): void
    {
        try {
            db()->prepare('UPDATE segments SET current_leader_id = ? WHERE id = ?')
                ->execute([$leaderId, $segmentId]);
        } catch (Throwable $e) {
            log_to_file('segment-match.log', 'saveStoredLeader failed for #' . $segmentId . ': ' . $e->getMessage());
        }
    }

    /**
     * Уведомляет о смене лидера ТОЛЬКО если лидер реально изменился
     * по сравнению с сохранённым в segments.current_leader_id.
     *
     * Вызывается после матчинга. Никаких внешних аргументов не нужно —
     * метод сам сходит в БД за старым значением и обновит его.
     */
    public static function notifyLeadershipChange(int $segmentId): void
    {
        $newLeaderId = self::currentLeader($segmentId);
        $oldLeaderId = self::storedLeader($segmentId);

        // Если лидер не изменился — молча выходим
        if ($oldLeaderId === $newLeaderId) {
            return;
        }

        // Сохраняем новое значение в БД (даже если новый лидер = null)
        self::saveStoredLeader($segmentId, $newLeaderId);

        // Если лидера вообще нет — уведомлять некого
        if ($newLeaderId === null) return;

        $segment = self::findById($segmentId);
        if (!$segment) return;

        // Уведомляем нового лидера
        try {
            Notification::push(
                $newLeaderId,
                'segment_new_lead',
                null,
                'segment',
                $segmentId,
                'Вы стали лидером сегмента «' . $segment['name'] . '»'
            );
        } catch (Throwable $e) {}

        // Уведомляем старого лидера о потере
        if ($oldLeaderId !== null) {
            try {
                Notification::push(
                    $oldLeaderId,
                    'segment_lost_lead',
                    $newLeaderId,
                    'segment',
                    $segmentId,
                    'Вы потеряли лидерство на сегменте «' . $segment['name'] . '»'
                );
            } catch (Throwable $e) {}
        }
    }
}
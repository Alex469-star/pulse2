<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class Segment
{
    private static ?bool $hasAutoCols = null;

    // ============================================================
    // СОЗДАНИЕ И ЧТЕНИЕ
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
            ':name'             => $d['name'],
            ':type'             => $d['type'] ?? 'run',
            ':distance_m'       => $d['distance_m'] ?? 0,
            ':elevation_gain_m' => $d['elevation_gain_m'] ?? null,
            ':track_json'       => $d['track_json'],
            ':is_public'        => isset($d['is_public']) ? (int)$d['is_public'] : 1,
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

    public static function allPublic(int $limit = 50, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT s.*, u.username, u.display_name,
                    (SELECT COUNT(DISTINCT user_id) FROM segment_efforts e
                     WHERE e.segment_id = s.id) AS athletes,
                    (SELECT COUNT(*) FROM segment_efforts e
                     WHERE e.segment_id = s.id) AS efforts
             FROM segments s
             JOIN users u ON u.id = s.creator_id
             WHERE s.is_public = 1
             ORDER BY s.created_at DESC
             LIMIT :lim OFFSET :off'
        );
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->bindValue(':off', $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function allPublicByType(string $type, int $limit = 50): array
    {
        $allowed = ['run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
        if (!in_array($type, $allowed, true)) return self::allPublic($limit);

        $s = db()->prepare(
            'SELECT s.*, u.username, u.display_name,
                    (SELECT COUNT(DISTINCT user_id) FROM segment_efforts e
                     WHERE e.segment_id = s.id) AS athletes,
                    (SELECT COUNT(*) FROM segment_efforts e
                     WHERE e.segment_id = s.id) AS efforts
             FROM segments s
             JOIN users u ON u.id = s.creator_id
             WHERE s.is_public = 1 AND s.type = :type
             ORDER BY s.created_at DESC
             LIMIT :lim'
        );
        $s->bindValue(':type', $type);
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function byUser(int $userId, int $limit = 100): array
    {
        $s = db()->prepare(
            'SELECT s.*,
                    (SELECT COUNT(DISTINCT user_id) FROM segment_efforts e
                     WHERE e.segment_id = s.id) AS athletes,
                    (SELECT COUNT(*) FROM segment_efforts e
                     WHERE e.segment_id = s.id) AS efforts
             FROM segments s
             WHERE s.creator_id = ?
             ORDER BY s.created_at DESC
             LIMIT ?'
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    // ============================================================
    // ЛИДЕРБОРД
    // ============================================================

    /**
     * Лидерборд сегмента.
     *
     * Возвращает для каждого пользователя его ЛУЧШЕЕ усилие
     * вместе с activity_id и id самого усилия.
     *
     * Совместимо с ONLY_FULL_GROUP_BY: использует оконную функцию
     * ROW_NUMBER(), а не GROUP BY. Требует MySQL 8.0+ или MariaDB 10.2+.
     *
     * Поля в результате:
     *  - effort_id
     *  - user_id
     *  - activity_id   (ID активности, где показано это время)
     *  - best_time     (в секундах)
     *  - effort_started_at
     *  - effort_created_at
     *  - is_auto, match_quality (0/NULL, если колонок нет)
     *  - username, display_name, avatar_url
     */
        /**
     * Лидерборд сегмента.
     * Возвращает для каждого пользователя его ЛУЧШЕЕ усилие
     * вместе с activity_id и средней скоростью.
     */
    public static function leaderboard(int $segmentId, int $limit = 100): array
    {
        $hasAuto = self::hasAutoCols();

        $autoCols = $hasAuto
            ? ', e.is_auto, e.match_quality'
            : ', 0 AS is_auto, NULL AS match_quality';

        $sql = "
            SELECT
                ranked.effort_id,
                ranked.user_id,
                ranked.activity_id,
                ranked.best_time,
                ranked.effort_started_at,
                ranked.effort_created_at,
                ranked.is_auto,
                ranked.match_quality,
                u.username,
                u.display_name,
                u.avatar_url,
                s.distance_m AS segment_distance_m
            FROM (
                SELECT
                    e.id               AS effort_id,
                    e.user_id          AS user_id,
                    e.activity_id      AS activity_id,
                    e.elapsed_time_sec AS best_time,
                    e.started_at       AS effort_started_at,
                    e.created_at       AS effort_created_at
                    {$autoCols},
                    ROW_NUMBER() OVER (
                        PARTITION BY e.user_id
                        ORDER BY e.elapsed_time_sec ASC, e.created_at ASC
                    ) AS rn
                FROM segment_efforts e
                WHERE e.segment_id = :seg
            ) AS ranked
            JOIN users u ON u.id = ranked.user_id
            JOIN segments s ON s.id = :seg2
            WHERE ranked.rn = 1
            ORDER BY ranked.best_time ASC
            LIMIT :lim
        ";

        $s = db()->prepare($sql);
        $s->bindValue(':seg',  $segmentId, PDO::PARAM_INT);
        $s->bindValue(':seg2', $segmentId, PDO::PARAM_INT);
        $s->bindValue(':lim',  $limit, PDO::PARAM_INT);
        $s->execute();
        $rows = $s->fetchAll();

        // Считаем среднюю скорость в PHP — точнее, чем в SQL
        foreach ($rows as &$row) {
            $dist = (float)$row['segment_distance_m'];
            $time = (int)$row['best_time'];
            $row['avg_speed_mps'] = ($time > 0 && $dist > 0) ? $dist / $time : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Текущий лидер сегмента (лучший результат).
     */
    public static function currentLeader(int $segmentId): ?array
    {
        $s = db()->prepare(
            'SELECT e.user_id, e.activity_id, e.elapsed_time_sec AS best_time,
                    u.username, u.display_name
             FROM segment_efforts e
             JOIN users u ON u.id = e.user_id
             WHERE e.segment_id = ?
             ORDER BY e.elapsed_time_sec ASC, e.created_at ASC
             LIMIT 1'
        );
        $s->execute([$segmentId]);
        return $s->fetch() ?: null;
    }

    /**
     * Позиция пользователя в лидерборде сегмента.
     */
    public static function userRank(int $segmentId, int $userId): ?int
    {
        $check = db()->prepare(
            'SELECT 1 FROM segment_efforts
             WHERE segment_id = ? AND user_id = ? LIMIT 1'
        );
        $check->execute([$segmentId, $userId]);
        if (!$check->fetchColumn()) return null;

        $s = db()->prepare(
            'SELECT COUNT(*) + 1
             FROM (
                 SELECT MIN(elapsed_time_sec) AS best
                 FROM segment_efforts
                 WHERE segment_id = ?
                 GROUP BY user_id
             ) AS board
             WHERE board.best < (
                 SELECT MIN(elapsed_time_sec)
                 FROM segment_efforts
                 WHERE segment_id = ? AND user_id = ?
             )'
        );
        $s->execute([$segmentId, $segmentId, $userId]);
        return (int)$s->fetchColumn();
    }

    /**
     * Лучшее усилие пользователя по сегменту (с activity_id).
     */
    public static function userBestEffort(int $segmentId, int $userId): ?array
    {
        $s = db()->prepare(
            'SELECT e.*, s.name AS segment_name
             FROM segment_efforts e
             JOIN segments s ON s.id = e.segment_id
             WHERE e.segment_id = ? AND e.user_id = ?
             ORDER BY e.elapsed_time_sec ASC, e.created_at ASC
             LIMIT 1'
        );
        $s->execute([$segmentId, $userId]);
        return $s->fetch() ?: null;
    }

    public static function effortsForActivity(int $activityId): array
    {
        $hasAuto = self::hasAutoCols();
        $cols = 'e.*, s.name, s.distance_m, s.type';
        if ($hasAuto) $cols .= ', e.is_auto, e.match_quality';

        $s = db()->prepare(
            "SELECT $cols
             FROM segment_efforts e
             JOIN segments s ON s.id = e.segment_id
             WHERE e.activity_id = ?
             ORDER BY e.elapsed_time_sec ASC"
        );
        $s->execute([$activityId]);
        return $s->fetchAll();
    }

    public static function effortsForUser(int $userId, int $limit = 50): array
    {
        $s = db()->prepare(
            'SELECT e.*, s.name, s.distance_m, s.type, s.id AS segment_id
             FROM segment_efforts e
             JOIN segments s ON s.id = e.segment_id
             WHERE e.user_id = ?
             ORDER BY e.created_at DESC
             LIMIT ?'
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    // ============================================================
    // ДОБАВЛЕНИЕ УСИЛИЙ
    // ============================================================

    public static function addEffort(
        int $segmentId,
        int $activityId,
        int $userId,
        int $elapsedSec,
        ?string $startedAt = null,
        bool $notify = true
    ): void {
        $hasAuto = self::hasAutoCols();
        $previousLeader = $notify ? self::currentLeader($segmentId) : null;

        if ($hasAuto) {
            $s = db()->prepare(
                'INSERT INTO segment_efforts
                    (segment_id, activity_id, user_id, elapsed_time_sec, is_auto, started_at)
                 VALUES (?, ?, ?, ?, 0, ?)
                 ON DUPLICATE KEY UPDATE
                    elapsed_time_sec = VALUES(elapsed_time_sec),
                    is_auto          = 0,
                    started_at       = VALUES(started_at)'
            );
            $s->execute([$segmentId, $activityId, $userId, $elapsedSec, $startedAt]);
        } else {
            $s = db()->prepare(
                'INSERT INTO segment_efforts
                    (segment_id, activity_id, user_id, elapsed_time_sec, started_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE elapsed_time_sec = VALUES(elapsed_time_sec)'
            );
            $s->execute([$segmentId, $activityId, $userId, $elapsedSec, $startedAt]);
        }

        if ($notify) {
            self::notifyLeadershipChange($segmentId, $previousLeader);
        }
    }

    public static function deleteEffort(int $effortId, int $userId): void
    {
        db()->prepare(
            'DELETE FROM segment_efforts WHERE id = ? AND user_id = ?'
        )->execute([$effortId, $userId]);
    }

    // ============================================================
    // СМЕНА ЛИДЕРА И УВЕДОМЛЕНИЯ
    // ============================================================

    public static function notifyLeadershipChange(int $segmentId, ?array $previousLeader): void
    {
        try {
            $newLeader = self::currentLeader($segmentId);
            if (!$newLeader) return;

            $newLeaderId  = (int)$newLeader['user_id'];
            $prevLeaderId = $previousLeader ? (int)$previousLeader['user_id'] : null;

            if ($prevLeaderId === $newLeaderId) return;

            $segment = self::findById($segmentId);
            if (!$segment) return;

            $segmentName = (string)$segment['name'];

            if ($prevLeaderId !== null && $prevLeaderId !== $newLeaderId) {
                if (!Notification::existsRecent(
                    $prevLeaderId, 'segment_lost_lead', 'segment', $segmentId, 24 * 30
                )) {
                    Notification::push(
                        $prevLeaderId,
                        'segment_lost_lead',
                        $newLeaderId,
                        'segment',
                        $segmentId,
                        'Вас обошли на сегменте «' . $segmentName . '»'
                    );
                }
            }

            if ($newLeaderId !== $prevLeaderId) {
                Notification::deleteByTarget(
                    $newLeaderId, 'segment_lost_lead', 'segment', $segmentId
                );

                if (!Notification::existsRecent(
                    $newLeaderId, 'segment_new_lead', 'segment', $segmentId, 24 * 7
                )) {
                    Notification::push(
                        $newLeaderId,
                        'segment_new_lead',
                        null,
                        'segment',
                        $segmentId,
                        'Вы вышли на 1-е место на сегменте «' . $segmentName . '»'
                    );
                }
            }
        } catch (Throwable $e) {
            log_to_file('notifications.log', 'notifyLeadershipChange: ' . $e->getMessage());
        }
    }

    public static function recalcLeadership(int $segmentId): void
    {
        self::notifyLeadershipChange($segmentId, null);
    }

    // ============================================================
    // СТАТИСТИКА
    // ============================================================

    public static function stats(int $segmentId): array
    {
        $s = db()->prepare(
            'SELECT COUNT(*) AS efforts,
                    COUNT(DISTINCT user_id) AS athletes
             FROM segment_efforts
             WHERE segment_id = ?'
        );
        $s->execute([$segmentId]);
        $r = $s->fetch() ?: ['efforts' => 0, 'athletes' => 0];
        return [
            'efforts'  => (int)$r['efforts'],
            'athletes' => (int)$r['athletes'],
        ];
    }

    public static function delete(int $segmentId, int $userId): void
    {
        db()->prepare(
            'DELETE FROM segments WHERE id = ? AND creator_id = ?'
        )->execute([$segmentId, $userId]);
    }

    public static function isOwner(int $segmentId, int $userId): bool
    {
        $s = db()->prepare(
            'SELECT 1 FROM segments WHERE id = ? AND creator_id = ? LIMIT 1'
        );
        $s->execute([$segmentId, $userId]);
        return (bool)$s->fetchColumn();
    }

    public static function search(string $q, ?int $viewerId = null, int $limit = 30): array
    {
        $sql = 'SELECT s.*, u.username, u.display_name,
                       (SELECT COUNT(DISTINCT user_id) FROM segment_efforts e
                        WHERE e.segment_id = s.id) AS athletes
                FROM segments s
                JOIN users u ON u.id = s.creator_id
                WHERE s.name LIKE :q
                  AND (s.is_public = 1';

        $params = [':q' => '%' . $q . '%'];

        if ($viewerId !== null) {
            $sql .= ' OR s.creator_id = :me';
            $params[':me'] = $viewerId;
        }
        $sql .= ') ORDER BY s.created_at DESC LIMIT :lim';

        $s = db()->prepare($sql);
        foreach ($params as $k => $v) {
            $s->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    // ============================================================
    // ПАРСИНГ
    // ============================================================

    public static function parseTrack(?string $trackJson): array
    {
        if (empty($trackJson)) return [];
        $decoded = json_decode($trackJson, true);
        if (!is_array($decoded)) return [];

        $points = [];
        foreach ($decoded as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            $lat = (float)$p['lat'];
            $lng = (float)$p['lng'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            $points[] = ['lat' => $lat, 'lng' => $lng];
        }
        return $points;
    }

    public static function parseTrackJson(?string $trackJson): array
    {
        return self::parseTrack($trackJson);
    }

    public static function hasAutoCols(): bool
    {
        if (self::$hasAutoCols !== null) return self::$hasAutoCols;
        try {
            $s = db()->query("SHOW COLUMNS FROM segment_efforts LIKE 'is_auto'");
            self::$hasAutoCols = (bool)$s->fetch();
        } catch (Throwable $e) {
            self::$hasAutoCols = false;
        }
        return self::$hasAutoCols;
    }
}
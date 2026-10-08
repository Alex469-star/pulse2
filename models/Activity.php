<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class Activity
{
    // ============================================================
    // СОЗДАНИЕ / ЧТЕНИЕ / УДАЛЕНИЕ
    // ============================================================

    public static function create(int $userId, array $d): int
    {
        $s = db()->prepare(
            'INSERT INTO activities
                (user_id, type, title, description, started_at, duration_sec,
                 distance_m, elevation_gain_m, avg_speed_mps, max_speed_mps,
                 calories, gear_id, track_json, visibility,
                 fingerprint, track_hash,
                 avg_hr, max_hr, avg_cadence, max_cadence,
                 avg_power_w, max_power_w, avg_temp_c, has_sensors)
             VALUES
                (:user_id, :type, :title, :description, :started_at, :duration_sec,
                 :distance_m, :elevation_gain_m, :avg_speed_mps, :max_speed_mps,
                 :calories, :gear_id, :track_json, :visibility,
                 :fingerprint, :track_hash,
                 :avg_hr, :max_hr, :avg_cadence, :max_cadence,
                 :avg_power_w, :max_power_w, :avg_temp_c, :has_sensors)'
        );
        $s->execute([
            ':user_id'          => $userId,
            ':type'             => $d['type'] ?? 'run',
            ':title'            => $d['title'] ?? 'Активность',
            ':description'      => $d['description'] ?? null,
            ':started_at'       => $d['started_at'] ?? null,
            ':duration_sec'     => $d['duration_sec'] ?? null,
            ':distance_m'       => $d['distance_m'] ?? null,
            ':elevation_gain_m' => $d['elevation_gain_m'] ?? null,
            ':avg_speed_mps'    => $d['avg_speed_mps'] ?? null,
            ':max_speed_mps'    => $d['max_speed_mps'] ?? null,
            ':calories'         => $d['calories'] ?? null,
            ':gear_id'          => $d['gear_id'] ?? null,
            ':track_json'       => $d['track_json'] ?? null,
            ':visibility'       => $d['visibility'] ?? 'public',
            ':fingerprint'      => $d['fingerprint'] ?? null,
            ':track_hash'       => $d['track_hash'] ?? null,
            ':avg_hr'           => $d['avg_hr'] ?? null,
            ':max_hr'           => $d['max_hr'] ?? null,
            ':avg_cadence'      => $d['avg_cadence'] ?? null,
            ':max_cadence'      => $d['max_cadence'] ?? null,
            ':avg_power_w'      => $d['avg_power_w'] ?? null,
            ':max_power_w'      => $d['max_power_w'] ?? null,
            ':avg_temp_c'       => $d['avg_temp_c'] ?? null,
            ':has_sensors'      => $d['has_sensors'] ?? 0,
        ]);

        $activityId = (int)db()->lastInsertId();
        self::gameHookCapture($activityId);

        return $activityId;
    }

    public static function findById(int $id): ?array
    {
        $s = db()->prepare(
            'SELECT a.*, u.username, u.display_name, u.avatar_url
             FROM activities a JOIN users u ON u.id = a.user_id
             WHERE a.id = ? LIMIT 1'
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function feed(int $viewerId, int $limit = 20, int $offset = 0): array
    {
        $sql = 'SELECT a.*, u.username, u.display_name, u.avatar_url,
                  (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id) AS likes_count,
                  (SELECT COUNT(*) FROM activity_comments c WHERE c.activity_id = a.id) AS comments_count,
                  (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id AND l.user_id = :vid) AS liked_by_me
                FROM activities a JOIN users u ON u.id = a.user_id
                WHERE (a.visibility = "public"
                       OR a.user_id = :vid2
                       OR (a.visibility = "followers" AND a.user_id IN (
                            SELECT following_id FROM follows WHERE follower_id = :vid3
                       )))
                ORDER BY COALESCE(a.started_at, a.created_at) DESC, a.id DESC
                LIMIT :lim OFFSET :off';
        $s = db()->prepare($sql);
        $s->bindValue(':vid',  $viewerId, PDO::PARAM_INT);
        $s->bindValue(':vid2', $viewerId, PDO::PARAM_INT);
        $s->bindValue(':vid3', $viewerId, PDO::PARAM_INT);
        $s->bindValue(':lim',  $limit,    PDO::PARAM_INT);
        $s->bindValue(':off',  $offset,   PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Активности участников клубов, в которых состоит пользователь.
     */
    public static function feedFromClubs(int $viewerId, int $limit = 20, int $offset = 0): array
    {
        $sql = 'SELECT DISTINCT a.*, u.username, u.display_name, u.avatar_url,
                  (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id) AS likes_count,
                  (SELECT COUNT(*) FROM activity_comments c WHERE c.activity_id = a.id) AS comments_count,
                  (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id AND l.user_id = :vid) AS liked_by_me
                FROM activities a
                JOIN users u ON u.id = a.user_id
                JOIN club_members cm
                     ON cm.user_id = u.id
                    AND cm.status = "active"
                JOIN club_members mine
                     ON mine.club_id = cm.club_id
                    AND mine.user_id = :vid2
                    AND mine.status = "active"
                WHERE a.visibility = "public"
                ORDER BY COALESCE(a.started_at, a.created_at) DESC, a.id DESC
                LIMIT :lim OFFSET :off';
        $s = db()->prepare($sql);
        $s->bindValue(':vid',  $viewerId, PDO::PARAM_INT);
        $s->bindValue(':vid2', $viewerId, PDO::PARAM_INT);
        $s->bindValue(':lim',  $limit,    PDO::PARAM_INT);
        $s->bindValue(':off',  $offset,   PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function byUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT a.*,
              (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id) AS likes_count,
              (SELECT COUNT(*) FROM activity_comments c WHERE c.activity_id = a.id) AS comments_count
             FROM activities a
             WHERE a.user_id = ?
             ORDER BY COALESCE(a.started_at, a.created_at) DESC, a.id DESC
             LIMIT ? OFFSET ?'
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit,  PDO::PARAM_INT);
        $s->bindValue(3, $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function countByUser(int $userId): int
    {
        $s = db()->prepare('SELECT COUNT(*) FROM activities WHERE user_id = ?');
        $s->execute([$userId]);
        return (int)$s->fetchColumn();
    }

    public static function delete(int $id, int $userId): void
    {
        self::gameHookRevert($id);
        db()->prepare('DELETE FROM activities WHERE id = ? AND user_id = ?')
            ->execute([$id, $userId]);
    }

    // ============================================================
    // ФОТО АКТИВНОСТИ
    // ============================================================

    public static function photos(int $activityId): array
    {
        $s = db()->prepare(
            'SELECT * FROM activity_photos
             WHERE activity_id = ?
             ORDER BY order_index ASC, id ASC'
        );
        $s->execute([$activityId]);
        return $s->fetchAll();
    }

    public static function photosForMany(array $activityIds): array
    {
        if (!$activityIds) return [];
        $ph = implode(',', array_fill(0, count($activityIds), '?'));
        $s = db()->prepare(
            "SELECT * FROM activity_photos
             WHERE activity_id IN ($ph)
             ORDER BY activity_id ASC, order_index ASC, id ASC"
        );
        $s->execute($activityIds);

        $out = [];
        foreach ($s->fetchAll() as $row) {
            $out[(int)$row['activity_id']][] = $row;
        }
        return $out;
    }

    public static function addPhoto(int $activityId, string $url, int $orderIndex = 0): int
    {
        $s = db()->prepare(
            'INSERT INTO activity_photos (activity_id, url, order_index) VALUES (?, ?, ?)'
        );
        $s->execute([$activityId, $url, $orderIndex]);
        return (int)db()->lastInsertId();
    }

    public static function deletePhoto(int $photoId, int $activityId, int $userId): bool
    {
        $act = self::findById($activityId);
        if (!$act || (int)$act['user_id'] !== $userId) return false;

        $s = db()->prepare('SELECT * FROM activity_photos WHERE id = ? AND activity_id = ? LIMIT 1');
        $s->execute([$photoId, $activityId]);
        $photo = $s->fetch();
        if (!$photo) return false;

        self::deletePhotoFile((string)$photo['url']);
        db()->prepare('DELETE FROM activity_photos WHERE id = ?')->execute([$photoId]);
        return true;
    }

    public static function deletePhotoFile(string $url): void
    {
        $uploadsUrl = url('assets/uploads/activities');
        if (strpos($url, $uploadsUrl) === false) return;
        $filename = basename(parse_url($url, PHP_URL_PATH) ?? '');
        if ($filename === '') return;
        $path = __DIR__ . '/../assets/uploads/activities/' . $filename;
        if (is_file($path)) @unlink($path);
    }

    public static function photoCount(int $activityId): int
    {
        $s = db()->prepare('SELECT COUNT(*) FROM activity_photos WHERE activity_id = ?');
        $s->execute([$activityId]);
        return (int)$s->fetchColumn();
    }

    public static function likers(int $activityId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $sql = "
            SELECT u.id, u.username, u.display_name, u.avatar_url, al.created_at
            FROM activity_likes al
            JOIN users u ON u.id = al.user_id
            WHERE al.activity_id = :aid
            ORDER BY al.created_at DESC
            LIMIT " . $limit . "
        ";

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':aid', $activityId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'           => (int)$row['id'],
                'username'     => (string)$row['username'],
                'display_name' => (string)$row['display_name'],
                'avatar_url'   => $row['avatar_url'] ?: null,
                'initial'      => mb_substr((string)$row['display_name'], 0, 1),
                'liked_at'     => (string)$row['created_at'],
            ];
        }

        return $out;
    }

    // ============================================================
    // ДЕДУПЛИКАЦИЯ ЗАГРУЖАЕМЫХ АКТИВНОСТЕЙ
    // ============================================================

    /**
     * Считает «отпечаток» активности.
     * Округляем значения, чтобы ловить слегка отличающиеся результаты парсинга.
     */
    public static function fingerprint(array $d): string
    {
        $type = (string)($d['type'] ?? 'run');

        $startedAt = $d['started_at'] ?? null;
        if ($startedAt) {
            $ts = strtotime((string)$startedAt);
            $startedNorm = $ts ? date('Y-m-d H:i', (int)($ts / 60) * 60) : '';
        } else {
            $startedNorm = '';
        }

        $dist = (float)($d['distance_m'] ?? 0);
        $distNorm = (string)((int)round($dist / 10) * 10);

        $dur = (int)($d['duration_sec'] ?? 0);
        $durNorm = (string)((int)round($dur / 5) * 5);

        return sha1(implode('|', [$type, $startedNorm, $distNorm, $durNorm]));
    }

    /**
     * SHA-256 от нормализованного JSON трека.
     */
    public static function trackHash(?string $trackJson): ?string
    {
        if (empty($trackJson)) return null;
        $normalized = preg_replace('/\s+/', '', $trackJson);
        return hash('sha256', (string)$normalized);
    }

    /**
     * Ищет дубликат у этого пользователя.
     *
     * @return array|null ['reason' => 'fingerprint'|'track_hash', 'activity' => [...]]
     */
    public static function findDuplicate(int $userId, string $fingerprint, ?string $trackHash): ?array
    {
        $s = db()->prepare(
            'SELECT id, title, started_at, distance_m, duration_sec, type
             FROM activities
             WHERE user_id = ? AND fingerprint = ?
             LIMIT 1'
        );
        $s->execute([$userId, $fingerprint]);
        $row = $s->fetch();
        if ($row) {
            return ['reason' => 'fingerprint', 'activity' => $row];
        }

        if ($trackHash !== null) {
            $s = db()->prepare(
                'SELECT id, title, started_at, distance_m, duration_sec, type
                 FROM activities
                 WHERE user_id = ? AND track_hash = ?
                 LIMIT 1'
            );
            $s->execute([$userId, $trackHash]);
            $row = $s->fetch();
            if ($row) {
                return ['reason' => 'track_hash', 'activity' => $row];
            }
        }

        return null;
    }

    // ============================================================
    // ХУКИ В МОДУЛЬ GAME
    // ============================================================

    private static function gameHookCapture(int $activityId): void
    {
        $bootstrap = __DIR__ . '/../game/includes/bootstrap.php';
        if (!is_file($bootstrap)) return;

        try { require_once $bootstrap; } catch (\Throwable $e) { return; }
        if (!class_exists(\Pulse\Game\TerritoryEngine::class)) return;

        try {
            $inTransaction = false;
            try { $inTransaction = db()->inTransaction(); } catch (\Throwable $e) {}

            if ($inTransaction) {
                \Pulse\Game\TerritoryEngine::enqueueOnly($activityId);
                return;
            }
            \Pulse\Game\TerritoryEngine::queue($activityId);
        } catch (\Throwable $e) {
            if (function_exists('game_log')) {
                game_log('Activity::gameHookCapture failed', [
                    'activity_id' => $activityId,
                    'err'         => $e->getMessage(),
                ]);
            }
        }
    }

    private static function gameHookRevert(int $activityId): void
    {
        $bootstrap = __DIR__ . '/../game/includes/bootstrap.php';
        if (!is_file($bootstrap)) return;

        try { require_once $bootstrap; } catch (\Throwable $e) { return; }
        if (!class_exists(\Pulse\Game\TerritoryEngine::class)) return;

        try {
            \Pulse\Game\TerritoryEngine::revertByActivity($activityId);
        } catch (\Throwable $e) {
            if (function_exists('game_log')) {
                game_log('Activity::gameHookRevert failed', [
                    'activity_id' => $activityId,
                    'err'         => $e->getMessage(),
                ]);
            }
        }
    }
}
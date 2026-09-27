<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class Activity
{
    public static function create(int $userId, array $d): int
    {
        $s = db()->prepare(
            'INSERT INTO activities
                (user_id, type, title, description, started_at, duration_sec,
                 distance_m, elevation_gain_m, avg_speed_mps, max_speed_mps,
                 calories, gear_id, track_json, visibility)
             VALUES
                (:user_id, :type, :title, :description, :started_at, :duration_sec,
                 :distance_m, :elevation_gain_m, :avg_speed_mps, :max_speed_mps,
                 :calories, :gear_id, :track_json, :visibility)'
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
        ]);
        return (int)db()->lastInsertId();
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
                ORDER BY a.created_at DESC
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

    public static function byUser(int $userId, int $limit = 30): array
    {
        $s = db()->prepare(
            'SELECT a.*,
              (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id) AS likes_count,
              (SELECT COUNT(*) FROM activity_comments c WHERE c.activity_id = a.id) AS comments_count
             FROM activities a WHERE a.user_id = ?
             ORDER BY a.created_at DESC LIMIT ?'
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit,  PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function delete(int $id, int $userId): void
    {
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
        // Убедимся, что активность принадлежит пользователю
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
    
        /**
 * Возвращает список пользователей, поставивших лайк активности.
 *
 * @return array<int, array{id:int, username:string, display_name:string, avatar_url:?string}>
 */
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
        
}
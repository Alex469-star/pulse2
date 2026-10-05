<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Club.php';

class ClubEvent
{
    // ============================================================
    // ЧТЕНИЕ
    // ============================================================

    public static function findById(int $id): ?array
    {
        $s = db()->prepare(
            'SELECT e.*, u.username AS creator_username, u.display_name AS creator_display_name,
                    u.avatar_url AS creator_avatar,
                    c.name AS club_name, c.slug AS club_slug,
                    c.avatar_url AS club_avatar, c.id AS club_id
               FROM club_events e
               JOIN users u ON u.id = e.creator_id
               JOIN clubs c ON c.id = e.club_id
              WHERE e.id = ? LIMIT 1'
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function listForClub(int $clubId, string $scope = 'upcoming', int $limit = 50): array
    {
        $where = ['e.club_id = ?'];
        $params = [$clubId];

        switch ($scope) {
            case 'past':
                $where[] = 'e.starts_at < NOW()';
                break;
            case 'all':
                // без фильтра
                break;
            default: // upcoming
                $where[] = 'e.starts_at >= NOW() - INTERVAL 1 DAY';
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT e.*, u.username AS creator_username, u.display_name AS creator_display_name,
                       (SELECT COUNT(*) FROM club_event_attendees a
                         WHERE a.event_id = e.id AND a.status = 'going') AS going_count,
                       (SELECT COUNT(*) FROM club_event_photos p WHERE p.event_id = e.id) AS photos_count
                  FROM club_events e
                  JOIN users u ON u.id = e.creator_id
                 WHERE $whereSql
              ORDER BY e.starts_at " . ($scope === 'past' ? 'DESC' : 'ASC') . "
                 LIMIT " . max(1, min(200, $limit));

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function photos(int $eventId): array
    {
        $s = db()->prepare(
            'SELECT p.*, u.username, u.display_name
               FROM club_event_photos p
               JOIN users u ON u.id = p.user_id
              WHERE p.event_id = ?
           ORDER BY p.order_index ASC, p.id ASC'
        );
        $s->execute([$eventId]);
        return $s->fetchAll();
    }

    public static function attendees(int $eventId, ?string $status = null): array
    {
        $sql = 'SELECT a.*, u.username, u.display_name, u.avatar_url,
                       u.city
                  FROM club_event_attendees a
                  JOIN users u ON u.id = a.user_id
                 WHERE a.event_id = ?';
        $params = [$eventId];

        if ($status !== null) {
            $sql .= ' AND a.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY FIELD(a.status, "going","maybe","declined"), a.joined_at ASC';

        $s = db()->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    }

    public static function myStatus(int $eventId, int $userId): ?string
    {
        $s = db()->prepare(
            'SELECT status FROM club_event_attendees
              WHERE event_id = ? AND user_id = ? LIMIT 1'
        );
        $s->execute([$eventId, $userId]);
        $v = $s->fetchColumn();
        return $v !== false ? (string)$v : null;
    }

    // ============================================================
    // СОЗДАНИЕ / РЕДАКТИРОВАНИЕ / УДАЛЕНИЕ
    // ============================================================

    public static function create(int $clubId, int $creatorId, array $d): int
    {
        $s = db()->prepare(
            'INSERT INTO club_events
                (club_id, creator_id, title, description, cover_url,
                 type, visibility, status, starts_at, ends_at,
                 location, city, lat, lng, max_attendees)
             VALUES
                (:club_id, :creator_id, :title, :description, :cover_url,
                 :type, :visibility, :status, :starts_at, :ends_at,
                 :location, :city, :lat, :lng, :max_attendees)'
        );
        $s->execute([
            ':club_id'       => $clubId,
            ':creator_id'    => $creatorId,
            ':title'         => $d['title'] ?? 'Событие',
            ':description'   => $d['description'] ?? null,
            ':cover_url'     => $d['cover_url'] ?? null,
            ':type'          => $d['type'] ?? 'training',
            ':visibility'    => $d['visibility'] ?? 'members',
            ':status'        => $d['status'] ?? 'scheduled',
            ':starts_at'     => $d['starts_at'] ?? date('Y-m-d H:i:s'),
            ':ends_at'       => $d['ends_at'] ?? null,
            ':location'      => $d['location'] ?? null,
            ':city'          => $d['city'] ?? null,
            ':lat'           => $d['lat'] ?? null,
            ':lng'           => $d['lng'] ?? null,
            ':max_attendees' => $d['max_attendees'] ?? null,
        ]);

        $eventId = (int)db()->lastInsertId();

        // Создатель автоматически "идёт"
        db()->prepare(
            'INSERT INTO club_event_attendees (event_id, user_id, status)
             VALUES (?, ?, "going")'
        )->execute([$eventId, $creatorId]);

        self::recountAttendees($eventId);
        return $eventId;
    }

    public static function update(int $id, array $d): void
    {
        $fields = ['title','description','cover_url','type','visibility','status',
                   'starts_at','ends_at','location','city','lat','lng','max_attendees'];
        $setSql = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $d)) {
                $setSql[] = "`$f` = ?";
                $params[] = $d[$f];
            }
        }
        if (!$setSql) return;
        $params[] = $id;
        db()->prepare('UPDATE club_events SET ' . implode(', ', $setSql) . ' WHERE id = ?')
            ->execute($params);
    }

    public static function delete(int $id): void
    {
        db()->prepare('DELETE FROM club_events WHERE id = ?')->execute([$id]);
    }

    // ============================================================
    // УЧАСТИЕ
    // ============================================================

    public static function setAttendance(int $eventId, int $userId, string $status, ?string $comment = null): void
    {
        if (!in_array($status, ['going','maybe','declined'], true)) {
            throw new InvalidArgumentException('Неверный статус');
        }

        if ($status === 'declined') {
            db()->prepare('DELETE FROM club_event_attendees WHERE event_id = ? AND user_id = ?')
                ->execute([$eventId, $userId]);
        } else {
            db()->prepare(
                'INSERT INTO club_event_attendees (event_id, user_id, status, comment)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    comment = VALUES(comment),
                    updated_at = NOW()'
            )->execute([$eventId, $userId, $status, $comment]);
        }

        self::recountAttendees($eventId);
    }

    public static function removeAttendee(int $eventId, int $userId): void
    {
        db()->prepare('DELETE FROM club_event_attendees WHERE event_id = ? AND user_id = ?')
            ->execute([$eventId, $userId]);
        self::recountAttendees($eventId);
    }

    private static function recountAttendees(int $eventId): void
    {
        db()->prepare(
            'UPDATE club_events
                SET attendees_count = (
                    SELECT COUNT(*) FROM club_event_attendees
                     WHERE event_id = ? AND status = "going"
                )
              WHERE id = ?'
        )->execute([$eventId, $eventId]);
    }

    // ============================================================
    // ФОТО
    // ============================================================

    public static function addPhoto(int $eventId, int $userId, string $url, int $order = 0): int
    {
        $s = db()->prepare(
            'INSERT INTO club_event_photos (event_id, user_id, url, order_index)
             VALUES (?, ?, ?, ?)'
        );
        $s->execute([$eventId, $userId, $url, $order]);
        return (int)db()->lastInsertId();
    }

    public static function deletePhoto(int $photoId, int $eventId, int $userId, bool $isManager): bool
    {
        $s = db()->prepare('SELECT * FROM club_event_photos WHERE id = ? AND event_id = ? LIMIT 1');
        $s->execute([$photoId, $eventId]);
        $ph = $s->fetch();
        if (!$ph) return false;

        if ((int)$ph['user_id'] !== $userId && !$isManager) {
            return false;
        }

        db()->prepare('DELETE FROM club_event_photos WHERE id = ?')->execute([$photoId]);
        return true;
    }

    // ============================================================
    // ПРАВА
    // ============================================================

    public static function canManage(int $eventId, int $userId): bool
    {
        $event = self::findById($eventId);
        if (!$event) return false;

        // Создатель
        if ((int)$event['creator_id'] === $userId) return true;

        // Админ клуба
        return Club::canManage((int)$event['club_id'], $userId);
    }
}
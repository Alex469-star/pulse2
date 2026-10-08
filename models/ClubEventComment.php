<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class ClubEventComment
{
    public static function add(int $eventId, int $userId, string $body, ?int $parentId = null): int
    {
        $body = trim($body);
        if ($body === '') throw new InvalidArgumentException('Пустой комментарий');
        if (mb_strlen($body) > 4000) throw new InvalidArgumentException('Слишком длинный комментарий');

        // проверяем, что родитель существует и принадлежит этому же событию
        if ($parentId !== null && $parentId > 0) {
            $s = db()->prepare('SELECT event_id FROM club_event_comments WHERE id = ? LIMIT 1');
            $s->execute([$parentId]);
            $parentEvent = (int)($s->fetchColumn() ?: 0);
            if ($parentEvent !== $eventId) $parentId = null;
        } else {
            $parentId = null;
        }

        $s = db()->prepare(
            'INSERT INTO club_event_comments (event_id, parent_id, user_id, body)
             VALUES (?, ?, ?, ?)'
        );
        $s->execute([$eventId, $parentId, $userId, $body]);
        $id = (int)db()->lastInsertId();

        self::notifyParticipants($eventId, $userId, $parentId, $id);
        return $id;
    }

    public static function update(int $commentId, int $userId, string $body): bool
    {
        $body = trim($body);
        if ($body === '') return false;

        $s = db()->prepare(
            'UPDATE club_event_comments
             SET body = ?, edited_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        $s->execute([$body, $commentId, $userId]);
        return $s->rowCount() > 0;
    }

    public static function delete(int $commentId, int $userId, bool $canModerate = false): bool
    {
        if ($canModerate) {
            $s = db()->prepare('DELETE FROM club_event_comments WHERE id = ?');
            $s->execute([$commentId]);
        } else {
            $s = db()->prepare('DELETE FROM club_event_comments WHERE id = ? AND user_id = ?');
            $s->execute([$commentId, $userId]);
        }
        return $s->rowCount() > 0;
    }

    public static function forEvent(int $eventId): array
    {
        $s = db()->prepare(
            'SELECT c.*, u.username, u.display_name, u.avatar_url
             FROM club_event_comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.event_id = ?
             ORDER BY c.created_at ASC'
        );
        $s->execute([$eventId]);
        return $s->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $s = db()->prepare(
            'SELECT c.*, u.username, u.display_name, u.avatar_url
             FROM club_event_comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.id = ? LIMIT 1'
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    // ============================================================
    // УВЕДОМЛЕНИЯ
    // ============================================================

    private static function notifyParticipants(int $eventId, int $actorId, ?int $parentId, int $commentId): void
    {
        // Собираем участников треда: все, кто комментировал событие + автор родителя
        $recipients = [];

        try {
            $s = db()->prepare(
                'SELECT DISTINCT user_id
                 FROM club_event_comments
                 WHERE event_id = ? AND user_id <> ?'
            );
            $s->execute([$eventId, $actorId]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $recipients[(int)$uid] = true;
            }
        } catch (Throwable $e) {}

        // Автор родительского комментария (если это ответ)
        if ($parentId !== null && $parentId > 0) {
            $s = db()->prepare('SELECT user_id FROM club_event_comments WHERE id = ? LIMIT 1');
            $s->execute([$parentId]);
            $parentAuthor = (int)($s->fetchColumn() ?: 0);
            if ($parentAuthor > 0 && $parentAuthor !== $actorId) {
                $recipients[$parentAuthor] = true;
            }
        }

        if (!$recipients) return;

        $url = url('club-event.php?id=' . $eventId . '#comment-' . $commentId);

        foreach (array_keys($recipients) as $uid) {
            try {
                Notification::push(
                    (int)$uid,
                    'comment',
                    $actorId,
                    'club_event',
                    $eventId,
                    null,
                    $url
                );
            } catch (Throwable $e) {
                // тихо игнорируем
            }
        }
    }
}
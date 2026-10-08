<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class Comment
{
    /**
     * Добавить комментарий к активности и разослать уведомления участникам треда.
     *
     * @param int      $activityId
     * @param int      $userId
     * @param string   $body
     * @param int|null $parentId  ID комментария, на который отвечают (если ответ)
     */
    public static function add(int $activityId, int $userId, string $body, ?int $parentId = null): int
    {
        $s = db()->prepare(
            'INSERT INTO activity_comments (activity_id, parent_id, user_id, body)
             VALUES (?, ?, ?, ?)'
        );
        $s->execute([$activityId, $parentId, $userId, $body]);
        $id = (int)db()->lastInsertId();

        // ---- Собираем получателей ----
        $ownerId = self::activityOwner($activityId);
        $participants = self::participants($activityId, $userId); // уже без автора нового комментария

        if ($ownerId > 0 && $ownerId !== $userId) {
            $participants[$ownerId] = true; // ключи-массив, чтобы не дублировать
        }

        // Если это ответ — уведомим ещё и автора родительского комментария
        if ($parentId !== null && $parentId > 0) {
            $parentAuthor = self::commentAuthor($parentId);
            if ($parentAuthor > 0 && $parentAuthor !== $userId) {
                $participants[$parentAuthor] = true;
            }
        }

        // ---- Рассылка ----
        if ($participants) {
            $type = ($parentId !== null && $parentId > 0) ? 'comment_reply' : 'comment';
            $url  = url('activity.php?id=' . $activityId . '#comment-' . $id);

            foreach (array_keys($participants) as $recipientId) {
                // Антиспам: не чаще одного уведомления по этой активности за 6 часов
                if (Notification::existsRecent((int)$recipientId, $type, 'activity', $activityId, 6)) {
                    continue;
                }
                Notification::push(
                    (int)$recipientId,
                    $type,
                    $userId,
                    'activity',
                    $activityId,
                    null,
                    $url
                );
            }
        }

        return $id;
    }

    public static function forActivity(int $activityId): array
    {
        $s = db()->prepare(
            'SELECT c.*, u.username, u.display_name, u.avatar_url
             FROM activity_comments c JOIN users u ON u.id = c.user_id
             WHERE c.activity_id = ? ORDER BY c.created_at ASC'
        );
        $s->execute([$activityId]);
        return $s->fetchAll();
    }

    /* ============================================================
       ВНУТРЕННИЕ ХЕЛПЕРЫ
       ============================================================ */

    /** ID владельца активности (0 если не найдено). */
    private static function activityOwner(int $activityId): int
    {
        $s = db()->prepare('SELECT user_id FROM activities WHERE id = ? LIMIT 1');
        $s->execute([$activityId]);
        return (int)($s->fetchColumn() ?: 0);
    }

    /** Автор конкретного комментария (0 если не найдено). */
    private static function commentAuthor(int $commentId): int
    {
        $s = db()->prepare('SELECT user_id FROM activity_comments WHERE id = ? LIMIT 1');
        $s->execute([$commentId]);
        return (int)($s->fetchColumn() ?: 0);
    }

    /**
     * Уникальные пользователи, которые уже комментировали эту активность,
     * кроме автора нового комментария.
     *
     * @return array<int,bool>  user_id => true
     */
    private static function participants(int $activityId, int $excludeUserId): array
    {
        $s = db()->prepare(
            'SELECT DISTINCT user_id
             FROM activity_comments
             WHERE activity_id = ? AND user_id <> ?'
        );
        $s->execute([$activityId, $excludeUserId]);

        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $out[(int)$uid] = true;
        }
        return $out;
    }
}
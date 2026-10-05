<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';


class Notification
{
    /**
     * Создать уведомление.
     */
        /**
     * Создать уведомление.
     */
    public static function push(
        int $userId,
        string $type,
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $message = null,
        ?string $url = null
    ): void {
        if ($actorId === $userId) return;

        $allowed = [
            'like', 'comment', 'follow', 'mention', 'system',
            'segment_new_lead', 'segment_lost_lead',
            'territory_captured', 'territory_lost', 'territory_stolen',
            'club_invite', 'club_join', 'club_post', 'club_role', 'club_event',
        ];
        if (!in_array($type, $allowed, true)) return;

        try {
            $s = db()->prepare(
                'INSERT INTO notifications
                    (user_id, type, actor_id, target_type, target_id, message, url)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $s->execute([$userId, $type, $actorId, $targetType, $targetId, $message, $url]);
        } catch (Throwable $e) {
            log_to_file('notifications.log', $e->getMessage());
        }
    }

    /**
     * Пакетная вставка уведомлений.
     */
        public static function pushMany(array $items): void
    {
        foreach ($items as $item) {
            self::push(
                (int)$item['user_id'],
                (string)$item['type'],
                isset($item['actor_id'])   ? (int)$item['actor_id']    : null,
                $item['target_type'] ?? null,
                isset($item['target_id'])  ? (int)$item['target_id']   : null,
                $item['message'] ?? null,
                $item['url'] ?? null
            );
        }
    }

    /**
     * Количество непрочитанных.
     */
    public static function unreadCount(int $userId): int
    {
        $s = db()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'
        );
        $s->execute([$userId]);
        return (int)$s->fetchColumn();
    }

    /**
     * Все уведомления пользователя.
     */
        public static function allForUser(int $userId, int $limit = 50): array
    {
        $s = db()->prepare(
            'SELECT n.*, u.username, u.display_name, u.avatar_url
             FROM notifications n
             LEFT JOIN users u ON u.id = n.actor_id
             WHERE n.user_id = ?
             ORDER BY n.created_at DESC
             LIMIT ?'
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Отметить одно прочитанным.
     */
    public static function markRead(int $notificationId, int $userId): void
    {
        db()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?'
        )->execute([$notificationId, $userId]);
    }

    /**
     * Отметить все прочитанными.
     */
    public static function markAllRead(int $userId): void
    {
        db()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ?'
        )->execute([$userId]);
    }

    /**
     * Есть ли уже такое уведомление (защита от спама).
     * Проверяет наличие уведомления указанного типа
     * по цели (target_type + target_id) за последние N часов.
     */
    public static function existsRecent(
        int $userId,
        string $type,
        string $targetType,
        int $targetId,
        int $hoursWindow = 24
    ): bool {
        $s = db()->prepare(
            'SELECT 1 FROM notifications
             WHERE user_id = ?
               AND type = ?
               AND target_type = ?
               AND target_id = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             LIMIT 1'
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $type);
        $s->bindValue(3, $targetType);
        $s->bindValue(4, $targetId, PDO::PARAM_INT);
        $s->bindValue(5, $hoursWindow, PDO::PARAM_INT);
        $s->execute();
        return (bool)$s->fetchColumn();
    }

    /**
     * Удалить старое уведомление (если пользователь вернул лидерство).
     */
    public static function deleteByTarget(
        int $userId,
        string $type,
        string $targetType,
        int $targetId
    ): void {
        db()->prepare(
            'DELETE FROM notifications
             WHERE user_id = ? AND type = ? AND target_type = ? AND target_id = ?'
        )->execute([$userId, $type, $targetType, $targetId]);
    }
}
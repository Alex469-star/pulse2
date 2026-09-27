<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class Follow
{
    public static function isFollowing(int $a, int $b): bool
    {
        if ($a === $b) return false;
        $s = db()->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1');
        $s->execute([$a, $b]);
        return (bool)$s->fetchColumn();
    }

    /**
     * Подписаться. $a подписывается на $b.
     */
    public static function follow(int $a, int $b): void
    {
        if ($a === $b) return;
        db()->prepare('INSERT IGNORE INTO follows (follower_id, following_id) VALUES (?, ?)')
            ->execute([$a, $b]);
        Notification::push($b, 'follow', $a, 'user', $a);
    }

    /**
     * Отписаться. $a отписывается от $b.
     */
    public static function unfollow(int $a, int $b): void
    {
        db()->prepare('DELETE FROM follows WHERE follower_id = ? AND following_id = ?')
            ->execute([$a, $b]);
    }

    /**
     * Удалить подписчика: $followerId больше не подписан на $userId.
     * Это то же самое, что "unfollow", но вызвано со стороны владельца профиля.
     */
    public static function removeFollower(int $userId, int $followerId): void
    {
        db()->prepare('DELETE FROM follows WHERE follower_id = ? AND following_id = ?')
            ->execute([$followerId, $userId]);
    }

    /**
     * Кто подписан на пользователя (подписчики).
     */
    public static function followers(int $userId, int $limit = 200, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT u.id, u.username, u.display_name, u.avatar_url, u.city,
                    f.created_at AS followed_at,
                    (SELECT COUNT(*) FROM follows f2
                     WHERE f2.follower_id = :me AND f2.following_id = u.id) AS i_follow_back
             FROM follows f
             JOIN users u ON u.id = f.follower_id
             WHERE f.following_id = :uid
             ORDER BY f.created_at DESC
             LIMIT :lim OFFSET :off'
        );
        $s->bindValue(':me', $userId, PDO::PARAM_INT);
        $s->bindValue(':uid', $userId, PDO::PARAM_INT);
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->bindValue(':off', $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * На кого подписан пользователь.
     */
    public static function following(int $userId, int $limit = 200, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT u.id, u.username, u.display_name, u.avatar_url, u.city,
                    f.created_at AS followed_at,
                    1 AS i_follow_back
             FROM follows f
             JOIN users u ON u.id = f.following_id
             WHERE f.follower_id = :uid
             ORDER BY f.created_at DESC
             LIMIT :lim OFFSET :off'
        );
        $s->bindValue(':uid', $userId, PDO::PARAM_INT);
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->bindValue(':off', $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function followersCount(int $userId): int
    {
        $s = db()->prepare('SELECT COUNT(*) FROM follows WHERE following_id = ?');
        $s->execute([$userId]);
        return (int)$s->fetchColumn();
    }

    public static function followingCount(int $userId): int
    {
        $s = db()->prepare('SELECT COUNT(*) FROM follows WHERE follower_id = ?');
        $s->execute([$userId]);
        return (int)$s->fetchColumn();
    }
}
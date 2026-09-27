<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class Comment
{
    public static function add(int $activityId, int $userId, string $body): int
    {
        $s = db()->prepare('INSERT INTO activity_comments (activity_id, user_id, body) VALUES (?, ?, ?)');
        $s->execute([$activityId, $userId, $body]);
        $id = (int)db()->lastInsertId();

        $s = db()->prepare('SELECT user_id FROM activities WHERE id = ?');
        $s->execute([$activityId]);
        $owner = (int)$s->fetchColumn();
        if ($owner && $owner !== $userId) {
            Notification::push($owner, 'comment', $userId, 'activity', $activityId);
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
}
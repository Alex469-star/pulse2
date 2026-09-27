<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class Like
{
    public static function toggle(int $userId, int $activityId): bool
    {
        $s = db()->prepare('SELECT 1 FROM activity_likes WHERE user_id=? AND activity_id=?');
        $s->execute([$userId, $activityId]);
        if ($s->fetchColumn()) {
            db()->prepare('DELETE FROM activity_likes WHERE user_id=? AND activity_id=?')
                ->execute([$userId, $activityId]);
            return false;
        }
        db()->prepare('INSERT INTO activity_likes (user_id, activity_id) VALUES (?, ?)')
            ->execute([$userId, $activityId]);

        // Уведомление автору
        $s = db()->prepare('SELECT user_id FROM activities WHERE id = ?');
        $s->execute([$activityId]);
        $owner = (int)$s->fetchColumn();
        if ($owner && $owner !== $userId) {
            Notification::push($owner, 'like', $userId, 'activity', $activityId);
        }
        return true;
    }
}
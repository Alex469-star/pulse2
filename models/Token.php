<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

class Token
{
    public static function create(int $userId, string $type, int $ttlHours = 24): string
    {
        $raw = random_token(32);
        $hash = hash('sha256', $raw);
        $s = db()->prepare(
            'INSERT INTO tokens (user_id, type, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))'
        );
        $s->execute([$userId, $type, $hash, $ttlHours]);
        return $raw;
    }

    public static function consume(string $raw, string $type): ?int
    {
        $hash = hash('sha256', $raw);
        $s = db()->prepare(
            'SELECT * FROM tokens
             WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $s->execute([$hash, $type]);
        $row = $s->fetch();
        if (!$row) return null;

        db()->prepare('UPDATE tokens SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        return (int)$row['user_id'];
    }
}
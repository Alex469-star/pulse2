<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

class User
{
    public static function findByEmail(string $email): ?array
    {
        $s = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $s->execute([$email]);
        return $s->fetch() ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $s = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $s->execute([$username]);
        return $s->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $s = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function search(string $q, int $limit = 20): array
    {
        $s = db()->prepare(
            'SELECT id, username, display_name, avatar_url
             FROM users
             WHERE username LIKE :q OR display_name LIKE :q
             ORDER BY display_name ASC LIMIT :lim'
        );
        $s->bindValue(':q', '%' . $q . '%');
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function create(array $d): int
    {
        $s = db()->prepare(
            'INSERT INTO users (email, username, password_hash, display_name, email_verified)
             VALUES (:email, :username, :password_hash, :display_name, :email_verified)'
        );
        $s->execute([
            ':email'          => $d['email'],
            ':username'       => $d['username'],
            ':password_hash'  => $d['password_hash'],
            ':display_name'   => $d['display_name'],
            ':email_verified' => $d['email_verified'] ?? 0,
        ]);
        return (int)db()->lastInsertId();
    }

    public static function update(int $id, array $fields): void
    {
        $allowed = ['display_name','bio','city','country','gender','birth_date',
                    'weight_kg','height_cm','units','is_public','avatar_url','email_verified',
                    'password_hash'];
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $set[] = "$k = :$k";
                $params[":$k"] = $v;
            }
        }
        if (!$set) return;
        db()->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
    }

    public static function stats(int $userId): array
    {
        $s = db()->prepare(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(distance_m),0) AS dist
             FROM activities WHERE user_id = ?'
        );
        $s->execute([$userId]);
        $row = $s->fetch() ?: ['cnt' => 0, 'dist' => 0];

        $s = db()->prepare('SELECT COUNT(*) FROM follows WHERE following_id = ?');
        $s->execute([$userId]);
        $followers = (int)$s->fetchColumn();

        $s = db()->prepare('SELECT COUNT(*) FROM follows WHERE follower_id = ?');
        $s->execute([$userId]);
        $following = (int)$s->fetchColumn();

        return [
            'activities' => (int)$row['cnt'],
            'distance_m' => (float)$row['dist'],
            'followers'  => $followers,
            'following'  => $following,
        ];
    }
}
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

    // ============================================================
    // РЕКОМЕНДАЦИИ И ТОП СПОРТСМЕНОВ
    // ============================================================

    /**
     * Кого читать — рекомендации подписок.
     * Простой алгоритм: пользователи, на которых я не подписан,
     * отсортированы по количеству активностей.
     */
    public static function suggestedFor(int $userId, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));

        $sql = "
            SELECT u.id, u.username, u.display_name, u.avatar_url,
                   (SELECT COUNT(*) FROM activities a WHERE a.user_id = u.id) AS cnt
            FROM users u
            WHERE u.id <> :me
              AND u.is_banned = 0
              AND u.id NOT IN (
                  SELECT following_id FROM follows WHERE follower_id = :me2
              )
            ORDER BY cnt DESC, u.id ASC
            LIMIT " . $limit . "
        ";

        $s = db()->prepare($sql);
        $s->bindValue(':me',  $userId, PDO::PARAM_INT);
        $s->bindValue(':me2', $userId, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Топ спортсменов среди тех, на кого я подписан.
     * За последние $days дней — по количеству активностей и дистанции.
     */
    public static function topAthletesFor(int $userId, int $days = 30, int $limit = 5): array
    {
        $days  = max(1, min(365, $days));
        $limit = max(1, min(50, $limit));

        $sql = "
            SELECT u.id, u.username, u.display_name, u.avatar_url,
                   COUNT(a.id) AS cnt,
                   COALESCE(SUM(a.distance_m), 0) AS dist_m
            FROM follows f
            JOIN users u ON u.id = f.following_id
            LEFT JOIN activities a
                   ON a.user_id = u.id
                  AND a.visibility IN ('public','followers')
                  AND COALESCE(a.started_at, a.created_at) >= DATE_SUB(NOW(), INTERVAL " . $days . " DAY)
            WHERE f.follower_id = :me
            GROUP BY u.id
            ORDER BY cnt DESC, dist_m DESC
            LIMIT " . $limit . "
        ";

        $s = db()->prepare($sql);
        $s->bindValue(':me', $userId, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }
}
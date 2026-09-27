<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class Post
{
    // ============================================================
    // СОЗДАНИЕ / ОБНОВЛЕНИЕ / УДАЛЕНИЕ
    // ============================================================

    public static function create(int $userId, array $d): int
    {
        $s = db()->prepare(
            'INSERT INTO posts (user_id, title, body, visibility)
             VALUES (:user_id, :title, :body, :visibility)'
        );
        $s->execute([
            ':user_id'    => $userId,
            ':title'      => $d['title'],
            ':body'       => $d['body'],
            ':visibility' => $d['visibility'] ?? 'public',
        ]);
        return (int)db()->lastInsertId();
    }

    public static function update(int $postId, int $userId, array $d): void
    {
        $allowed = ['title', 'body', 'visibility'];
        $set = [];
        $params = [':id' => $postId, ':uid' => $userId];

        foreach ($d as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $set[] = "$k = :$k";
            $params[":$k"] = $v;
        }
        if (!$set) return;

        db()->prepare('UPDATE posts SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :uid')
            ->execute($params);
    }

    public static function delete(int $postId, int $userId): void
    {
        $photos = self::photos($postId);
        foreach ($photos as $p) {
            self::deleteFile($p['url']);
        }
        db()->prepare('DELETE FROM posts WHERE id = ? AND user_id = ?')
            ->execute([$postId, $userId]);
    }

    // ============================================================
    // ЧТЕНИЕ
    // ============================================================

    public static function findById(int $id): ?array
    {
        $s = db()->prepare(
            'SELECT p.*, u.username, u.display_name, u.avatar_url
             FROM posts p
             JOIN users u ON u.id = p.user_id
             WHERE p.id = ? LIMIT 1'
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function feed(int $viewerId, int $limit = 20, int $offset = 0): array
    {
        $sql = 'SELECT p.*, u.username, u.display_name, u.avatar_url,
                       (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id) AS likes_count,
                       (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id) AS comments_count,
                       (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id AND l.user_id = :vid) AS liked_by_me
                FROM posts p
                JOIN users u ON u.id = p.user_id
                WHERE (p.visibility = "public"
                       OR p.user_id = :me_own
                       OR (p.visibility = "followers" AND p.user_id IN (
                            SELECT following_id FROM follows WHERE follower_id = :me_follow
                       )))
                ORDER BY p.created_at DESC
                LIMIT :lim OFFSET :off';

        $s = db()->prepare($sql);
        $s->bindValue(':vid', $viewerId, PDO::PARAM_INT);
        $s->bindValue(':me_own', $viewerId, PDO::PARAM_INT);
        $s->bindValue(':me_follow', $viewerId, PDO::PARAM_INT);
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->bindValue(':off', $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function byUser(int $userId, int $limit = 30, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT p.*, u.username, u.display_name, u.avatar_url,
                    (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id) AS likes_count,
                    (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id) AS comments_count
             FROM posts p
             JOIN users u ON u.id = p.user_id
             WHERE p.user_id = ?
             ORDER BY p.created_at DESC
             LIMIT ? OFFSET ?'
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->bindValue(3, $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    // ============================================================
    // ФОТО
    // ============================================================

    public static function photos(int $postId): array
    {
        $s = db()->prepare(
            'SELECT * FROM post_photos WHERE post_id = ? ORDER BY order_index ASC, id ASC'
        );
        $s->execute([$postId]);
        return $s->fetchAll();
    }

    public static function photosForMany(array $postIds): array
    {
        if (!$postIds) return [];
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $s = db()->prepare(
            "SELECT * FROM post_photos
             WHERE post_id IN ($placeholders)
             ORDER BY post_id ASC, order_index ASC, id ASC"
        );
        $s->execute($postIds);

        $out = [];
        foreach ($s->fetchAll() as $row) {
            $out[(int)$row['post_id']][] = $row;
        }
        return $out;
    }

    public static function addPhoto(int $postId, string $url, int $orderIndex = 0): int
    {
        $s = db()->prepare(
            'INSERT INTO post_photos (post_id, url, order_index) VALUES (?, ?, ?)'
        );
        $s->execute([$postId, $url, $orderIndex]);
        return (int)db()->lastInsertId();
    }

    public static function deletePhoto(int $photoId, int $postId, int $userId): bool
    {
        $post = self::findById($postId);
        if (!$post || (int)$post['user_id'] !== $userId) return false;

        $s = db()->prepare('SELECT * FROM post_photos WHERE id = ? AND post_id = ? LIMIT 1');
        $s->execute([$photoId, $postId]);
        $photo = $s->fetch();
        if (!$photo) return false;

        self::deleteFile($photo['url']);
        db()->prepare('DELETE FROM post_photos WHERE id = ?')->execute([$photoId]);
        return true;
    }

    public static function deleteFile(string $url): void
    {
        $uploadsUrl = url('assets/uploads/posts');
        if (strpos($url, $uploadsUrl) === false) return;
        $filename = basename(parse_url($url, PHP_URL_PATH) ?? '');
        if ($filename === '') return;
        $path = __DIR__ . '/../assets/uploads/posts/' . $filename;
        if (is_file($path)) @unlink($path);
    }

    // ============================================================
    // ЛАЙКИ
    // ============================================================

    public static function toggleLike(int $userId, int $postId): bool
    {
        $s = db()->prepare('SELECT 1 FROM post_likes WHERE user_id = ? AND post_id = ?');
        $s->execute([$userId, $postId]);
        if ($s->fetchColumn()) {
            db()->prepare('DELETE FROM post_likes WHERE user_id = ? AND post_id = ?')
                ->execute([$userId, $postId]);
            return false;
        }
        db()->prepare('INSERT INTO post_likes (user_id, post_id) VALUES (?, ?)')
            ->execute([$userId, $postId]);

        $s = db()->prepare('SELECT user_id FROM posts WHERE id = ?');
        $s->execute([$postId]);
        $owner = (int)$s->fetchColumn();
        if ($owner && $owner !== $userId) {
            Notification::push($owner, 'post_like', $userId, 'post', $postId);
        }
        return true;
    }

    // ============================================================
    // КОММЕНТАРИИ
    // ============================================================

    public static function addComment(int $postId, int $userId, string $body): int
    {
        $s = db()->prepare(
            'INSERT INTO post_comments (post_id, user_id, body) VALUES (?, ?, ?)'
        );
        $s->execute([$postId, $userId, $body]);
        $id = (int)db()->lastInsertId();

        $s = db()->prepare('SELECT user_id FROM posts WHERE id = ?');
        $s->execute([$postId]);
        $owner = (int)$s->fetchColumn();
        if ($owner && $owner !== $userId) {
            Notification::push($owner, 'post_comment', $userId, 'post', $postId);
        }
        return $id;
    }

    public static function comments(int $postId): array
    {
        $s = db()->prepare(
            'SELECT c.*, u.username, u.display_name, u.avatar_url
             FROM post_comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.post_id = ?
             ORDER BY c.created_at ASC'
        );
        $s->execute([$postId]);
        return $s->fetchAll();
    }

    public static function commentsForMany(array $postIds): array
    {
        if (!$postIds) return [];
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $s = db()->prepare(
            "SELECT c.*, u.username, u.display_name, u.avatar_url
             FROM post_comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.post_id IN ($placeholders)
             ORDER BY c.post_id ASC, c.created_at ASC"
        );
        $s->execute($postIds);

        $out = [];
        foreach ($s->fetchAll() as $row) {
            $out[(int)$row['post_id']][] = $row;
        }
        return $out;
    }

    public static function deleteComment(int $commentId, int $userId): bool
    {
        $s = db()->prepare(
            'SELECT c.user_id AS comment_owner, p.user_id AS post_owner
             FROM post_comments c
             JOIN posts p ON p.id = c.post_id
             WHERE c.id = ? LIMIT 1'
        );
        $s->execute([$commentId]);
        $row = $s->fetch();
        if (!$row) return false;

        if ((int)$row['comment_owner'] !== $userId && (int)$row['post_owner'] !== $userId) {
            return false;
        }
        db()->prepare('DELETE FROM post_comments WHERE id = ?')->execute([$commentId]);
        return true;
    }
}
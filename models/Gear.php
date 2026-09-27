<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

class Gear
{
    private static ?bool $hasPhotoCol = null;

    public static function hasPhotoColumn(): bool
    {
        if (self::$hasPhotoCol !== null) return self::$hasPhotoCol;
        try {
            $s = db()->query("SHOW COLUMNS FROM gear LIKE 'photo_url'");
            self::$hasPhotoCol = (bool)$s->fetch();
        } catch (Throwable $e) {
            self::$hasPhotoCol = false;
        }
        return self::$hasPhotoCol;
    }

    public static function allForUser(int $userId): array
    {
        $cols = self::hasPhotoColumn() ? 'g.*, g.photo_url' : 'g.*';
        $s = db()->prepare("SELECT $cols FROM gear g WHERE g.user_id = ? ORDER BY g.created_at DESC");
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    /**
     * Возвращает весь инвентарь пользователя со статистикой использования:
     * distance_m — сумма дистанций активностей, привязанных к этому инвентарю;
     * activities_count — количество таких активностей;
     * last_used_at — дата последней активности.
     */
    public static function allForUserWithStats(int $userId): array
    {
        $hasPhoto = self::hasPhotoColumn();
        $photoCol = $hasPhoto ? 'g.photo_url' : 'NULL AS photo_url';

        $sql = "
            SELECT g.*, $photoCol,
                   COALESCE(SUM(a.distance_m), 0) AS distance_m,
                   COUNT(a.id) AS activities_count,
                   MAX(a.started_at) AS last_used_at,
                   MAX(a.created_at) AS last_added_at
            FROM gear g
            LEFT JOIN activities a ON a.gear_id = g.id
            WHERE g.user_id = :uid
            GROUP BY g.id
            ORDER BY g.is_retired ASC, g.created_at DESC
        ";

        $s = db()->prepare($sql);
        $s->bindValue(':uid', $userId, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $hasPhoto = self::hasPhotoColumn();
        $cols = $hasPhoto ? '*' : '*, NULL AS photo_url';
        $s = db()->prepare("SELECT $cols FROM gear WHERE id = ? LIMIT 1");
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function create(int $userId, array $d): int
    {
        $hasPhoto = self::hasPhotoColumn();

        if ($hasPhoto) {
            $s = db()->prepare(
                'INSERT INTO gear (user_id, type, name, brand, model, purchase_date, notes, photo_url)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $s->execute([
                $userId,
                $d['type'] ?? 'other',
                $d['name'],
                $d['brand'] ?? null,
                $d['model'] ?? null,
                $d['purchase_date'] ?? null,
                $d['notes'] ?? null,
                $d['photo_url'] ?? null,
            ]);
        } else {
            $s = db()->prepare(
                'INSERT INTO gear (user_id, type, name, brand, model, purchase_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $s->execute([
                $userId,
                $d['type'] ?? 'other',
                $d['name'],
                $d['brand'] ?? null,
                $d['model'] ?? null,
                $d['purchase_date'] ?? null,
                $d['notes'] ?? null,
            ]);
        }
        return (int)db()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $fields): void
    {
        $allowed = ['name','type','brand','model','purchase_date','notes','photo_url','is_retired'];
        $set = [];
        $params = [':id' => $id, ':uid' => $userId];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            if ($k === 'photo_url' && !self::hasPhotoColumn()) continue;
            $set[] = "$k = :$k";
            $params[":$k"] = $v;
        }
        if (!$set) return;
        db()->prepare('UPDATE gear SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :uid')
            ->execute($params);
    }

    public static function toggleRetired(int $id, int $userId): void
    {
        db()->prepare(
            'UPDATE gear SET is_retired = 1 - is_retired WHERE id = ? AND user_id = ?'
        )->execute([$id, $userId]);
    }

    public static function delete(int $id, int $userId): void
    {
        db()->prepare('DELETE FROM gear WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    }

    public static function stats(int $gearId): array
    {
        $s = db()->prepare(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(distance_m),0) AS dist
             FROM activities WHERE gear_id = ?'
        );
        $s->execute([$gearId]);
        $r = $s->fetch() ?: ['cnt' => 0, 'dist' => 0];
        return ['activities' => (int)$r['cnt'], 'distance_m' => (float)$r['dist']];
    }
}
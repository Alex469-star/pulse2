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
     * Возвращает весь инвентарь пользователя со статистикой использования.
     *
     * distance_m        — сумма дистанций активностей, привязанных к инвентарю,
     *                     начиная с purchase_date (если она задана).
     * activities_count  — количество таких активностей.
     * last_used_at      — дата последней подходящей активности.
     * last_added_at     — дата последнего добавления активности.
     *
     * Активности с started_at (или created_at) ДО purchase_date в пробег не идут.
     */
    public static function allForUserWithStats(int $userId): array
    {
        $hasPhoto = self::hasPhotoColumn();
        $photoCol = $hasPhoto ? 'g.photo_url' : 'NULL AS photo_url';

        // Условие фильтрации по дате покупки внутри JOIN:
        // если purchase_date IS NULL — считаем все активности;
        // иначе — только те, что начались (или созданы) после даты покупки.
        $sql = "
            SELECT g.*, $photoCol,
                   COALESCE(SUM(a.distance_m), 0) AS distance_m,
                   COUNT(a.id) AS activities_count,
                   MAX(a.started_at) AS last_used_at,
                   MAX(a.created_at) AS last_added_at
              FROM gear g
         LEFT JOIN activities a
                ON a.gear_id = g.id
               AND (
                    g.purchase_date IS NULL
                    OR COALESCE(a.started_at, a.created_at) >= g.purchase_date
               )
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

    /**
     * Статистика по вещи с учётом purchase_date.
     *
     * @return array{activities:int, distance_m:float, last_used_at:?string}
     */
    public static function stats(int $gearId): array
    {
        // Берём purchase_date вещи, чтобы отфильтровать активности
        $g = db()->prepare('SELECT purchase_date FROM gear WHERE id = ? LIMIT 1');
        $g->execute([$gearId]);
        $purchaseDate = $g->fetchColumn();
        $purchaseDate = $purchaseDate !== false ? $purchaseDate : null;

        if ($purchaseDate) {
            $s = db()->prepare(
                'SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(distance_m), 0) AS dist,
                        MAX(started_at) AS last_used_at
                   FROM activities
                  WHERE gear_id = ?
                    AND COALESCE(started_at, created_at) >= ?'
            );
            $s->execute([$gearId, $purchaseDate . ' 00:00:00']);
        } else {
            $s = db()->prepare(
                'SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(distance_m), 0) AS dist,
                        MAX(started_at) AS last_used_at
                   FROM activities
                  WHERE gear_id = ?'
            );
            $s->execute([$gearId]);
        }

        $r = $s->fetch() ?: ['cnt' => 0, 'dist' => 0, 'last_used_at' => null];

        return [
            'activities'   => (int)$r['cnt'],
            'distance_m'   => (float)$r['dist'],
            'last_used_at' => $r['last_used_at'],
        ];
    }

    /**
     * Пересчёт пробега вещи.
     *
     * Возвращает актуальную сумму дистанций активностей, привязанных к вещи,
     * у которых started_at (или created_at) >= purchase_date.
     *
     * Если у вещи нет purchase_date — считает все привязанные активности.
     *
     * Важно: в текущей схеме нет колонки gear.distance_m — пробег считается
     * на лету в allForUserWithStats(). Поэтому метод НЕ пишет в БД, а просто
     * возвращает актуальное значение. Если позже добавишь колонку — раскомментируй
     * блок UPDATE ниже.
     *
     * @return float Пробег в метрах
     */
    public static function recalcDistance(int $gearId, int $userId): float
    {
        // Проверяем владельца и получаем purchase_date
        $stmt = db()->prepare(
            'SELECT id, purchase_date FROM gear WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$gearId, $userId]);
        $gear = $stmt->fetch();
        if (!$gear) {
            throw new RuntimeException('Инвентарь не найден');
        }

        $purchaseDate = $gear['purchase_date'] ?? null;

        if ($purchaseDate) {
            $sql = 'SELECT COALESCE(SUM(distance_m), 0)
                      FROM activities
                     WHERE gear_id = ?
                       AND user_id = ?
                       AND COALESCE(started_at, created_at) >= ?';
            $s = db()->prepare($sql);
            $s->execute([$gearId, $userId, $purchaseDate . ' 00:00:00']);
        } else {
            $sql = 'SELECT COALESCE(SUM(distance_m), 0)
                      FROM activities
                     WHERE gear_id = ? AND user_id = ?';
            $s = db()->prepare($sql);
            $s->execute([$gearId, $userId]);
        }

        $total = (float)$s->fetchColumn();

        /*
        // Если когда-нибудь добавишь колонку gear.distance_m — раскомментируй:
        try {
            db()->prepare('UPDATE gear SET distance_m = ? WHERE id = ? AND user_id = ?')
                ->execute([$total, $gearId, $userId]);
        } catch (Throwable $e) {
            // колонки нет — молча игнорируем
        }
        */

        return $total;
    }
}
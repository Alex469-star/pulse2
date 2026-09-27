<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Route
{
    /**
     * Кэш: есть ли в routes колонка waypoints_json.
     */
    private static ?bool $hasWaypointsCol = null;

    // ============================================================
    // СОЗДАНИЕ И ЧТЕНИЕ
    // ============================================================

    /**
     * Создать маршрут.
     *
     * Ожидаемые поля:
     *  - name             (string, обязательно)
     *  - description      (string|null)
     *  - type             (string, run|ride|swim|ski|walk|hike|other)
     *  - distance_m       (float|null)
     *  - elevation_gain_m (float|null)
     *  - track_json       (string, обязательно) — JSON-массив [{lat,lng}, ...]
     *  - waypoints_json   (string|null)          — JSON-массив меток
     *  - is_public        (int, 0|1)
     */
    public static function create(int $userId, array $d): int
    {
        $hasWaypoints = self::supportsWaypoints();

        if ($hasWaypoints) {
            $sql = 'INSERT INTO routes
                        (user_id, name, description, type,
                         distance_m, elevation_gain_m,
                         track_json, waypoints_json, is_public)
                    VALUES
                        (:user_id, :name, :description, :type,
                         :distance_m, :elevation_gain_m,
                         :track_json, :waypoints_json, :is_public)';
        } else {
            $sql = 'INSERT INTO routes
                        (user_id, name, description, type,
                         distance_m, elevation_gain_m,
                         track_json, is_public)
                    VALUES
                        (:user_id, :name, :description, :type,
                         :distance_m, :elevation_gain_m,
                         :track_json, :is_public)';
        }

        $params = [
            ':user_id'          => $userId,
            ':name'             => $d['name'],
            ':description'      => $d['description'] ?? null,
            ':type'             => $d['type'] ?? 'run',
            ':distance_m'       => $d['distance_m'] ?? null,
            ':elevation_gain_m' => $d['elevation_gain_m'] ?? null,
            ':track_json'       => $d['track_json'],
            ':is_public'        => isset($d['is_public']) ? (int)$d['is_public'] : 1,
        ];

        if ($hasWaypoints) {
            $params[':waypoints_json'] = $d['waypoints_json'] ?? null;
        }

        $s = db()->prepare($sql);
        $s->execute($params);

        return (int)db()->lastInsertId();
    }

    /**
     * Найти маршрут по ID (с данными автора).
     */
    public static function findById(int $id): ?array
    {
        $hasWaypoints = self::supportsWaypoints();

        $cols = 'r.id, r.user_id, r.name, r.description, r.type,
                 r.distance_m, r.elevation_gain_m,
                 r.track_json, r.is_public, r.created_at,
                 u.username, u.display_name, u.avatar_url';

        if ($hasWaypoints) {
            $cols .= ', r.waypoints_json';
        }

        $sql = "SELECT $cols
                FROM routes r
                JOIN users u ON u.id = r.user_id
                WHERE r.id = ?
                LIMIT 1";

        $s = db()->prepare($sql);
        $s->execute([$id]);
        $row = $s->fetch();

        if (!$row) return null;

        if (!$hasWaypoints) {
            $row['waypoints_json'] = null;
        }

        return $row;
    }

    /**
     * Все публичные маршруты (без трека — для лёгких списков).
     */
    public static function allPublic(int $limit = 30, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT r.id, r.user_id, r.name, r.description, r.type,
                    r.distance_m, r.elevation_gain_m, r.is_public, r.created_at,
                    u.username, u.display_name, u.avatar_url
             FROM routes r
             JOIN users u ON u.id = r.user_id
             WHERE r.is_public = 1
             ORDER BY r.created_at DESC
             LIMIT :lim OFFSET :off'
        );
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->bindValue(':off', $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Публичные маршруты с треком и данными автора (для страницы routes.php).
     */
    public static function allPublicWithTrack(int $limit = 12, int $offset = 0, string $type = ''): array
    {
        $sql = 'SELECT r.id, r.user_id, r.name, r.description, r.type,
                       r.distance_m, r.elevation_gain_m,
                       r.track_json, r.is_public, r.created_at,
                       u.username, u.display_name, u.avatar_url
                FROM routes r
                JOIN users u ON u.id = r.user_id
                WHERE r.is_public = 1';

        $params = [];

        $allowedTypes = ['run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
        if ($type !== '' && in_array($type, $allowedTypes, true)) {
            $sql .= ' AND r.type = :type';
            $params[':type'] = $type;
        }

        $sql .= ' ORDER BY r.created_at DESC LIMIT :lim OFFSET :off';

        $s = db()->prepare($sql);
        foreach ($params as $k => $v) {
            $s->bindValue($k, $v);
        }
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->bindValue(':off', $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Публичные маршруты с фильтром по типу (без трека).
     */
    public static function allPublicByType(string $type, int $limit = 30): array
    {
        $allowed = ['run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
        if (!in_array($type, $allowed, true)) {
            return self::allPublic($limit);
        }

        $s = db()->prepare(
            'SELECT r.id, r.user_id, r.name, r.description, r.type,
                    r.distance_m, r.elevation_gain_m, r.is_public, r.created_at,
                    u.username, u.display_name, u.avatar_url
             FROM routes r
             JOIN users u ON u.id = r.user_id
             WHERE r.is_public = 1 AND r.type = :type
             ORDER BY r.created_at DESC
             LIMIT :lim'
        );
        $s->bindValue(':type', $type);
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Все маршруты пользователя (включая приватные, без трека).
     */
    public static function byUser(int $userId, int $limit = 100): array
    {
        $s = db()->prepare(
            'SELECT r.id, r.user_id, r.name, r.description, r.type,
                    r.distance_m, r.elevation_gain_m, r.is_public, r.created_at
             FROM routes r
             WHERE r.user_id = ?
             ORDER BY r.created_at DESC
             LIMIT ?'
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Маршруты пользователя с треком и данными автора (для routes.php, profile.php).
     */
    public static function byUserWithTrack(int $userId, int $limit = 12, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT r.id, r.user_id, r.name, r.description, r.type,
                    r.distance_m, r.elevation_gain_m,
                    r.track_json, r.is_public, r.created_at,
                    u.username, u.display_name, u.avatar_url
             FROM routes r
             JOIN users u ON u.id = r.user_id
             WHERE r.user_id = :uid
             ORDER BY r.created_at DESC
             LIMIT :lim OFFSET :off'
        );
        $s->bindValue(':uid', $userId, PDO::PARAM_INT);
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->bindValue(':off', $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Публичные маршруты пользователя (для чужого профиля).
     */
    public static function publicByUser(int $userId, int $limit = 100): array
    {
        $s = db()->prepare(
            'SELECT r.id, r.user_id, r.name, r.description, r.type,
                    r.distance_m, r.elevation_gain_m, r.is_public, r.created_at
             FROM routes r
             WHERE r.user_id = ? AND r.is_public = 1
             ORDER BY r.created_at DESC
             LIMIT ?'
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    // ============================================================
    // ОБНОВЛЕНИЕ И УДАЛЕНИЕ
    // ============================================================

    /**
     * Обновить поля маршрута.
     */
    public static function update(int $id, int $userId, array $d): void
    {
        $allowed = [
            'name', 'description', 'type',
            'distance_m', 'elevation_gain_m',
            'track_json', 'waypoints_json', 'is_public',
        ];

        $set = [];
        $params = [':id' => $id, ':user_id' => $userId];

        foreach ($d as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            if ($k === 'waypoints_json' && !self::supportsWaypoints()) continue;

            $set[] = "$k = :$k";
            $params[":$k"] = $v;
        }

        if (!$set) return;

        $sql = 'UPDATE routes SET ' . implode(', ', $set)
             . ' WHERE id = :id AND user_id = :user_id';

        db()->prepare($sql)->execute($params);
    }

    /**
     * Изменить видимость.
     */
    public static function setPublic(int $id, int $userId, int $isPublic): void
    {
        db()->prepare(
            'UPDATE routes SET is_public = ? WHERE id = ? AND user_id = ?'
        )->execute([$isPublic ? 1 : 0, $id, $userId]);
    }

    /**
     * Удалить маршрут (только владелец).
     */
    public static function delete(int $id, int $userId): void
    {
        db()->prepare(
            'DELETE FROM routes WHERE id = ? AND user_id = ?'
        )->execute([$id, $userId]);
    }

    // ============================================================
    // ПРОВЕРКИ ДОСТУПА
    // ============================================================

    /**
     * Маршрут принадлежит пользователю?
     */
    public static function isOwner(int $routeId, int $userId): bool
    {
        $s = db()->prepare(
            'SELECT 1 FROM routes WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $s->execute([$routeId, $userId]);
        return (bool)$s->fetchColumn();
    }

    /**
     * Пользователь может смотреть маршрут? (публичный или свой)
     */
    public static function canView(int $routeId, ?int $userId): bool
    {
        $s = db()->prepare(
            'SELECT user_id, is_public FROM routes WHERE id = ? LIMIT 1'
        );
        $s->execute([$routeId]);
        $row = $s->fetch();
        if (!$row) return false;
        if ((int)$row['is_public'] === 1) return true;
        if ($userId !== null && (int)$row['user_id'] === $userId) return true;
        return false;
    }

    // ============================================================
    // СТАТИСТИКА
    // ============================================================

    /**
     * Статистика по маршрутам пользователя.
     */
    public static function stats(int $userId): array
    {
        $s = db()->prepare(
            'SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(distance_m), 0) AS dist,
                    COALESCE(SUM(elevation_gain_m), 0) AS elev
             FROM routes
             WHERE user_id = ?'
        );
        $s->execute([$userId]);
        $r = $s->fetch() ?: ['cnt' => 0, 'dist' => 0, 'elev' => 0];

        return [
            'routes'           => (int)$r['cnt'],
            'distance_m'       => (float)$r['dist'],
            'elevation_gain_m' => (float)$r['elev'],
        ];
    }

    /**
     * Сводная статистика по всем публичным маршрутам.
     */
    public static function publicStats(): array
    {
        $s = db()->query(
            'SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(distance_m), 0) AS dist,
                    COALESCE(SUM(elevation_gain_m), 0) AS elev
             FROM routes
             WHERE is_public = 1'
        );
        $r = $s->fetch() ?: ['cnt' => 0, 'dist' => 0, 'elev' => 0];

        return [
            'routes'           => (int)$r['cnt'],
            'distance_m'       => (float)$r['dist'],
            'elevation_gain_m' => (float)$r['elev'],
        ];
    }

    // ============================================================
    // ПОИСК
    // ============================================================

    /**
     * Поиск маршрутов по названию или описанию.
     * Возвращает публичные + свои собственные.
     */
    public static function search(string $q, ?int $viewerId = null, int $limit = 30): array
    {
        $sql = 'SELECT r.id, r.user_id, r.name, r.description, r.type,
                       r.distance_m, r.elevation_gain_m, r.is_public, r.created_at,
                       u.username, u.display_name, u.avatar_url
                FROM routes r
                JOIN users u ON u.id = r.user_id
                WHERE (r.name LIKE :q OR r.description LIKE :q)
                  AND (r.is_public = 1';

        $params = [':q' => '%' . $q . '%'];

        if ($viewerId !== null) {
            $sql .= ' OR r.user_id = :me';
            $params[':me'] = $viewerId;
        }

        $sql .= ') ORDER BY r.created_at DESC LIMIT :lim';

        $s = db()->prepare($sql);
        foreach ($params as $k => $v) {
            $s->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $s->bindValue(':lim', $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    // ============================================================
    // ПАРСИНГ ТРЕКА И МЕТОК
    // ============================================================

    /**
     * Разбирает track_json в массив [{lat,lng}, ...].
     * Валидирует координаты.
     */
    public static function parseTrack(?string $trackJson): array
    {
        if (empty($trackJson)) return [];

        $decoded = json_decode($trackJson, true);
        if (!is_array($decoded)) return [];

        $points = [];
        foreach ($decoded as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            $lat = (float)$p['lat'];
            $lng = (float)$p['lng'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            $points[] = ['lat' => $lat, 'lng' => $lng];
        }
        return $points;
    }

    /**
     * Алиас parseTrack.
     */
    public static function parseTrackJson(?string $trackJson): array
    {
        return self::parseTrack($trackJson);
    }

    /**
     * Разбирает waypoints_json в массив [{lat,lng,name,note}, ...].
     */
    public static function parseWaypoints(?string $waypointsJson): array
    {
        if (empty($waypointsJson)) return [];

        $decoded = json_decode($waypointsJson, true);
        if (!is_array($decoded)) return [];

        $points = [];
        foreach ($decoded as $w) {
            if (!isset($w['lat'], $w['lng'])) continue;
            $lat = (float)$w['lat'];
            $lng = (float)$w['lng'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;

            $points[] = [
                'lat'  => $lat,
                'lng'  => $lng,
                'name' => (string)($w['name'] ?? ''),
                'note' => (string)($w['note'] ?? ''),
            ];
        }
        return $points;
    }

    /**
     * Считает длину трека по haversine.
     */
    public static function trackLength(array $track): float
    {
        if (count($track) < 2) return 0.0;

        $R = 6371000;
        $d = 0.0;

        for ($i = 1; $i < count($track); $i++) {
            $a = $track[$i - 1];
            $b = $track[$i];

            $dLat = deg2rad($b['lat'] - $a['lat']);
            $dLng = deg2rad($b['lng'] - $a['lng']);
            $lat1 = deg2rad($a['lat']);
            $lat2 = deg2rad($b['lat']);

            $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
            $d += 2 * $R * asin(min(1, sqrt($h)));
        }

        return $d;
    }

    // ============================================================
    // ПРОВЕРКИ СХЕМЫ БД
    // ============================================================

    /**
     * Проверяет, есть ли в таблице routes колонка waypoints_json.
     * Кэшируется на время одного запроса.
     */
    public static function supportsWaypoints(): bool
    {
        if (self::$hasWaypointsCol !== null) {
            return self::$hasWaypointsCol;
        }

        try {
            $s = db()->query("SHOW COLUMNS FROM routes LIKE 'waypoints_json'");
            self::$hasWaypointsCol = (bool)$s->fetch();
        } catch (Throwable $e) {
            self::$hasWaypointsCol = false;
        }

        return self::$hasWaypointsCol;
    }
}
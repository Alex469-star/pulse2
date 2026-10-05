<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';

class Club
{
    // ============================================================
    // ЧТЕНИЕ
    // ============================================================

    public static function findById(int $id): ?array
    {
        $s = db()->prepare(
            'SELECT c.*, u.username AS owner_username, u.display_name AS owner_display_name,
                    u.avatar_url AS owner_avatar,
                    cs.total_distance_m, cs.week_distance_m, cs.month_distance_m,
                    cs.total_activities
               FROM clubs c
               JOIN users u ON u.id = c.owner_id
          LEFT JOIN club_stats cs ON cs.club_id = c.id
              WHERE c.id = ? LIMIT 1'
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $s = db()->prepare(
            'SELECT c.*, u.username AS owner_username, u.display_name AS owner_display_name,
                    u.avatar_url AS owner_avatar,
                    cs.total_distance_m, cs.week_distance_m, cs.month_distance_m,
                    cs.total_activities
               FROM clubs c
               JOIN users u ON u.id = c.owner_id
          LEFT JOIN club_stats cs ON cs.club_id = c.id
              WHERE c.slug = ? LIMIT 1'
        );
        $s->execute([$slug]);
        return $s->fetch() ?: null;
    }

    public static function publicList(
        string $q = '',
        string $type = '',
        string $city = '',
        string $sort = 'popular',
        int $limit = 24,
        int $offset = 0
    ): array {
        $where = ['c.is_banned = 0', "c.visibility = 'public'"];
        $params = [];

        if ($q !== '') {
            $where[] = '(c.name LIKE ? OR c.description LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like;
        }
        if ($type !== '') { $where[] = 'c.sport_type = ?'; $params[] = $type; }
        if ($city !== '') { $where[] = 'c.city = ?'; $params[] = $city; }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $orderSql = match ($sort) {
            'new'      => 'c.created_at DESC',
            'distance' => 'cs.week_distance_m DESC, c.member_count DESC',
            'alpha'    => 'c.name ASC',
            default    => 'c.member_count DESC, c.created_at DESC',
        };

        $sql = "SELECT c.*,
                    (SELECT COUNT(*) FROM club_members m WHERE m.club_id = c.id AND m.status = 'active') AS members_active,
                    cs.week_distance_m, cs.total_distance_m
                  FROM clubs c
             LEFT JOIN club_stats cs ON cs.club_id = c.id
                  $whereSql
              ORDER BY $orderSql
                 LIMIT ? OFFSET ?";

        $stmt = db()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p, PDO::PARAM_STR);
        }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function countPublic(string $q = '', string $type = '', string $city = ''): int
    {
        $where = ['is_banned = 0', "visibility = 'public'"];
        $params = [];
        if ($q !== '') {
            $where[] = '(name LIKE ? OR description LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($type !== '') { $where[] = 'sport_type = ?'; $params[] = $type; }
        if ($city !== '') { $where[] = 'city = ?'; $params[] = $city; }
        $s = db()->prepare('SELECT COUNT(*) FROM clubs WHERE ' . implode(' AND ', $where));
        $s->execute($params);
        return (int)$s->fetchColumn();
    }

    // ============================================================
    // СОЗДАНИЕ / РЕДАКТИРОВАНИЕ
    // ============================================================

    public static function create(int $ownerId, array $d): int
    {
        $slug = self::generateSlug($d['name'] ?? 'club');

        $s = db()->prepare(
            'INSERT INTO clubs
                (name, slug, description, city, country, sport_type,
                 visibility, join_policy, avatar_url, cover_url, website_url,
                 owner_id, member_count)
             VALUES
                (:name, :slug, :description, :city, :country, :sport_type,
                 :visibility, :join_policy, :avatar_url, :cover_url, :website_url,
                 :owner_id, 1)'
        );
        $s->execute([
            ':name'         => $d['name'] ?? 'Клуб',
            ':slug'         => $slug,
            ':description'  => $d['description'] ?? null,
            ':city'         => $d['city'] ?? null,
            ':country'      => $d['country'] ?? null,
            ':sport_type'   => $d['sport_type'] ?? 'mixed',
            ':visibility'   => $d['visibility'] ?? 'public',
            ':join_policy'  => $d['join_policy'] ?? 'open',
            ':avatar_url'   => $d['avatar_url'] ?? null,
            ':cover_url'    => $d['cover_url'] ?? null,
            ':website_url'  => $d['website_url'] ?? null,
            ':owner_id'     => $ownerId,
        ]);

        $clubId = (int)db()->lastInsertId();

        db()->prepare(
            'INSERT INTO club_members (club_id, user_id, role, status)
             VALUES (?, ?, "owner", "active")'
        )->execute([$clubId, $ownerId]);

        db()->prepare('INSERT INTO club_stats (club_id) VALUES (?)')->execute([$clubId]);

        return $clubId;
    }

    public static function update(int $id, array $d): void
    {
        $fields = ['name','description','city','country','sport_type',
                   'visibility','join_policy','avatar_url','cover_url','website_url'];
        $setSql = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $d)) {
                $setSql[] = "`$f` = ?";
                $params[] = $d[$f];
            }
        }
        if (!$setSql) return;

        if (!empty($d['name'])) {
            $setSql[] = '`slug` = ?';
            $params[] = self::generateSlug($d['name'], $id);
        }
        $params[] = $id;
        db()->prepare('UPDATE clubs SET ' . implode(', ', $setSql) . ' WHERE id = ?')
            ->execute($params);
    }

    public static function delete(int $id, int $userId): bool
    {
        $c = self::findById($id);
        if (!$c || (int)$c['owner_id'] !== $userId) return false;
        db()->prepare('DELETE FROM clubs WHERE id = ?')->execute([$id]);
        return true;
    }

    private static function generateSlug(string $name, ?int $ignoreId = null): string
    {
        $base = self::transliterate($name);
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($base));
        $base = trim($base, '-') ?: 'club';
        $base = mb_substr($base, 0, 100);

        $slug = $base;
        $i = 1;
        while (true) {
            $sql = 'SELECT id FROM clubs WHERE slug = ?' . ($ignoreId ? ' AND id != ?' : '') . ' LIMIT 1';
            $s = db()->prepare($sql);
            $s->execute($ignoreId ? [$slug, $ignoreId] : [$slug]);
            if (!$s->fetchColumn()) break;
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }

    private static function transliterate(string $s): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
            'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
            'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts',
            'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu',
            'я'=>'ya',
        ];
        return strtr(mb_strtolower($s), $map);
    }

    // ============================================================
    // ЧЛЕНСТВО
    // ============================================================

    public static function roleOf(int $clubId, int $userId): ?string
    {
        $s = db()->prepare(
            'SELECT role FROM club_members
              WHERE club_id = ? AND user_id = ? AND status = "active" LIMIT 1'
        );
        $s->execute([$clubId, $userId]);
        $r = $s->fetchColumn();
        return $r !== false ? (string)$r : null;
    }

    public static function isMember(int $clubId, int $userId): bool
    {
        return self::roleOf($clubId, $userId) !== null;
    }

    public static function canManage(int $clubId, int $userId): bool
    {
        $role = self::roleOf($clubId, $userId);
        return in_array($role, ['owner', 'admin'], true);
    }

    public static function join(int $clubId, int $userId): string
    {
        $club = self::findById($clubId);
        if (!$club) throw new RuntimeException('Клуб не найден');
        if ($club['is_banned']) throw new RuntimeException('Клуб заблокирован');

        $existing = db()->prepare(
            'SELECT status FROM club_members WHERE club_id = ? AND user_id = ?'
        );
        $existing->execute([$clubId, $userId]);
        $st = $existing->fetchColumn();

        $status = match ($club['join_policy']) {
            'open'    => 'active',
            'request' => 'pending',
            'invite'  => 'invited',
            default   => 'active',
        };

        if ($st !== false) {
            db()->prepare(
                'UPDATE club_members SET status = ? WHERE club_id = ? AND user_id = ?'
            )->execute([$status, $clubId, $userId]);
        } else {
            db()->prepare(
                'INSERT INTO club_members (club_id, user_id, role, status)
                 VALUES (?, ?, "member", ?)'
            )->execute([$clubId, $userId, $status]);
        }

        if ($status === 'active') self::recountMembers($clubId);
        return $status;
    }

    public static function leave(int $clubId, int $userId): bool
    {
        $role = self::roleOf($clubId, $userId);
        if ($role === 'owner') return false;

        db()->prepare('DELETE FROM club_members WHERE club_id = ? AND user_id = ?')
            ->execute([$clubId, $userId]);
        self::recountMembers($clubId);
        return true;
    }

    public static function approve(int $clubId, int $userId, int $approverId): void
    {
        db()->prepare(
            'UPDATE club_members
                SET status = "active", approved_by = ?, approved_at = NOW()
              WHERE club_id = ? AND user_id = ?'
        )->execute([$approverId, $clubId, $userId]);
        self::recountMembers($clubId);
    }

    public static function kick(int $clubId, int $userId): void
    {
        db()->prepare('DELETE FROM club_members WHERE club_id = ? AND user_id = ?')
            ->execute([$clubId, $userId]);
        self::recountMembers($clubId);
    }

    public static function setRole(int $clubId, int $userId, string $role): void
    {
        if (!in_array($role, ['admin','moderator','member'], true)) {
            throw new InvalidArgumentException('Неверная роль');
        }
        db()->prepare('UPDATE club_members SET role = ? WHERE club_id = ? AND user_id = ?')
            ->execute([$role, $clubId, $userId]);
    }

    public static function members(int $clubId, string $status = 'active', int $limit = 100): array
    {
        $s = db()->prepare(
            'SELECT m.role, m.status, m.joined_at,
                    u.id, u.username, u.display_name, u.avatar_url,
                    (SELECT COUNT(*) FROM activities a WHERE a.user_id = u.id) AS activities_cnt,
                    (SELECT COALESCE(SUM(distance_m),0) FROM activities a WHERE a.user_id = u.id) AS distance_m
               FROM club_members m
               JOIN users u ON u.id = m.user_id
              WHERE m.club_id = ? AND m.status = ?
           ORDER BY FIELD(m.role, "owner","admin","moderator","member"), m.joined_at ASC
              LIMIT ' . max(1, min(500, $limit))
        );
        $s->execute([$clubId, $status]);
        return $s->fetchAll();
    }

    public static function pendingRequests(int $clubId): array
    {
        $s = db()->prepare(
            'SELECT m.joined_at, u.id, u.username, u.display_name, u.avatar_url
               FROM club_members m
               JOIN users u ON u.id = m.user_id
              WHERE m.club_id = ? AND m.status = "pending"
           ORDER BY m.joined_at ASC'
        );
        $s->execute([$clubId]);
        return $s->fetchAll();
    }

    private static function recountMembers(int $clubId): void
    {
        db()->prepare(
            'UPDATE clubs
                SET member_count = (
                    SELECT COUNT(*) FROM club_members
                     WHERE club_id = ? AND status = "active"
                )
              WHERE id = ?'
        )->execute([$clubId, $clubId]);
    }

    // ============================================================
    // СТЕНА
    // ============================================================

    /**
     * Список корневых постов стены с ответами.
     * У каждого ответа есть reply_to (кому он адресован).
     */
    public static function wallTree(int $clubId, int $limit = 30, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT p.id, p.club_id, p.user_id, p.parent_id, p.reply_to_user_id,
                    p.body, p.is_pinned, p.is_deleted,
                    p.created_at, p.updated_at, p.edited_at,
                    u.username, u.display_name, u.avatar_url
               FROM club_posts p
               JOIN users u ON u.id = p.user_id
              WHERE p.club_id = ? AND p.is_deleted = 0 AND p.parent_id IS NULL
           ORDER BY p.is_pinned DESC, p.created_at DESC
              LIMIT ? OFFSET ?'
        );
        $s->bindValue(1, $clubId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->bindValue(3, $offset, PDO::PARAM_INT);
        $s->execute();
        $roots = $s->fetchAll();

        if (!$roots) return [];

        $ids = array_map(fn($r) => (int)$r['id'], $roots);
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $r = db()->prepare(
            "SELECT p.id, p.club_id, p.user_id, p.parent_id, p.reply_to_user_id,
                    p.body, p.is_deleted, p.created_at, p.updated_at, p.edited_at,
                    u.username, u.display_name, u.avatar_url,
                    ru.username AS reply_to_username,
                    ru.display_name AS reply_to_display_name
               FROM club_posts p
               JOIN users u ON u.id = p.user_id
          LEFT JOIN users ru ON ru.id = p.reply_to_user_id
              WHERE p.parent_id IN ($ph) AND p.is_deleted = 0
           ORDER BY p.created_at ASC"
        );
        $r->execute($ids);

        $replies = [];
        foreach ($r->fetchAll() as $row) {
            $replies[(int)$row['parent_id']][] = $row;
        }

        foreach ($roots as &$root) {
            $root['replies'] = $replies[(int)$root['id']] ?? [];
        }
        unset($root);

        return $roots;
    }

    /**
     * Один пост (для проверки прав и parent).
     */
    public static function wallPost(int $postId): ?array
    {
        $s = db()->prepare(
            'SELECT p.*, u.username, u.display_name, u.avatar_url
               FROM club_posts p
               JOIN users u ON u.id = p.user_id
              WHERE p.id = ? LIMIT 1'
        );
        $s->execute([$postId]);
        return $s->fetch() ?: null;
    }

    /**
     * Создать пост на стене или ответ.
     *
     * @param int      $clubId
     * @param int      $userId
     * @param string   $body
     * @param int|null $parentId       — ID корневого поста, если это ответ
     * @param int|null $replyToUserId  — ID пользователя, которому адресован ответ
     */
    public static function addWallPost(
        int $clubId,
        int $userId,
        string $body,
        ?int $parentId = null,
        ?int $replyToUserId = null
    ): int {
        $s = db()->prepare(
            'INSERT INTO club_posts (club_id, user_id, body, parent_id, reply_to_user_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $s->execute([$clubId, $userId, $body, $parentId, $replyToUserId]);
        return (int)db()->lastInsertId();
    }

    /**
     * Редактировать пост.
     */
    public static function editWallPost(int $postId, int $userId, string $body, bool $isManager = false): bool
    {
        $row = self::wallPost($postId);
        if (!$row) return false;
        if ((int)$row['user_id'] !== $userId && !$isManager) return false;

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 1000) return false;

        db()->prepare(
            'UPDATE club_posts
                SET body = ?, edited_at = NOW()
              WHERE id = ?'
        )->execute([$body, $postId]);
        return true;
    }

    /**
     * Удалить (soft) пост.
     */
    public static function deleteWallPost(int $postId): void
    {
        db()->prepare('UPDATE club_posts SET is_deleted = 1 WHERE id = ?')->execute([$postId]);
    }

    // ============================================================
    // АКТИВНОСТИ КЛУБА
    // ============================================================

    /**
     * Активности участников клуба с треками для мини-карт.
     */
    public static function activitiesFeed(int $clubId, int $limit = 30, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT a.id, a.user_id, a.type, a.title, a.description,
                    a.started_at, a.created_at, a.duration_sec,
                    a.distance_m, a.elevation_gain_m,
                    a.avg_speed_mps, a.max_speed_mps,
                    a.avg_hr, a.max_hr,
                    a.avg_power_w, a.max_power_w,
                    a.avg_cadence, a.max_cadence,
                    a.track_json, a.visibility,
                    u.username, u.display_name, u.avatar_url,
                    (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id) AS likes_count,
                    (SELECT COUNT(*) FROM activity_comments c WHERE c.activity_id = a.id) AS comments_count,
                    (SELECT COUNT(*) FROM activity_photos p WHERE p.activity_id = a.id) AS photos_count
               FROM activities a
               JOIN users u ON u.id = a.user_id
               JOIN club_members m ON m.user_id = u.id
              WHERE m.club_id = ? AND m.status = "active"
                AND a.visibility = "public"
           ORDER BY COALESCE(a.started_at, a.created_at) DESC
              LIMIT ? OFFSET ?'
        );
        $s->bindValue(1, $clubId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->bindValue(3, $offset, PDO::PARAM_INT);
        $s->execute();
        $rows = $s->fetchAll();

        foreach ($rows as &$r) {
            $r['map_points'] = self::simplifyTrack($r['track_json'] ?? null, 100);
            unset($r['track_json']);
        }
        unset($r);

        return $rows;
    }

    private static function simplifyTrack(?string $json, int $maxPoints = 100): array
    {
        if (empty($json)) return [];
        $raw = json_decode($json, true);
        if (!is_array($raw) || count($raw) < 2) return [];

        $pts = [];
        foreach ($raw as $p) {
            if (isset($p['lat'], $p['lng'])) {
                $pts[] = ['lat' => round((float)$p['lat'], 5), 'lng' => round((float)$p['lng'], 5)];
            }
        }
        if (count($pts) > $maxPoints) {
            $step = (int)ceil(count($pts) / $maxPoints);
            $out = [];
            for ($i = 0; $i < count($pts); $i += $step) $out[] = $pts[$i];
            if (end($out) !== end($pts)) $out[] = end($pts);
            return $out;
        }
        return $pts;
    }

    // ============================================================
    // СТАТИСТИКА
    // ============================================================

    public static function leaderboard(int $clubId, string $period = 'week', int $limit = 20): array
    {
        $interval = match ($period) {
            'week'  => '7 DAY',
            'month' => '30 DAY',
            'year'  => '365 DAY',
            default => '7 DAY',
        };

        $s = db()->prepare(
            "SELECT u.id, u.username, u.display_name, u.avatar_url,
                    COUNT(a.id) AS activities_cnt,
                    COALESCE(SUM(a.distance_m), 0) AS distance_m,
                    COALESCE(SUM(a.duration_sec), 0) AS duration_sec
               FROM club_members m
               JOIN users u ON u.id = m.user_id
          LEFT JOIN activities a ON a.user_id = u.id
                AND a.visibility = 'public'
                AND COALESCE(a.started_at, a.created_at) >= NOW() - INTERVAL $interval
              WHERE m.club_id = ? AND m.status = 'active'
           GROUP BY u.id
           ORDER BY distance_m DESC
              LIMIT " . max(1, min(100, $limit))
        );
        $s->execute([$clubId]);
        return $s->fetchAll();
    }

    /**
     * Живые агрегаты клуба. Используется, если club_stats пуст.
     */
    public static function liveStats(int $clubId): array
    {
        try {
            $s = db()->prepare(
                "SELECT COUNT(*) AS total_activities,
                        COALESCE(SUM(a.distance_m), 0) AS total_distance_m,
                        COALESCE(SUM(a.duration_sec), 0) AS total_duration_sec,
                        COALESCE(SUM(CASE WHEN COALESCE(a.started_at, a.created_at) >= NOW() - INTERVAL 7 DAY
                                          THEN a.distance_m ELSE 0 END), 0) AS week_distance_m,
                        COALESCE(SUM(CASE WHEN COALESCE(a.started_at, a.created_at) >= NOW() - INTERVAL 30 DAY
                                          THEN a.distance_m ELSE 0 END), 0) AS month_distance_m
                   FROM activities a
                   JOIN club_members m ON m.user_id = a.user_id
                  WHERE m.club_id = ? AND m.status = 'active'
                    AND a.visibility = 'public'"
            );
            $s->execute([$clubId]);
            return $s->fetch() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function recalcStats(int $clubId): void
    {
        $row = self::liveStats($clubId);

        db()->prepare(
            'INSERT INTO club_stats
                (club_id, total_distance_m, total_duration_sec, total_activities,
                 week_distance_m, month_distance_m)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                total_distance_m = VALUES(total_distance_m),
                total_duration_sec = VALUES(total_duration_sec),
                total_activities = VALUES(total_activities),
                week_distance_m = VALUES(week_distance_m),
                month_distance_m = VALUES(month_distance_m)'
        )->execute([
            $clubId,
            (int)($row['total_distance_m'] ?? 0),
            (int)($row['total_duration_sec'] ?? 0),
            (int)($row['total_activities'] ?? 0),
            (int)($row['week_distance_m'] ?? 0),
            (int)($row['month_distance_m'] ?? 0),
        ]);
    }

    // ============================================================
    // ИНВАЙТЫ
    // ============================================================

    public static function createInvite(int $clubId, int $userId, ?int $maxUses = null, ?int $ttlDays = 7): string
    {
        $token = bin2hex(random_bytes(16));
        $expires = $ttlDays ? date('Y-m-d H:i:s', time() + $ttlDays * 86400) : null;

        db()->prepare(
            'INSERT INTO club_invites (club_id, token, created_by, expires_at, max_uses)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$clubId, $token, $userId, $expires, $maxUses]);

        return $token;
    }

    public static function findInvite(string $token): ?array
    {
        $s = db()->prepare(
            'SELECT i.*, c.name AS club_name, c.slug AS club_slug
               FROM club_invites i
               JOIN clubs c ON c.id = i.club_id
              WHERE i.token = ? LIMIT 1'
        );
        $s->execute([$token]);
        $row = $s->fetch();
        if (!$row) return null;
        if ($row['expires_at'] && strtotime((string)$row['expires_at']) < time()) return null;
        if ($row['max_uses'] && (int)$row['used_count'] >= (int)$row['max_uses']) return null;
        return $row;
    }

    public static function consumeInvite(int $inviteId): void
    {
        db()->prepare('UPDATE club_invites SET used_count = used_count + 1 WHERE id = ?')
            ->execute([$inviteId]);
    }

    // ============================================================
    // УВЕДОМЛЕНИЯ
    // ============================================================

        public static function notifyNewMember(int $clubId, int $userId): void
    {
        if (!class_exists('Notification')) return;

        $club = self::findById($clubId);
        if (!$club) return;

        $user = db()->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
        $user->execute([$userId]);
        $u = $user->fetch();
        if (!$u) return;

        Notification::push(
            (int)$club['owner_id'],
            'club_join',
            $userId,
            'club:' . $clubId,                    // ← составной тип
            $clubId,
            $u['display_name'] . ' вступил в клуб «' . $club['name'] . '»'
        );
    }

        public static function notifyWallPost(int $clubId, int $authorId, string $excerpt): void
    {
        if (!class_exists('Notification')) return;

        $club = self::findById($clubId);
        if (!$club) return;

        $author = db()->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
        $author->execute([$authorId]);
        $authorName = (string)$author->fetchColumn() ?: 'Участник';

        $recipients = db()->prepare(
            'SELECT user_id FROM club_members
              WHERE club_id = ?
                AND status = "active"
                AND role IN ("owner","admin","moderator")
                AND user_id != ?'
        );
        $recipients->execute([$clubId, $authorId]);
        $ids = $recipients->fetchAll(PDO::FETCH_COLUMN);

        // ID последнего вставленного поста (addWallPost вызывается ДО notifyWallPost)
        $postId = (int)db()->lastInsertId();

        $text = $authorName . ' написал на стене клуба «' . $club['name'] . '»: '
              . mb_substr($excerpt, 0, 80) . (mb_strlen($excerpt) > 80 ? '…' : '');

        foreach ($ids as $uid) {
            Notification::push(
    (int)$uid,
    'club_post',
    $authorId,
    'club_post:' . $clubId,
    (int)db()->lastInsertId(),
    $text
);
        }
    }

    public static function notifyRoleChange(int $clubId, int $userId, string $newRole, int $byUserId): void
    {
        if (!class_exists('Notification')) return;

        $club = self::findById($clubId);
        if (!$club) return;

        $roleLabel = match ($newRole) {
            'admin'     => 'администратора',
            'moderator' => 'модератора',
            'member'    => 'участника',
            default     => $newRole,
        };

        Notification::push(
            $userId,
            'club_role',
            $byUserId,
            'club:' . $clubId,                    // ← составной тип
            $clubId,
            'Ваша роль в клубе «' . $club['name'] . '» изменена на ' . $roleLabel
        );
    }
}
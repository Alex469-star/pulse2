<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Notification.php';

class Broadcast
{
    // ============================================================
    // СОЗДАНИЕ
    // ============================================================

    public static function create(int $adminId, array $d): int
    {
        $s = db()->prepare(
            'INSERT INTO admin_broadcasts
                (admin_id, title, body, type, url, audience, audience_meta, status, scheduled_at)
             VALUES
                (:admin_id, :title, :body, :type, :url, :audience, :audience_meta, :status, :scheduled_at)'
        );
        $s->execute([
            ':admin_id'      => $adminId,
            ':title'         => $d['title'] ?? 'Сообщение',
            ':body'          => $d['body'] ?? '',
            ':type'          => $d['type'] ?? 'system',
            ':url'           => $d['url'] ?? null,
            ':audience'      => $d['audience'] ?? 'all',
            ':audience_meta' => isset($d['audience_meta']) ? json_encode($d['audience_meta'], JSON_UNESCAPED_UNICODE) : null,
            ':status'        => $d['status'] ?? 'draft',
            ':scheduled_at'  => $d['scheduled_at'] ?? null,
        ]);
        return (int)db()->lastInsertId();
    }

    public static function update(int $id, array $d): void
    {
        $fields = ['title','body','type','url','audience','audience_meta','status','scheduled_at'];
        $setSql = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $d)) {
                $setSql[] = "`$f` = ?";
                $params[] = $f === 'audience_meta' && is_array($d[$f])
                    ? json_encode($d[$f], JSON_UNESCAPED_UNICODE)
                    : $d[$f];
            }
        }
        if (!$setSql) return;
        $params[] = $id;
        db()->prepare('UPDATE admin_broadcasts SET ' . implode(', ', $setSql) . ' WHERE id = ?')
            ->execute($params);
    }

    public static function delete(int $id): void
    {
        db()->prepare('DELETE FROM admin_broadcasts WHERE id = ?')->execute([$id]);
    }

    // ============================================================
    // ЧТЕНИЕ
    // ============================================================

    public static function findById(int $id): ?array
    {
        $s = db()->prepare(
            'SELECT b.*, a.username AS admin_username
               FROM admin_broadcasts b
          LEFT JOIN admins a ON a.id = b.admin_id
              WHERE b.id = ? LIMIT 1'
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function listAll(int $limit = 50, int $offset = 0): array
    {
        $s = db()->prepare(
            'SELECT b.*, a.username AS admin_username,
                    (SELECT COUNT(*) FROM admin_broadcast_recipients r WHERE r.broadcast_id = b.id) AS delivered
               FROM admin_broadcasts b
          LEFT JOIN admins a ON a.id = b.admin_id
           ORDER BY b.id DESC
              LIMIT ? OFFSET ?'
        );
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->bindValue(2, $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    // ============================================================
    // АУДИТОРИИ
    // ============================================================

    /**
     * Возвращает массив user_id по правилам аудитории.
     */
    public static function resolveAudience(array $broadcast): array
    {
        $audience = (string)($broadcast['audience'] ?? 'all');
        $meta = [];
        if (!empty($broadcast['audience_meta'])) {
            $meta = is_array($broadcast['audience_meta'])
                ? $broadcast['audience_meta']
                : (json_decode((string)$broadcast['audience_meta'], true) ?: []);
        }

        switch ($audience) {
            case 'active':
                // Заходили за последние 30 дней
                return self::ids("
                    SELECT id FROM users
                     WHERE is_banned = 0
                       AND last_seen_at IS NOT NULL
                       AND last_seen_at >= NOW() - INTERVAL 30 DAY
                ");

            case 'inactive':
                // Не заходили больше 60 дней
                return self::ids("
                    SELECT id FROM users
                     WHERE is_banned = 0
                       AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 60 DAY)
                ");

            case 'new':
                // Зарегистрированы за последние 14 дней
                return self::ids("
                    SELECT id FROM users
                     WHERE is_banned = 0
                       AND created_at >= NOW() - INTERVAL 14 DAY
                ");

            case 'club':
                $clubId = (int)($meta['club_id'] ?? 0);
                if ($clubId <= 0) return [];
                $s = db()->prepare("
                    SELECT user_id FROM club_members
                     WHERE club_id = ? AND status = 'active'
                ");
                $s->execute([$clubId]);
                return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));

            case 'role':
                $role = (string)($meta['role'] ?? '');
                if ($role === 'admins') {
                    $s = db()->query("
                        SELECT user_id FROM club_members
                         WHERE role IN ('owner','admin') AND status = 'active'
                         GROUP BY user_id
                    ");
                    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
                }
                if ($role === 'creators') {
                    // Кто создал хотя бы 3 активности
                    return self::ids("
                        SELECT a.user_id
                          FROM activities a
                      GROUP BY a.user_id
                        HAVING COUNT(*) >= 3
                    ");
                }
                return [];

            case 'specific':
                $ids = $meta['user_ids'] ?? [];
                if (!is_array($ids)) return [];
                $ids = array_values(array_filter(array_map('intval', $ids)));
                if (!$ids) return [];
                // Проверим, что пользователи существуют
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $s = db()->prepare("SELECT id FROM users WHERE id IN ($ph) AND is_banned = 0");
                $s->execute($ids);
                return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));

            case 'all':
            default:
                return self::ids("SELECT id FROM users WHERE is_banned = 0");
        }
    }

    private static function ids(string $sql): array
    {
        $rows = db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows);
    }

    // ============================================================
    // ОТПРАВКА
    // ============================================================

    /**
     * Разослать уведомления по списку user_id.
     * Возвращает [отправлено, ошибок].
     */
    public static function send(int $broadcastId, array $userIds): array
    {
        $b = self::findById($broadcastId);
        if (!$b) throw new RuntimeException('Рассылка не найдена');

                $title = trim((string)$b['title']);
        $body  = trim((string)$b['body']);
        $url   = trim((string)($b['url'] ?? ''));

        // Текст без URL — ссылка пойдёт отдельно
        $text = $title;
        if ($body !== '') {
            $text .= ': ' . $body;
        }
        $text = mb_substr($text, 0, 255);

        // Абсолютный URL
        $absUrl = null;
        if ($url !== '') {
            $absUrl = preg_match('~^https?://~i', $url)
                ? $url
                : rtrim((string)config('base_url'), '/') . '/' . ltrim($url, '/');
        }

        $text = mb_substr($text, 0, 255); // ограничение notifications.message

        db()->prepare(
            "UPDATE admin_broadcasts
                SET status = 'sending', started_at = NOW(), total_count = ?
              WHERE id = ?"
        )->execute([count($userIds), $broadcastId]);

        $sent = 0;
        $failed = 0;
        $insertR = db()->prepare(
            'INSERT IGNORE INTO admin_broadcast_recipients (broadcast_id, user_id, error)
             VALUES (?, ?, ?)'
        );

        foreach ($userIds as $uid) {
            try {
                Notification::push(
                    (int)$uid,
                    'system',
                    null,
                    null,
                    null,
                    $text,
                    $absUrl
                );
                $insertR->execute([$broadcastId, $uid, null]);
                $sent++;
            } catch (Throwable $e) {
                $insertR->execute([$broadcastId, $uid, mb_substr($e->getMessage(), 0, 255)]);
                $failed++;
            }
        }

        db()->prepare(
            "UPDATE admin_broadcasts
                SET status = 'sent',
                    sent_count = ?, failed_count = ?,
                    finished_at = NOW()
              WHERE id = ?"
        )->execute([$sent, $failed, $broadcastId]);

        return [$sent, $failed];
    }

    /**
     * Отправить тестовое уведомление себе.
     */
        public static function sendTest(int $broadcastId, int $adminUserId): void
    {
        $b = self::findById($broadcastId);
        if (!$b) throw new RuntimeException('Рассылка не найдена');

        $text = trim((string)$b['title']);
        if (!empty($b['body'])) $text .= ': ' . trim((string)$b['body']);

        $url = trim((string)($b['url'] ?? ''));
        $absUrl = null;
        if ($url !== '') {
            $absUrl = preg_match('~^https?://~i', $url)
                ? $url
                : rtrim((string)config('base_url'), '/') . '/' . ltrim($url, '/');
        }

        Notification::push(
            $adminUserId,
            'system',
            null,
            null,
            null,
            mb_substr('[ТЕСТ] ' . $text, 0, 255),
            $absUrl
        );
    }
}
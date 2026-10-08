<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Notification.php';

$me = api_require_user();

// ---- POST: пометить прочитанным ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    api_check_csrf();
    $input = json_input();
    $action = (string)($input['action'] ?? '');

    if ($action === 'mark_read' && !empty($input['id'])) {
        Notification::markRead((int)$input['id'], (int)$me['id']);
        json_ok(['marked' => true]);
    }
    if ($action === 'mark_all_read') {
        Notification::markAllRead((int)$me['id']);
        json_ok(['marked' => true]);
    }
    json_err('Unknown action', 400);
}

// ---- GET: список и счётчик ----
$sinceId = (int)($_GET['since_id'] ?? 0);
$limit   = min(50, max(1, (int)($_GET['limit'] ?? 20)));

try {
    $unread = Notification::unreadCount((int)$me['id']);
    $items  = Notification::allForUser((int)$me['id'], $limit);

    $latestId = 0;
    $out = [];
    foreach ($items as $n) {
        $id = (int)$n['id'];
        if ($id > $latestId) $latestId = $id;

        if ($sinceId > 0 && $id <= $sinceId) continue;

        $out[] = [
            'id'           => $id,
            'type'         => (string)$n['type'],
            'actor_id'     => $n['actor_id'] !== null ? (int)$n['actor_id'] : null,
            'actor_name'   => (string)($n['display_name'] ?? ''),
            'actor_user'   => (string)($n['username'] ?? ''),
            'actor_avatar' => $n['avatar_url'] ?: null,
            'target_type'  => $n['target_type'] ?? null,
            'target_id'    => $n['target_id'] !== null ? (int)$n['target_id'] : null,
            'message'      => (string)($n['message'] ?? ''),
            'url'          => (string)($n['url'] ?? ''),
            'is_read'      => (int)$n['is_read'] === 1,
            'created_at'   => (string)$n['created_at'],
            'time_ago'     => time_ago((string)$n['created_at']),
        ];
    }

    json_ok([
        'unread'    => $unread,
        'latest_id' => $latestId,
        'items'     => $out,
    ]);
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
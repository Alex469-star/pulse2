<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Comment.php';

api_check_csrf();
$me = api_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$input = json_input();
$activityId = (int)($input['activity_id'] ?? 0);
$body       = trim((string)($input['body'] ?? ''));
$parentId   = isset($input['parent_id']) ? (int)$input['parent_id'] : null;
if ($parentId !== null && $parentId <= 0) $parentId = null;

if ($activityId <= 0 || $body === '') {
    json_err('activity_id and body required', 400);
}

if (mb_strlen($body) > 1000) {
    json_err('Комментарий слишком длинный', 400);
}

try {
    $commentId = Comment::add($activityId, (int)$me['id'], $body);

    $stmt = db()->prepare(
        'SELECT c.id, c.body, c.created_at, u.username, u.display_name, u.avatar_url
         FROM activity_comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.id = ?'
    );
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch();

    if (!$comment) {
        json_err('Комментарий не найден', 500);
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM activity_comments WHERE activity_id = ?');
    $stmt->execute([$activityId]);
    $count = (int)$stmt->fetchColumn();

    json_ok([
        'comment' => [
            'id'           => (int)$comment['id'],
            'body'         => (string)$comment['body'],
            'created_at'   => (string)$comment['created_at'],
            'username'     => (string)$comment['username'],
            'display_name' => (string)$comment['display_name'],
            'avatar_url'   => $comment['avatar_url'] ?: null,
            'initial'      => mb_substr((string)$comment['display_name'], 0, 1),
            'time_ago'     => time_ago((string)$comment['created_at']),
            'profile_url'  => url('profile.php?u=' . urlencode((string)$comment['username'])),
        ],
        'count' => $count,
    ], 201);
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../models/ClubEvent.php';
require_once __DIR__ . '/../models/ClubEventComment.php';


api_check_csrf();
$me = api_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$input = json_input();
$eventId  = (int)($input['event_id'] ?? 0);
$body     = trim((string)($input['body'] ?? ''));
$parentId = isset($input['parent_id']) ? (int)$input['parent_id'] : null;
if ($parentId !== null && $parentId <= 0) $parentId = null;

if ($eventId <= 0 || $body === '') {
    json_err('event_id and body required', 400);
}
if (mb_strlen($body) > 4000) {
    json_err('Слишком длинный комментарий', 400);
}

error_log('[club-event-comment] input: ' . json_encode($input));
error_log('[club-event-comment] eventId: ' . $eventId);

$event = ClubEvent::findById($eventId);
if (!$event) json_err('Событие не найдено', 404);

// Проверка доступа: тот же набор, что и на странице
$clubId = (int)$event['club_id'];
$role = Club::roleOf($clubId, (int)$me['id']); // если метода нет — замените на прямой SQL
$canManage = ClubEvent::canManage($eventId, (int)$me['id']);

if ($event['visibility'] === 'admins' && !$canManage) json_err('Нет доступа', 403);
if ($event['visibility'] === 'members' && !$role && !$canManage) json_err('Только для участников клуба', 403);

try {
    $id = ClubEventComment::add($eventId, (int)$me['id'], $body, $parentId);
    $comment = ClubEventComment::findById($id);

    json_ok([
        'comment' => [
            'id'           => (int)$comment['id'],
            'parent_id'    => $comment['parent_id'] !== null ? (int)$comment['parent_id'] : null,
            'body'         => (string)$comment['body'],
            'created_at'   => (string)$comment['created_at'],
            'display_name' => (string)$comment['display_name'],
            'username'     => (string)$comment['username'],
            'avatar_url'   => $comment['avatar_url'] ?: null,
            'initial'      => mb_substr((string)$comment['display_name'], 0, 1),
            'time_ago'     => time_ago((string)$comment['created_at']),
            'profile_url'  => url('profile.php?u=' . urlencode((string)$comment['username'])),
            'can_edit'     => true,
            'can_delete'   => true,
        ],
    ], 201);
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
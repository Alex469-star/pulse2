<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/ClubEvent.php';
require_once __DIR__ . '/../models/ClubEventComment.php';

api_check_csrf();
$me = api_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

$input = json_input();
$commentId = (int)($input['comment_id'] ?? 0);
if ($commentId <= 0) json_err('comment_id required', 400);

$comment = ClubEventComment::findById($commentId);
if (!$comment) json_err('Комментарий не найден', 404);

$eventId = (int)$comment['event_id'];
$canManage = ClubEvent::canManage($eventId, (int)$me['id']);

if ((int)$comment['user_id'] !== (int)$me['id'] && !$canManage) {
    json_err('Нет доступа', 403);
}

try {
    ClubEventComment::delete($commentId, (int)$me['id'], $canManage);
    json_ok(['deleted' => true]);
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
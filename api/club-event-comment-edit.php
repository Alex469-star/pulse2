<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/ClubEventComment.php';

api_check_csrf();
$me = api_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

$input = json_input();
$commentId = (int)($input['comment_id'] ?? 0);
$body      = trim((string)($input['body'] ?? ''));

if ($commentId <= 0 || $body === '') json_err('comment_id and body required', 400);
if (mb_strlen($body) > 4000) json_err('Слишком длинный комментарий', 400);

$comment = ClubEventComment::findById($commentId);
if (!$comment) json_err('Комментарий не найден', 404);
if ((int)$comment['user_id'] !== (int)$me['id']) json_err('Нет доступа', 403);

try {
    $ok = ClubEventComment::update($commentId, (int)$me['id'], $body);
    if (!$ok) json_err('Не удалось сохранить', 400);

    json_ok([
        'comment' => [
            'id'         => $commentId,
            'body'       => $body,
            'edited_at'  => date('Y-m-d H:i:s'),
        ],
    ]);
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
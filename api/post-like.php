<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Post.php';

api_check_csrf();
$me = api_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

$input = json_input();
$postId = (int)($input['post_id'] ?? 0);
if ($postId <= 0) json_err('post_id required', 400);

try {
    $liked = Post::toggleLike((int)$me['id'], $postId);
    $stmt = db()->prepare('SELECT COUNT(*) FROM post_likes WHERE post_id = ?');
    $stmt->execute([$postId]);
    $count = (int)$stmt->fetchColumn();

    json_ok(['liked' => $liked, 'count' => $count]);
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
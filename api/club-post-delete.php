<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/Club.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');

function ok($d = null) { echo json_encode(['ok'=>true,'data'=>$d], JSON_UNESCAPED_UNICODE); exit; }
function err(string $m, int $c = 400) { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

$me = current_user();
if (!$me) err('Требуется вход', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only', 405);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$csrf = $input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
csrf_check($csrf);

$postId = (int)($input['post_id'] ?? 0);
if ($postId <= 0) err('post_id обязателен');

$post = Club::wallPost($postId);
if (!$post) err('Пост не найден', 404);

$isAuthor = (int)$post['user_id'] === (int)$me['id'];
$canManage = Club::canManage((int)$post['club_id'], (int)$me['id']);
if (!$isAuthor && !$canManage) err('Нет прав', 403);

db()->prepare('UPDATE club_posts SET is_deleted = 1 WHERE id = ?')->execute([$postId]);
ok(['deleted' => true]);
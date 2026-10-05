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
$body   = trim((string)($input['body'] ?? ''));

if ($postId <= 0) err('post_id обязателен');
if (mb_strlen($body) < 1 || mb_strlen($body) > 1000) err('Текст 1–1000 символов');

$post = Club::wallPost($postId);
if (!$post) err('Пост не найден', 404);

$canManage = Club::canManage((int)$post['club_id'], (int)$me['id']);
$ok = Club::editWallPost($postId, (int)$me['id'], $body, $canManage);
if (!$ok) err('Нет прав или пустой текст', 403);

ok(['body' => $body, 'edited_at' => date('c')]);
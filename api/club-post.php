<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../models/Notification.php';

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

$clubId          = (int)($input['club_id'] ?? 0);
$body            = trim((string)($input['body'] ?? ''));
$parentId        = (int)($input['parent_id'] ?? 0) ?: null;
$replyToUserId   = (int)($input['reply_to_user_id'] ?? 0) ?: null;

if ($clubId <= 0) err('club_id обязателен');
if ($body === '') err('Текст не может быть пустым');
if (mb_strlen($body) > 1000) err('Максимум 1000 символов');

$club = Club::findById($clubId);
if (!$club) err('Клуб не найден', 404);
if ($club['is_banned']) err('Клуб заблокирован', 403);

$role = Club::roleOf($clubId, (int)$me['id']);
if (!$role) err('Вы не участник клуба', 403);

// Валидация parent_id — должен быть корневой пост этого клуба
if ($parentId !== null) {
    $parent = Club::wallPost($parentId);
    if (!$parent) err('Родительский пост не найден', 404);
    if ((int)$parent['club_id'] !== $clubId) err('Родительский пост из другого клуба');

    // Если parent_id указывает на ответ, поднимаемся до корня
    if (!empty($parent['parent_id'])) {
        $parentId = (int)$parent['parent_id'];
        $root = Club::wallPost($parentId);
        if (!$root || (int)$root['club_id'] !== $clubId) {
            err('Не удалось найти корневой пост');
        }
    }
}

// Валидация reply_to_user_id
if ($replyToUserId !== null) {
    $u = db()->prepare('SELECT id, display_name FROM users WHERE id = ? LIMIT 1');
    $u->execute([$replyToUserId]);
    if (!$u->fetchColumn()) {
        $replyToUserId = null;
    }
}

try {
    $postId = Club::addWallPost($clubId, (int)$me['id'], $body, $parentId, $replyToUserId);

            // Уведомления
    $rootPostId = $parentId ?? $postId;
    if ($parentId !== null) {
        $parent = Club::wallPost($parentId);
        if ($parent && !empty($parent['parent_id'])) {
            $rootPostId = (int)$parent['parent_id'];
        }
    }

    $clubTargetType = 'club_post:' . $clubId;

    if ($replyToUserId !== null && $replyToUserId !== (int)$me['id']) {
        Notification::push(
            $replyToUserId,
            'comment',
            (int)$me['id'],
            $clubTargetType,
            $rootPostId,
            mb_substr($me['display_name'] . ' ответил вам в клубе «' . $club['name'] . '»', 0, 255)
        );
    } elseif ($parentId !== null) {
        $root = Club::wallPost($rootPostId);
        if ($root && (int)$root['user_id'] !== (int)$me['id']) {
            Notification::push(
                (int)$root['user_id'],
                'comment',
                (int)$me['id'],
                $clubTargetType,
                $rootPostId,
                mb_substr($me['display_name'] . ' ответил в вашем треде', 0, 255)
            );
        }
    } else {
        if (method_exists('Club', 'notifyWallPost')) {
            Club::notifyWallPost($clubId, (int)$me['id'], $body);
        }
    }

    // Готовим данные для ответа
    $replyToName = null;
    if ($replyToUserId !== null) {
        $u = db()->prepare('SELECT display_name, username FROM users WHERE id = ? LIMIT 1');
        $u->execute([$replyToUserId]);
        $ru = $u->fetch();
        if ($ru) {
            $replyToName = $ru['display_name'];
        }
    }

    ok([
        'id'                => $postId,
        'club_id'           => $clubId,
        'parent_id'         => $parentId,
        'reply_to_user_id'  => $replyToUserId,
        'reply_to_name'     => $replyToName,
        'body'              => $body,
        'user_id'           => (int)$me['id'],
        'username'          => $me['username'],
        'display_name'      => $me['display_name'],
        'avatar_url'        => $me['avatar_url'] ?? null,
        'created_at'        => date('c'),
        'edited_at'         => null,
        'is_pinned'         => 0,
    ]);
} catch (Throwable $e) {
    err('Ошибка: ' . $e->getMessage(), 500);
}
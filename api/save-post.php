<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Post.php';

api_check_csrf();
$me = api_require_user();

$postId = (int)($_POST['post_id'] ?? 0);
$title  = trim((string)($_POST['title'] ?? ''));
$body   = trim((string)($_POST['body'] ?? ''));
$vis    = (string)($_POST['visibility'] ?? 'public');

if ($title === '') {
    json_err('Введите заголовок', 400);
}
if (mb_strlen($title) > 190) {
    json_err('Максимум 190 символов в заголовке', 400);
}
if ($body === '') {
    json_err('Введите текст', 400);
}
if (mb_strlen($body) > 20000) {
    json_err('Максимум 20000 символов в тексте', 400);
}

$allowedVis = ['public', 'followers', 'private'];
if (!in_array($vis, $allowedVis, true)) $vis = 'public';

try {
    if ($postId > 0) {
        // Редактирование
        $existing = Post::findById($postId);
        if (!$existing || (int)$existing['user_id'] !== (int)$me['id']) {
            json_err('Запись не найдена', 403);
        }
        Post::update($postId, (int)$me['id'], [
            'title'      => $title,
            'body'       => $body,
            'visibility' => $vis,
        ]);
        $isNew = false;
    } else {
        // Создание
        $postId = Post::create((int)$me['id'], [
            'title'      => $title,
            'body'       => $body,
            'visibility' => $vis,
        ]);
        $isNew = true;
    }
} catch (Throwable $ex) {
    json_err('Не удалось сохранить: ' . $ex->getMessage(), 500);
}

json_ok([
    'post_id' => $postId,
    'is_new'  => $isNew,
]);
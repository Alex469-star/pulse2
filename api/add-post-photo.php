<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Post.php';

api_check_csrf();
$me = api_require_user();

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_err('Файл не был загружен', 400);
}

$postId = (int)($_POST['post_id'] ?? 0);
if ($postId <= 0) {
    json_err('Не указан ID записи', 400);
}

$post = Post::findById($postId);
if (!$post || (int)$post['user_id'] !== (int)$me['id']) {
    json_err('Запись не найдена', 404);
}

$existing = Post::photos($postId);
if (count($existing) >= 10) {
    json_err('Достигнут лимит 10 фото', 400);
}

$order = (int)($_POST['order'] ?? count($existing));

// Используем существующий upload_photo — он проверяет mime и жмёт до 1600px
$res = upload_photo([
    'tmp_name' => $_FILES['file']['tmp_name'],
    'size'     => (int)$_FILES['file']['size'],
    'error'    => (int)$_FILES['file']['error'],
], 'posts', (int)$me['id']);

if (!$res['url']) {
    json_err($res['error'] ?? 'Не удалось сохранить фото', 400);
}

$photoId = Post::addPhoto($postId, $res['url'], $order);

json_ok([
    'photo_id' => $photoId,
    'url'      => $res['url'],
]);
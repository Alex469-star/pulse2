<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/../models/Gear.php';

api_check_csrf();
$me = api_require_user();

$activityId = (int)($_POST['activity_id'] ?? 0);
if ($activityId <= 0) {
    json_err('Не указан ID активности', 400);
}

$activity = Activity::findById($activityId);
if (!$activity || (int)$activity['user_id'] !== (int)$me['id']) {
    json_err('Активность не найдена или недоступна', 403);
}

$title       = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$type        = (string)($_POST['type'] ?? 'run');
$visibility  = (string)($_POST['visibility'] ?? 'public');
$gearId      = (string)($_POST['gear_id'] ?? '');

if ($title === '') {
    json_err('Введите название', 400);
}
if (mb_strlen($title) > 190) {
    json_err('Максимум 190 символов в названии', 400);
}
if (mb_strlen($description) > 2000) {
    json_err('Максимум 2000 символов в описании', 400);
}

$allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
if (!in_array($type, $allowedTypes, true)) $type = 'run';

$allowedVis = ['public','followers','private'];
if (!in_array($visibility, $allowedVis, true)) $visibility = 'public';

$gearIdToSave = null;
if ($gearId !== '') {
    $g = Gear::findById((int)$gearId);
    if ($g && (int)$g['user_id'] === (int)$me['id']) {
        $gearIdToSave = (int)$g['id'];
    }
}

try {
    db()->prepare(
        'UPDATE activities
         SET title = :title,
             description = :description,
             type = :type,
             visibility = :visibility,
             gear_id = :gear_id
         WHERE id = :id AND user_id = :uid'
    )->execute([
        ':title'       => $title,
        ':description' => $description !== '' ? $description : null,
        ':type'        => $type,
        ':visibility'  => $visibility,
        ':gear_id'     => $gearIdToSave,
        ':id'          => $activityId,
        ':uid'         => (int)$me['id'],
    ]);
} catch (Throwable $ex) {
    json_err('Не удалось сохранить: ' . $ex->getMessage(), 500);
}

json_ok([
    'activity_id' => $activityId,
    'title'       => $title,
]);
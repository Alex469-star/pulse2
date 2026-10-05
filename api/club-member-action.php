<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../models/Notification.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');

function json_ok($data = null): void {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err(string $m, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}

$me = current_user();
if (!$me) json_err('Требуется вход', 401);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST only', 405);
csrf_check($_POST['csrf'] ?? null);

$clubId  = (int)($_POST['club_id'] ?? 0);
$userId  = (int)($_POST['user_id'] ?? 0);
$action  = (string)($_POST['action'] ?? '');

if ($clubId <= 0 || $userId <= 0) json_err('club_id и user_id обязательны');

$club = Club::findById($clubId);
if (!$club) json_err('Клуб не найден', 404);

$myRole = Club::roleOf($clubId, (int)$me['id']);
$canManage = in_array($myRole, ['owner','admin','moderator'], true);
$isOwner   = $myRole === 'owner';

if (!$canManage) json_err('Недостаточно прав', 403);

// Нельзя действовать против владельца
$targetRole = Club::roleOf($clubId, $userId);
if ($targetRole === 'owner') json_err('Нельзя изменять владельца клуба', 403);

try {
    switch ($action) {
        case 'approve':
            Club::approve($clubId, $userId, (int)$me['id']);
            // уведомление
            try {
                Notification::push($userId, 'system', (int)$me['id'], 'club', $clubId,
                    'Ваша заявка в клуб «' . $club['name'] . '» одобрена');
            } catch (Throwable $e) {}
            json_ok(['message' => 'Принят в клуб']);

        case 'reject':
            db()->prepare('DELETE FROM club_members WHERE club_id = ? AND user_id = ?')
                ->execute([$clubId, $userId]);
            try {
                Notification::push($userId, 'system', (int)$me['id'], 'club', $clubId,
                    'Заявка в клуб «' . $club['name'] . '» отклонена');
            } catch (Throwable $e) {}
            json_ok(['message' => 'Заявка отклонена']);

        case 'kick':
            if ($userId === (int)$me['id']) json_err('Нельзя исключить себя');
            if ($targetRole === 'admin' && !$isOwner) json_err('Только владелец может исключать админов');
            Club::kick($clubId, $userId);
            try {
                Notification::push($userId, 'system', (int)$me['id'], 'club', $clubId,
                    'Вы исключены из клуба «' . $club['name'] . '»');
            } catch (Throwable $e) {}
            json_ok(['message' => 'Исключён']);

        case 'ban':
            if (!$isOwner) json_err('Только владелец может банить');
            db()->prepare('UPDATE club_members SET status = "banned" WHERE club_id = ? AND user_id = ?')
                ->execute([$clubId, $userId]);
            Club::recalcStats($clubId);
            json_ok(['message' => 'Забанен']);

        case 'unban':
            if (!$isOwner) json_err('Только владелец может разбанить');
            db()->prepare('UPDATE club_members SET status = "active" WHERE club_id = ? AND user_id = ?')
                ->execute([$clubId, $userId]);
            Club::recalcStats($clubId);
            json_ok(['message' => 'Разбанен']);

        case 'set_role':
    $newRole = (string)($_POST['new_role'] ?? '');
    if (!in_array($newRole, ['member','moderator','admin'], true)) {
        json_err('Неверная роль');
    }
    if ($newRole === 'admin' && !$isOwner) {
        json_err('Только владелец может назначать админов');
    }
    Club::setRole($clubId, $userId, $newRole);
    Club::notifyRoleChange($clubId, $userId, $newRole, (int)$me['id']);
    json_ok(['message' => 'Роль обновлена']);

        default:
            json_err('Неизвестное действие');
    }
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
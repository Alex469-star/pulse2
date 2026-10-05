<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../models/ClubEvent.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');

function json_ok($d = null): void { echo json_encode(['ok'=>true,'data'=>$d], JSON_UNESCAPED_UNICODE); exit; }
function json_err(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

$me = current_user();
if (!$me) json_err('Требуется вход', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST only', 405);
csrf_check($_POST['csrf'] ?? null);

$eventId = (int)($_POST['event_id'] ?? 0);
$event = ClubEvent::findById($eventId);
if (!$event) json_err('Событие не найдено', 404);

if (!ClubEvent::canManage($eventId, (int)$me['id'])) {
    json_err('Недостаточно прав', 403);
}

ClubEvent::delete($eventId);
json_ok(['deleted' => true, 'club_id' => (int)$event['club_id']]);
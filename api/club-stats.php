<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/Club.php';

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

$clubId = (int)($_POST['club_id'] ?? 0);
if ($clubId <= 0) json_err('club_id обязателен');

$club = Club::findById($clubId);
if (!$club) json_err('Клуб не найден', 404);

if (!Club::canManage($clubId, (int)$me['id'])) {
    json_err('Только админы клуба могут пересчитывать статистику', 403);
}

try {
    Club::recalcStats($clubId);
    $club = Club::findById($clubId);
    json_ok([
        'total_distance_m'   => (int)($club['total_distance_m'] ?? 0),
        'total_duration_sec' => 0,
        'total_activities'   => (int)($club['total_activities'] ?? 0),
        'week_distance_m'    => (int)($club['week_distance_m'] ?? 0),
        'month_distance_m'   => (int)($club['month_distance_m'] ?? 0),
    ]);
} catch (Throwable $e) {
    json_err('Ошибка: ' . $e->getMessage(), 500);
}
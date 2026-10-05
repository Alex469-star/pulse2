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

$clubId = (int)($_GET['club_id'] ?? 0);
if ($clubId <= 0) err('club_id обязателен');

$club = Club::findById($clubId);
if (!$club) err('Клуб не найден', 404);

$role = Club::roleOf($clubId, (int)$me['id']);
if (($club['visibility'] ?? 'public') === 'hidden' && !$role) err('Доступ запрещён', 403);

$canManage = Club::canManage($clubId, (int)$me['id']);
$posts = Club::wallTree($clubId, 30, 0);

$prepare = function (array $p) use ($me, $canManage) {
    $p['is_mine']     = (int)$p['user_id'] === (int)$me['id'];
    $p['can_edit']    = $p['is_mine'];
    $p['can_delete']  = $p['is_mine'] || $canManage;
    return $p;
};

foreach ($posts as &$p) {
    $p = $prepare($p);
    if (!empty($p['replies'])) {
        foreach ($p['replies'] as &$r) $r = $prepare($r);
        unset($r);
    }
}
unset($p);

ok(['posts' => $posts, 'club_id' => $clubId]);
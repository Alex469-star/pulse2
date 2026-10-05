<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/Club.php';

auth_start();
$me = current_user();
if (!$me) { http_response_code(401); exit; }
csrf_check($_POST['csrf'] ?? null);

$clubId = (int)($_POST['club_id'] ?? 0);
if ($clubId <= 0) { http_response_code(400); exit; }

if (!Club::canManage($clubId, (int)$me['id'])) {
    http_response_code(403); exit('Недостаточно прав');
}

$token = Club::createInvite($clubId, (int)$me['id'], null, 7);
flash('Ссылка-приглашение создана (действует 7 дней)', 'success');
redirect(url('club.php?id=' . $clubId . '&invite=' . $token));
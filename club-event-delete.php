<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';
require_once __DIR__ . '/models/ClubEvent.php';

auth_start();
$me = require_login();

// Только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('feed.php'));
}

csrf_check($_POST['csrf'] ?? null);

$eventId = (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) {
    flash('Не указано событие', 'error');
    redirect(url('feed.php'));
}

$event = ClubEvent::findById($eventId);
if (!$event) {
    flash('Событие не найдено', 'error');
    redirect(url('feed.php'));
}

$clubId = (int)$event['club_id'];

// ---- Проверка прав ----
if (!ClubEvent::canManage($eventId, (int)$me['id'])) {
    flash('Недостаточно прав для удаления события', 'error');
    redirect(url('club-event.php?id=' . $eventId));
}

// ---- Удаление ----
try {
    ClubEvent::delete($eventId);
    flash('Событие удалено', 'success');
} catch (Throwable $e) {
    flash('Не удалось удалить: ' . $e->getMessage(), 'error');
    redirect(url('club-event.php?id=' . $eventId));
}

// Редирект на страницу клуба, в таб «События»
redirect(url('club.php?id=' . $clubId . '&tab=events'));
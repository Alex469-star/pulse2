<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';
require_once __DIR__ . '/models/ClubEvent.php';

auth_start();
$me = current_user();

$clubId = (int)($_GET['club_id'] ?? 0);
$club = Club::findById($clubId);
if (!$club) { http_response_code(404); exit('Клуб не найден'); }

$role = $me ? Club::roleOf($clubId, (int)$me['id']) : null;
$canManage = $me && Club::canManage($clubId, (int)$me['id']);

// Скрытые клубы — только участникам
if ($club['visibility'] === 'hidden' && !$role) {
    http_response_code(403); exit('Доступ запрещён');
}

$scope = (string)($_GET['scope'] ?? 'upcoming');
if (!in_array($scope, ['upcoming','past','all'], true)) $scope = 'upcoming';

$events = ClubEvent::listForClub($clubId, $scope, 100);

$pageTitle = $club['name'] . ' — события';
$extraCss = [url('assets/css/clubs.css')];
require __DIR__ . '/includes/header.php';
?>

<section class="club-events">
    <header class="club-events__head">
        <div>
            <h1 class="clubs-page__title"><?= e($club['name']) ?></h1>
            <p class="muted">
                <a href="<?= e(url('club.php?id=' . $clubId)) ?>">← к клубу</a>
                · События
            </p>
        </div>

        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="<?= e(url('club-event-create.php?club_id=' . $clubId)) ?>">
                + Создать событие
            </a>
        <?php endif; ?>
    </header>

    <div class="feed-tabs club-events__tabs">
        <?php foreach (['upcoming'=>'Предстоящие','past'=>'Прошедшие','all'=>'Все'] as $k=>$v): ?>
            <a class="feed-tab <?= $scope === $k ? 'is-active' : '' ?>"
               href="?club_id=<?= $clubId ?>&scope=<?= $k ?>"><?= $v ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$events): ?>
        <div class="empty">
            <p>Событий пока нет.</p>
            <?php if ($canManage): ?>
                <a class="btn btn--primary" href="<?= e(url('club-event-create.php?club_id=' . $clubId)) ?>">
                    Создать первое событие
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="club-events__grid">
            <?php foreach ($events as $e): ?>
                <?php
                    $starts = strtotime((string)$e['starts_at']);
                    $isPast = $starts < time();
                    $goingCount = (int)($e['going_count'] ?? 0);
                    $maxA = $e['max_attendees'] ? (int)$e['max_attendees'] : null;
                ?>
                <a class="club-event-card <?= $isPast ? 'is-past' : '' ?>"
                   href="<?= e(url('club-event.php?id=' . (int)$e['id'])) ?>">
                    <div class="club-event-card__cover"
                         style="background-image:url('<?= e((string)($e['cover_url'] ?? '')) ?>')">
                        <span class="club-event-card__type">
                            <?= e(club_event_type_icon((string)$e['type'])) ?>
                            <?= e(club_event_type_label((string)$e['type'])) ?>
                        </span>
                        <?php if ($e['status'] === 'cancelled'): ?>
                            <span class="club-event-card__cancelled">Отменено</span>
                        <?php endif; ?>
                    </div>
                    <div class="club-event-card__body">
                        <div class="club-event-card__date">
                            <div class="club-event-card__date-day"><?= e(date('d', $starts)) ?></div>
                            <div class="club-event-card__date-mon"><?= e(club_event_month_ru((int)date('n', $starts))) ?></div>
                        </div>
                        <div class="club-event-card__info">
                            <div class="club-event-card__title"><?= e($e['title']) ?></div>
                            <div class="club-event-card__meta muted">
                                ⏰ <?= e(date('H:i', $starts)) ?>
                                <?php if ($e['city']): ?> · 📍 <?= e($e['city']) ?><?php endif; ?>
                            </div>
                            <div class="club-event-card__stats">
                                <span>👥 <strong><?= $goingCount ?></strong><?= $maxA ? ' / ' . $maxA : '' ?></span>
                                <?php if ((int)$e['photos_count'] > 0): ?>
                                    <span>🖼 <?= (int)$e['photos_count'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php
function club_event_type_icon(string $t): string {
    return match ($t) {
        'training' => '🏃', 'race' => '🏆', 'meeting' => '☕', default => '📅',
    };
}
function club_event_type_label(string $t): string {
    return match ($t) {
        'training' => 'Тренировка', 'race' => 'Соревнование',
        'meeting' => 'Встреча', default => 'Другое',
    };
}
function club_event_month_ru(int $m): string {
    return ['','янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'][$m] ?? '';
}

require __DIR__ . '/includes/footer.php';
?>
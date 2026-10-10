<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';

$hasClubEvent = false;
if (is_file(__DIR__ . '/models/ClubEvent.php')) {
    require_once __DIR__ . '/models/ClubEvent.php';
    $hasClubEvent = class_exists('ClubEvent');
}

auth_start();
$me = current_user();

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

// ============================================================
// ЗАГРУЗКА КЛУБА
// ============================================================
$clubId = (int)($_GET['id'] ?? 0);
$slug   = trim((string)($_GET['slug'] ?? ''));

$club = $clubId > 0
    ? Club::findById($clubId)
    : ($slug !== '' ? Club::findBySlug($slug) : null);

if (!$club) {
    http_response_code(404);
    exit('Клуб не найден');
}

$clubId    = (int)$club['id'];
$role      = $me ? Club::roleOf($clubId, (int)$me['id']) : null;
$isMember  = $role !== null;
$canManage = $me && Club::canManage($clubId, (int)$me['id']);
$isOwner   = $role === 'owner';

if (($club['visibility'] ?? 'public') === 'hidden' && !$isMember) {
    http_response_code(403);
    exit('Доступ запрещён');
}

// ============================================================
// СТАТИСТИКА КЛУБА (живая, если кэш пустой)
// ============================================================
$clubStats = [
    'total_activities' => (int)($club['total_activities'] ?? 0),
    'total_distance_m' => (float)($club['total_distance_m'] ?? 0),
    'week_distance_m'  => (float)($club['week_distance_m'] ?? 0),
];

if ($clubStats['total_activities'] === 0 && $clubStats['total_distance_m'] == 0.0) {
    $live = Club::liveStats($clubId);
    if ($live) {
        $clubStats['total_activities'] = (int)($live['total_activities'] ?? 0);
        $clubStats['total_distance_m'] = (float)($live['total_distance_m'] ?? 0);
        $clubStats['week_distance_m']  = (float)($live['week_distance_m'] ?? 0);
    }
}

// ============================================================
// POST (без AJAX)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me && !$isAjax) {
    csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'join' && !$isMember) {
        try {
            $status = Club::join($clubId, (int)$me['id']);
            flash(match ($status) {
                'active'  => 'Вы вступили в клуб',
                'pending' => 'Заявка отправлена',
                'invited' => 'Клуб принимает только по приглашению',
                default   => 'Готово',
            }, $status === 'active' ? 'success' : 'info');
            if ($status === 'active') Club::notifyNewMember($clubId, (int)$me['id']);
        } catch (Throwable $e) {
            flash('Ошибка: ' . $e->getMessage(), 'error');
        }
        redirect(url('club.php?id=' . $clubId));
    }

    if ($action === 'leave' && $isMember && !$isOwner) {
        Club::leave($clubId, (int)$me['id']);
        flash('Вы вышли из клуба', 'success');
        redirect(url('club.php?id=' . $clubId));
    }

    if ($action === 'create_invite' && $canManage) {
        try {
            $token = Club::createInvite($clubId, (int)$me['id'], null, 7);
            $_SESSION['club_invite_' . $clubId] = $token;
            flash('Ссылка-приглашение создана', 'success');
        } catch (Throwable $e) {
            flash('Ошибка: ' . $e->getMessage(), 'error');
        }
        redirect(url('club.php?id=' . $clubId));
    }

    if ($action === 'event_rsvp' && $isMember && $hasClubEvent && !empty($_POST['event_id'])) {
        try {
            ClubEvent::setAttendance((int)$_POST['event_id'], (int)$me['id'], (string)($_POST['status'] ?? 'going'));
            flash('Ответ сохранён', 'success');
        } catch (Throwable $e) {
            flash('Ошибка: ' . $e->getMessage(), 'error');
        }
        redirect(url('club.php?id=' . $clubId . '&tab=events'));
    }
}

// ============================================================
// ДАННЫЕ
// ============================================================
$tab = (string)($_GET['tab'] ?? 'wall');
$allowedTabs = ['wall', 'activities', 'events', 'members'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'wall';

// Подскоуп для таба событий
$eventScope = (string)($_GET['event_scope'] ?? 'upcoming');
if (!in_array($eventScope, ['upcoming', 'past'], true)) {
    $eventScope = 'upcoming';
}

// Пагинация активностей
$actPage    = max(1, (int)($_GET['act_page'] ?? 1));
$actPerPage = 15;

$wall = [];
if ($tab === 'wall') {
    try { $wall = Club::wallTree($clubId, 30, 0); }
    catch (Throwable $e) { $wall = []; }
}

$activities      = [];
$activitiesTotal = 0;
$activitiesPages = 1;
if ($tab === 'activities') {
    try {
        $activitiesTotal = Club::countActivities($clubId);
        $activitiesPages = max(1, (int)ceil($activitiesTotal / $actPerPage));

        // Если страница за пределами — прижимаем к последней
        if ($actPage > $activitiesPages) {
            $actPage = $activitiesPages;
        }

        $offset = ($actPage - 1) * $actPerPage;
        $activities = Club::activitiesFeed($clubId, $actPerPage, $offset);
    } catch (Throwable $e) {
        $activities = [];
    }
}

$upcomingEvents = [];
$pastEvents     = [];
$nextEvent      = null;
if ($hasClubEvent) {
    try {
        // upcoming нужен всегда — для блока "Ближайшее событие" вне таба
        // и для счётчика в табе
        $upcomingEvents = ClubEvent::listForClub($clubId, 'upcoming', 12);
        $nextEvent      = $upcomingEvents[0] ?? null;

        // past тянем только если открыт таб events и выбран past
        if ($tab === 'events' && $eventScope === 'past') {
            $pastEvents = ClubEvent::listForClub($clubId, 'past', 12);
        }
    } catch (Throwable $e) {}
}

$members = [];
$pendingRequests = [];
try {
    $members = Club::members($clubId, 'active', 100);
    if ($canManage) $pendingRequests = Club::pendingRequests($clubId);
} catch (Throwable $e) {}

$topWeek = [];
try { $topWeek = Club::leaderboard($clubId, 'week', 5); }
catch (Throwable $e) {}

$myClubs = [];
if ($me) {
    try {
        $stmt = db()->prepare(
            'SELECT c.id, c.name, c.slug, c.avatar_url, m.role
               FROM club_members m
               JOIN clubs c ON c.id = m.club_id
              WHERE m.user_id = ? AND m.status = "active" AND c.is_banned = 0
           ORDER BY FIELD(m.role, "owner","admin","moderator","member"), c.name ASC
              LIMIT 5'
        );
        $stmt->execute([(int)$me['id']]);
        $myClubs = $stmt->fetchAll();
    } catch (Throwable $e) {}
}

$currentInvite = $_SESSION['club_invite_' . $clubId] ?? null;
$inviteUrl = $currentInvite
    ? app_url('club-join.php?token=' . $currentInvite)
    : null;

// ============================================================
// ХЕЛПЕРЫ
// ============================================================
if (!function_exists('club_event_type_icon')) {
    function club_event_type_icon(string $t): string {
        return match ($t) { 'training'=>'🏃','race'=>'🏆','meeting'=>'☕', default=>'📅' };
    }
}
if (!function_exists('club_event_month_ru')) {
    function club_event_month_ru(int $m): string {
        return ['','янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'][$m] ?? '';
    }
}
if (!function_exists('club_sport_label')) {
    function club_sport_label(string $t): string {
        return match ($t) {
            'run'=>'Бег','ride'=>'Велосипед','swim'=>'Плавание','ski'=>'Лыжи',
            'walk'=>'Ходьба','hike'=>'Хайкинг','mixed'=>'Смешанный', default=>'Другое'
        };
    }
}
if (!function_exists('club_activity_icon')) {
    function club_activity_icon(string $t): string {
        return match ($t) {
            'run'=>'🏃','ride'=>'🚴','swim'=>'🏊','ski'=>'⛷️',
            'walk'=>'🚶','hike'=>'🥾', default=>'📦'
        };
    }
}
if (!function_exists('club_activity_label')) {
    function club_activity_label(string $t): string {
        return match ($t) {
            'run'=>'Бег','ride'=>'Велосипед','swim'=>'Плавание','ski'=>'Лыжи',
            'walk'=>'Ходьба','hike'=>'Хайкинг', default=>'Другое'
        };
    }
}

/**
 * Рендер поста или ответа.
 */
if (!function_exists('club_render_wall_post')) {
    function club_render_wall_post(array $p, int $clubId, ?array $me, bool $canManage, int $depth = 0): void
    {
        $isMine    = $me && (int)$p['user_id'] === (int)$me['id'];
        $canEdit   = $isMine;
        $canDelete = $isMine || $canManage;
        $isPinned  = (int)($p['is_pinned'] ?? 0) === 1;
        $isReply   = $depth > 0;

        $containerClass = $isReply ? 'club-wall-reply' : 'club-wall-post';
        ?>
                <div class="<?= $containerClass ?> <?= $isPinned ? 'is-pinned' : '' ?>"
             id="post-<?= (int)$p['id'] ?>"
             data-post-id="<?= (int)$p['id'] ?>"
             data-root-id="<?= $isReply ? (int)($p['parent_id'] ?? 0) : (int)$p['id'] ?>"
             data-author-id="<?= (int)$p['user_id'] ?>"
             data-author-name="<?= e((string)$p['display_name']) ?>"
             data-can-edit="<?= $canEdit ? '1' : '0' ?>"
             data-can-delete="<?= $canDelete ? '1' : '0' ?>">
            <span class="avatar avatar--sm">
                <?php if (!empty($p['avatar_url'])): ?>
                    <img src="<?= e($p['avatar_url']) ?>" alt="">
                <?php else: ?>
                    <?= e(mb_substr((string)$p['display_name'], 0, 1)) ?>
                <?php endif; ?>
            </span>
            <div class="<?= $isReply ? 'club-wall-reply__body' : 'club-wall-post__body' ?>">
                <div class="<?= $isReply ? 'club-wall-reply__head' : 'club-wall-post__head' ?>">
                    <a href="<?= e(url('profile.php?u=' . urlencode((string)$p['username']))) ?>">
                        <strong><?= e($p['display_name']) ?></strong>
                    </a>

                    <?php if ($isReply && !empty($p['reply_to_user_id']) && !empty($p['reply_to_display_name'])): ?>
                        <span class="club-wall-reply__arrow">→</span>
                        <a class="club-wall-reply__to"
                           href="<?= e(url('profile.php?u=' . urlencode((string)($p['reply_to_username'] ?? '')))) ?>">
                            <strong><?= e($p['reply_to_display_name']) ?></strong>
                        </a>
                    <?php endif; ?>

                    <span class="muted"> · <?= e(time_ago((string)$p['created_at'])) ?></span>

                    <?php if (!empty($p['edited_at'])): ?>
                        <span class="club-wall-post__edited">(изменено)</span>
                    <?php endif; ?>
                    <?php if ($isPinned): ?><span class="club-wall-post__pin">📌</span><?php endif; ?>

                    <?php if ($canEdit || $canDelete || !$isReply): ?>
                        <button type="button" class="club-wall-post__menu-btn"
                                data-menu-toggle aria-label="Действия">⋯</button>
                    <?php endif; ?>
                </div>

                <div class="js-post-body"><?= nl2br(e((string)$p['body'])) ?></div>

                <?php if ($isReply): ?>
                    <button type="button" class="club-wall-post__reply-btn js-reply-btn"
                            data-post-id="<?= (int)$p['parent_id'] ?>"
                            data-reply-to="<?= (int)$p['user_id'] ?>"
                            data-reply-to-name="<?= e((string)$p['display_name']) ?>">
                        💬 Ответить
                    </button>
                <?php else: ?>
                    <button type="button" class="club-wall-post__reply-btn js-reply-btn"
                            data-post-id="<?= (int)$p['id'] ?>"
                            data-reply-to="<?= (int)$p['user_id'] ?>"
                            data-reply-to-name="<?= e((string)$p['display_name']) ?>">
                        💬 Ответить
                    </button>

                    <div class="club-wall-replies js-replies" data-root-id="<?= (int)$p['id'] ?>">
                        <?php if (!empty($p['replies'])): ?>
                            <?php foreach ($p['replies'] as $r): ?>
                                <?php club_render_wall_post($r, $clubId, $me, $canManage, 1); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

/**
 * Общий блок активностей — вынесен, чтобы использовать и в AJAX, и без.
 */
if (!function_exists('club_render_activities')) {
    function club_render_activities(array $activities): void
    {
        ?>
        <div class="club-activities-list">
            <?php if (!$activities): ?>
                <div class="club-card-block">
                    <p class="muted">В клубе пока нет публичных активностей.</p>
                </div>
            <?php else: ?>
                <?php foreach ($activities as $a): ?>
                    <?php
                        $aid = (int)$a['id'];
                        $hasTrack = !empty($a['map_points']);
                        $activityDate = $a['started_at'] ?: $a['created_at'];
                        $isRide = $a['type'] === 'ride';
                    ?>
                    <article class="club-activity-card" data-activity-id="<?= $aid ?>">
                        <div class="club-activity-card__head">
                            <a class="club-activity-card__user"
                               href="<?= e(url('profile.php?u=' . urlencode((string)$a['username']))) ?>">
                                <span class="avatar avatar--sm">
                                    <?php if (!empty($a['avatar_url'])): ?>
                                        <img src="<?= e($a['avatar_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(mb_substr((string)$a['display_name'], 0, 1)) ?>
                                    <?php endif; ?>
                                </span>
                                <span class="club-activity-card__user-info">
                                    <span class="club-activity-card__user-name"><?= e($a['display_name']) ?></span>
                                    <span class="club-activity-card__user-meta muted">
                                        @<?= e($a['username']) ?>
                                        · <?= e(date('d.m.Y H:i', strtotime((string)$activityDate))) ?>
                                    </span>
                                </span>
                            </a>
                            <span class="activity-type">
                                <?= e(club_activity_icon((string)$a['type'])) ?>
                                <?= e(club_activity_label((string)$a['type'])) ?>
                            </span>
                        </div>

                        <?php if ($hasTrack): ?>
                            <a class="club-activity-card__map-link"
                               href="<?= e(url('activity.php?id=' . $aid)) ?>">
                                <div class="club-activity-map"
                                     data-activity-id="<?= $aid ?>"
                                     data-points="<?= e(json_encode($a['map_points'], JSON_UNESCAPED_UNICODE)) ?>"
                                     data-initialized="0"></div>
                            </a>
                        <?php endif; ?>

                        <a class="club-activity-card__title-link"
                           href="<?= e(url('activity.php?id=' . $aid)) ?>">
                            <h3 class="club-activity-card__title"><?= e($a['title']) ?></h3>
                        </a>

                        <?php if (!empty($a['description'])): ?>
                            <div class="club-activity-card__desc muted">
                                <?= e(mb_substr((string)$a['description'], 0, 160)) ?><?= mb_strlen((string)$a['description']) > 160 ? '…' : '' ?>
                            </div>
                        <?php endif; ?>

                        <div class="club-activity-card__stats">
                            <div class="club-act-stat">
                                <span class="club-act-stat__value"><?= e(format_distance((float)$a['distance_m'])) ?></span>
                                <span class="club-act-stat__label">Дистанция</span>
                            </div>
                            <div class="club-act-stat">
                                <span class="club-act-stat__value"><?= e(format_duration((int)$a['duration_sec'])) ?></span>
                                <span class="club-act-stat__label">Время</span>
                            </div>
                            <div class="club-act-stat">
                                <span class="club-act-stat__value">
                                    <?php if ($isRide && $a['avg_speed_mps']): ?>
                                        <?= number_format((float)$a['avg_speed_mps'] * 3.6, 1, '.', '') ?> км/ч
                                    <?php else: ?>
                                        <?= e(format_pace((float)$a['distance_m'], (int)$a['duration_sec'])) ?>
                                    <?php endif; ?>
                                </span>
                                <span class="club-act-stat__label"><?= $isRide ? 'Средняя' : 'Темп' ?></span>
                            </div>
                            <div class="club-act-stat">
                                <span class="club-act-stat__value">
                                    <?= $a['elevation_gain_m'] ? (int)$a['elevation_gain_m'] . ' м' : '—' ?>
                                </span>
                                <span class="club-act-stat__label">Набор</span>
                            </div>
                        </div>

                        <?php if (!empty($a['avg_hr']) || !empty($a['avg_power_w']) || !empty($a['avg_cadence'])): ?>
                            <div class="club-activity-card__sensors">
                                <?php if (!empty($a['avg_hr'])): ?>
                                    <span>❤️ <?= (int)$a['avg_hr'] ?> <small>уд/мин</small></span>
                                <?php endif; ?>
                                <?php if (!empty($a['avg_power_w'])): ?>
                                    <span>⚡ <?= (int)$a['avg_power_w'] ?> <small>Вт</small></span>
                                <?php endif; ?>
                                <?php if (!empty($a['avg_cadence'])): ?>
                                    <span>🔄 <?= (int)$a['avg_cadence'] ?> <small>об/мин</small></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="club-activity-card__footer">
                            <a class="action" href="<?= e(url('activity.php?id=' . $aid)) ?>">
                                <span class="action__icon">♥</span>
                                <span><?= (int)$a['likes_count'] ?></span>
                            </a>
                            <a class="action" href="<?= e(url('activity.php?id=' . $aid . '#comments')) ?>">
                                <span class="action__icon">💬</span>
                                <span><?= (int)$a['comments_count'] ?></span>
                            </a>
                            <?php if ((int)$a['photos_count'] > 0): ?>
                                <span class="action action--static">
                                    <span class="action__icon">🖼</span>
                                    <span><?= (int)$a['photos_count'] ?></span>
                                </span>
                            <?php endif; ?>
                            <a class="action club-activity-card__open"
                               href="<?= e(url('activity.php?id=' . $aid)) ?>">
                                <span class="action__icon">🔗</span>
                                <span>Открыть</span>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

/**
 * Пагинация для таба "Активности".
 * Использует data-act-page для AJAX-загрузки.
 */
if (!function_exists('club_render_activities_pagination')) {
    function club_render_activities_pagination(
        int $clubId,
        int $currentPage,
        int $totalPages,
        int $totalItems
    ): void {
        if ($totalPages <= 1) return;

        $buildUrl = function (int $page) use ($clubId): string {
            return '?id=' . $clubId . '&tab=activities&act_page=' . $page;
        };

        // Окно страниц вокруг текущей
        $window = 2;
        $from = max(1, $currentPage - $window);
        $to   = min($totalPages, $currentPage + $window);
        ?>
        <nav class="pagination club-activities__pagination" aria-label="Пагинация активностей">
            <?php if ($currentPage > 1): ?>
                <a class="pagination__item pagination__item--arrow"
                   href="<?= e($buildUrl($currentPage - 1)) ?>"
                   data-act-page="<?= $currentPage - 1 ?>"
                   rel="prev">← Назад</a>
            <?php endif; ?>

            <?php if ($from > 1): ?>
                <a class="pagination__item"
                   href="<?= e($buildUrl(1)) ?>"
                   data-act-page="1">1</a>
                <?php if ($from > 2): ?>
                    <span class="pagination__dots">…</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $from; $p <= $to; $p++): ?>
                <?php if ($p === $currentPage): ?>
                    <span class="pagination__item is-active"><?= $p ?></span>
                <?php else: ?>
                    <a class="pagination__item"
                       href="<?= e($buildUrl($p)) ?>"
                       data-act-page="<?= $p ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($to < $totalPages): ?>
                <?php if ($to < $totalPages - 1): ?>
                    <span class="pagination__dots">…</span>
                <?php endif; ?>
                <a class="pagination__item"
                   href="<?= e($buildUrl($totalPages)) ?>"
                   data-act-page="<?= $totalPages ?>"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($currentPage < $totalPages): ?>
                <a class="pagination__item pagination__item--arrow"
                   href="<?= e($buildUrl($currentPage + 1)) ?>"
                   data-act-page="<?= $currentPage + 1 ?>"
                   rel="next">Вперёд →</a>
            <?php endif; ?>
        </nav>
        <?php
    }
}

/**
 * Общий блок таба "События" — с подтабами и RSVP.
 */
if (!function_exists('club_render_events_tab')) {
    function club_render_events_tab(
        int $clubId,
        string $eventScope,
        array $upcomingEvents,
        array $pastEvents,
        bool $isMember,
        bool $canManage,
        ?array $me
    ): void {
        ?>
        <?php if ($canManage): ?>
            <div class="club-card-block club-card-block--actions">
                <div class="club-card-block__title-row">
                    <h2 class="club-card-block__title">События клуба</h2>
                    <a class="btn btn--primary btn--sm"
                       href="<?= e(url('club-event-create.php?club_id=' . $clubId)) ?>">+ Создать событие</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="feed-tabs club-events__subtabs" id="club-events-subtabs">
            <a class="feed-tab <?= $eventScope === 'upcoming' ? 'is-active' : '' ?>"
               href="?id=<?= $clubId ?>&tab=events&event_scope=upcoming"
               data-event-scope="upcoming">Предстоящие</a>
            <a class="feed-tab <?= $eventScope === 'past' ? 'is-active' : '' ?>"
               href="?id=<?= $clubId ?>&tab=events&event_scope=past"
               data-event-scope="past">Прошедшие</a>
        </div>

        <div class="club-card-block">
            <?php $eventList = $eventScope === 'past' ? $pastEvents : $upcomingEvents; ?>
            <h2 class="club-card-block__title">
                <?= $eventScope === 'past' ? 'Прошедшие' : 'Предстоящие' ?>
                <span class="club-view__count"><?= count($eventList) ?></span>
            </h2>

            <?php if (!$eventList): ?>
                <p class="muted">
                    <?= $eventScope === 'past' ? 'Прошедших событий нет.' : 'Предстоящих событий нет.' ?>
                </p>
            <?php else: ?>
                <?php foreach ($eventList as $e): ?>
                    <?php
                        $starts = strtotime((string)$e['starts_at']);
                        $myStatus = ($me && $eventScope === 'upcoming' && class_exists('ClubEvent'))
                            ? ClubEvent::myStatus((int)$e['id'], (int)$me['id'])
                            : null;
                    ?>
                    <div class="club-events-list__item">
                        <div class="club-events-list__date">
                            <div class="club-events-list__day"><?= e(date('d', $starts)) ?></div>
                            <div class="club-events-list__mon"><?= e(club_event_month_ru((int)date('n', $starts))) ?></div>
                        </div>
                        <div class="club-events-list__body">
                            <a class="club-events-list__title"
                               href="<?= e(url('club-event.php?id=' . (int)$e['id'])) ?>">
                                <?= e(club_event_type_icon((string)$e['type'])) ?>
                                <?= e($e['title']) ?>
                            </a>
                            <div class="club-events-list__meta muted">
                                ⏰ <?= e(date('d.m.Y H:i', $starts)) ?>
                                · 👥 <?= (int)($e['going_count'] ?? 0) ?>
                                <?php if (($e['status'] ?? '') === 'cancelled'): ?>
                                    · <span style="color:#b3261e">Отменено</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="club-events-list__actions">
                            <?php if ($myStatus === 'going'): ?>
                                <span class="admin-badge admin-badge--ok">Иду</span>
                            <?php elseif ($isMember && $eventScope === 'upcoming'): ?>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="event_rsvp">
                                    <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                                    <button name="status" value="going"
                                            class="btn btn--ghost btn--sm">✅ Иду</button>
                                </form>
                            <?php endif; ?>
                            <a class="btn btn--ghost btn--sm"
                               href="<?= e(url('club-event.php?id=' . (int)$e['id'])) ?>">→</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

$pageTitle = $club['name'];

// ============================================================
// AJAX — только main
// ============================================================
if ($isAjax) {
    ob_start();
    ?>
    <div class="club-view__main" id="club-main">
        <?php if ($tab === 'wall'): ?>
            <div class="club-card-block" id="wall">
                <?php if ($isMember): ?>
                    <form class="club-wall-form js-wall-form" data-club-id="<?= $clubId ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <textarea name="body" rows="2" placeholder="Написать участникам..."
                                  maxlength="1000" required></textarea>
                        <button class="btn btn--primary btn--sm" type="submit">Отправить</button>
                    </form>
                <?php endif; ?>
                <div class="club-wall-list" id="club-wall-list">
                    <?php if (!$wall): ?>
                        <p class="muted js-wall-empty">На стене пока пусто.</p>
                    <?php else: ?>
                        <?php foreach ($wall as $p): ?>
                            <?php club_render_wall_post($p, $clubId, $me, $canManage, 0); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($tab === 'activities'): ?>
            <?php club_render_activities($activities); ?>
            <?php club_render_activities_pagination($clubId, $actPage, $activitiesPages, $activitiesTotal); ?>

        <?php elseif ($tab === 'events' && $hasClubEvent): ?>
            <?php club_render_events_tab($clubId, $eventScope, $upcomingEvents, $pastEvents, $isMember, $canManage, $me); ?>

        <?php elseif ($tab === 'members'): ?>
            <?php if ($canManage && $pendingRequests): ?>
                <div class="club-card-block club-card-block--pending">
                    <h2 class="club-card-block__title">
                        Заявки <span class="club-view__count"><?= count($pendingRequests) ?></span>
                    </h2>
                    <?php foreach ($pendingRequests as $p): ?>
                        <div class="club-pending-row">
                            <span class="avatar avatar--sm">
                                <?php if (!empty($p['avatar_url'])): ?>
                                    <img src="<?= e($p['avatar_url']) ?>" alt="">
                                <?php else: ?>
                                    <?= e(mb_substr((string)$p['display_name'], 0, 1)) ?>
                                <?php endif; ?>
                            </span>
                            <div>
                                <strong><?= e($p['display_name']) ?></strong>
                                <div class="muted">@<?= e($p['username']) ?></div>
                            </div>
                            <form method="post" action="<?= e(url('api/club-member-action.php')) ?>"
                                  class="club-pending-row__actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="club_id" value="<?= $clubId ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                                <button name="action" value="approve" class="btn btn--sm btn--primary">✓</button>
                                <button name="action" value="reject" class="btn btn--sm btn--ghost">✕</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="club-card-block">
                <h2 class="club-card-block__title">
                    Участники <span class="club-view__count"><?= count($members) ?></span>
                </h2>
                <div class="club-members-mini">
                    <?php foreach ($members as $m): ?>
                        <a class="club-member-row"
                           href="<?= e(url('profile.php?u=' . urlencode((string)$m['username']))) ?>">
                            <span class="avatar avatar--sm">
                                <?php if (!empty($m['avatar_url'])): ?>
                                    <img src="<?= e($m['avatar_url']) ?>" alt="">
                                <?php else: ?>
                                    <?= e(mb_substr((string)$m['display_name'], 0, 1)) ?>
                                <?php endif; ?>
                            </span>
                            <span><?= e($m['display_name']) ?></span>
                            <?php if ($m['role'] === 'owner'): ?>
                                <span class="club-member-row__role club-member-row__role--owner">👑</span>
                            <?php elseif ($m['role'] === 'admin'): ?>
                                <span class="club-member-row__role">⭐</span>
                            <?php elseif ($m['role'] === 'moderator'): ?>
                                <span class="club-member-row__role">🛡</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
    header('Content-Type: text/html; charset=utf-8');
    echo ob_get_clean();
    exit;
}

// ============================================================
// ОБЫЧНЫЙ РЕНДЕР
// ============================================================
$extraCss = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    url('assets/css/clubs.css'),
];
$extraJs = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    url('assets/js/club.js'),
];
require __DIR__ . '/includes/header.php';
?>

<section class="club-view" data-club-id="<?= $clubId ?>">
    <header class="club-view__hero">
        <div class="club-view__cover"
             style="background-image:url('<?= e((string)($club['cover_url'] ?? '')) ?>')"></div>

        <div class="club-view__head">
            <div class="club-view__avatar">
                <?php if (!empty($club['avatar_url'])): ?>
                    <img src="<?= e($club['avatar_url']) ?>" alt="">
                <?php else: ?>
                    <?= e(mb_substr((string)$club['name'], 0, 1)) ?>
                <?php endif; ?>
            </div>

            <div class="club-view__title-block">
                <h1 class="club-view__name">
                    <?= e($club['name']) ?>
                    <?php if ((int)($club['is_verified'] ?? 0)): ?>
                        <span class="club-view__verified" title="Проверенный клуб">✓</span>
                    <?php endif; ?>
                </h1>
                <div class="club-view__meta muted">
                    <?= e(club_sport_label((string)($club['sport_type'] ?? 'mixed'))) ?>
                    <?php if (!empty($club['city'])): ?> · 📍 <?= e($club['city']) ?><?php endif; ?>
                    · <?= (int)$club['member_count'] ?> участников
                </div>
            </div>

            <div class="club-view__actions">
                <?php if ($canManage): ?>
                    <a class="btn btn--ghost btn--sm"
                       href="<?= e(url('club-edit.php?id=' . $clubId)) ?>">✏️ Настройки</a>
                    <?php if ($hasClubEvent): ?>
                        <a class="btn btn--primary btn--sm"
                           href="<?= e(url('club-event-create.php?club_id=' . $clubId)) ?>">+ Событие</a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$me): ?>
                    <a class="btn btn--primary" href="<?= e(url('login.php')) ?>">Войти</a>
                <?php elseif ($isMember && !$isOwner): ?>
                    <form method="post" onsubmit="return confirm('Выйти из клуба?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="leave">
                        <button class="btn btn--ghost">Выйти</button>
                    </form>
                <?php elseif (!$isMember): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="join">
                        <button class="btn btn--primary">
                            <?= ($club['join_policy'] ?? 'open') === 'request' ? 'Подать заявку' : 'Вступить' ?>
                        </button>
                    </form>
                <?php else: ?>
                    <span class="badge badge--followers">Вы основатель</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($club['description'])): ?>
            <div class="club-view__description"><?= nl2br(e((string)$club['description'])) ?></div>
        <?php endif; ?>
    </header>

    <div class="club-view__stats">
        <div class="stat">
            <span class="stat__value"><?= number_format($clubStats['total_distance_m']/1000, 0, '.', ' ') ?> км</span>
            <span class="stat__label">Всего пройдено</span>
        </div>
        <div class="stat">
            <span class="stat__value"><?= number_format($clubStats['week_distance_m']/1000, 0, '.', ' ') ?> км</span>
            <span class="stat__label">За неделю</span>
        </div>
        <div class="stat">
            <span class="stat__value"><?= (int)$club['member_count'] ?></span>
            <span class="stat__label">Участников</span>
        </div>
        <div class="stat">
            <span class="stat__value"><?= (int)$clubStats['total_activities'] ?></span>
            <span class="stat__label">Тренировок</span>
        </div>
        <?php if ($upcomingEvents): ?>
            <div class="stat stat--accent">
                <span class="stat__value"><?= count($upcomingEvents) ?></span>
                <span class="stat__label">Событий впереди</span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($nextEvent && $hasClubEvent): ?>
        <?php
            $starts = strtotime((string)$nextEvent['starts_at']);
            $myStatus = $me ? ClubEvent::myStatus((int)$nextEvent['id'], (int)$me['id']) : null;
        ?>
        <div class="club-next-event">
            <div class="club-next-event__date">
                <div class="club-next-event__day"><?= e(date('d', $starts)) ?></div>
                <div class="club-next-event__mon"><?= e(club_event_month_ru((int)date('n', $starts))) ?></div>
            </div>
            <div class="club-next-event__body">
                <div class="club-next-event__label muted">Ближайшее событие</div>
                <a class="club-next-event__title"
                   href="<?= e(url('club-event.php?id=' . (int)$nextEvent['id'])) ?>">
                    <?= e(club_event_type_icon((string)$nextEvent['type'])) ?>
                    <?= e($nextEvent['title']) ?>
                </a>
                <div class="club-next-event__meta muted">
                    ⏰ <?= e(date('H:i', $starts)) ?>
                    · 👥 <?= (int)($nextEvent['going_count'] ?? 0) ?>
                </div>
            </div>
            <?php if ($isMember && $starts > time()): ?>
                <form method="post" class="club-next-event__rsvp">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="event_rsvp">
                    <input type="hidden" name="event_id" value="<?= (int)$nextEvent['id'] ?>">
                    <button name="status" value="going"
                            class="btn <?= $myStatus === 'going' ? 'btn--primary' : 'btn--ghost' ?> btn--sm">✅ Иду</button>
                    <button name="status" value="maybe"
                            class="btn <?= $myStatus === 'maybe' ? 'btn--primary' : 'btn--ghost' ?> btn--sm">❔</button>
                    <button name="status" value="declined" class="btn btn--ghost btn--sm">✕</button>
                </form>
            <?php else: ?>
                <a class="btn btn--ghost btn--sm"
                   href="<?= e(url('club-event.php?id=' . (int)$nextEvent['id'])) ?>">Подробнее →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <nav class="feed-tabs club-view__tabs" id="club-tabs">
        <?php
            $tabs = ['wall' => '📝 Стена', 'activities' => '🏃 Активности'];
            if ($hasClubEvent) {
                $tabs['events'] = '📅 События' . (count($upcomingEvents) > 0 ? ' (' . count($upcomingEvents) . ')' : '');
            }
            $tabs['members'] = '👥 Участники';
        ?>
        <?php foreach ($tabs as $k => $label): ?>
            <a class="feed-tab <?= $tab === $k ? 'is-active' : '' ?>"
               href="?id=<?= $clubId ?>&tab=<?= $k ?>"
               data-tab="<?= $k ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="club-view__layout">
        <div class="club-view__main" id="club-main">
            <?php if ($tab === 'wall'): ?>
                <div class="club-card-block" id="wall">
                    <?php if ($isMember): ?>
                        <form class="club-wall-form js-wall-form" data-club-id="<?= $clubId ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <textarea name="body" rows="2" placeholder="Написать участникам..."
                                      maxlength="1000" required></textarea>
                            <button class="btn btn--primary btn--sm" type="submit">Отправить</button>
                        </form>
                    <?php endif; ?>

                    <div class="club-wall-list" id="club-wall-list">
                        <?php if (!$wall): ?>
                            <p class="muted js-wall-empty">На стене пока пусто.</p>
                        <?php else: ?>
                            <?php foreach ($wall as $p): ?>
                                <?php club_render_wall_post($p, $clubId, $me, $canManage, 0); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($tab === 'activities'): ?>
                <?php club_render_activities($activities); ?>
                <?php club_render_activities_pagination($clubId, $actPage, $activitiesPages, $activitiesTotal); ?>

            <?php elseif ($tab === 'events' && $hasClubEvent): ?>
                <?php club_render_events_tab($clubId, $eventScope, $upcomingEvents, $pastEvents, $isMember, $canManage, $me); ?>

            <?php elseif ($tab === 'members'): ?>
                <?php if ($canManage && $pendingRequests): ?>
                    <div class="club-card-block club-card-block--pending">
                        <h2 class="club-card-block__title">
                            Заявки <span class="club-view__count"><?= count($pendingRequests) ?></span>
                        </h2>
                        <?php foreach ($pendingRequests as $p): ?>
                            <div class="club-pending-row">
                                <span class="avatar avatar--sm">
                                    <?php if (!empty($p['avatar_url'])): ?>
                                        <img src="<?= e($p['avatar_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(mb_substr((string)$p['display_name'], 0, 1)) ?>
                                    <?php endif; ?>
                                </span>
                                <div>
                                    <strong><?= e($p['display_name']) ?></strong>
                                    <div class="muted">@<?= e($p['username']) ?></div>
                                </div>
                                <form method="post" action="<?= e(url('api/club-member-action.php')) ?>"
                                      class="club-pending-row__actions">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="club_id" value="<?= $clubId ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                                    <button name="action" value="approve" class="btn btn--sm btn--primary">✓</button>
                                    <button name="action" value="reject" class="btn btn--sm btn--ghost">✕</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="club-card-block">
                    <h2 class="club-card-block__title">
                        Участники <span class="club-view__count"><?= count($members) ?></span>
                    </h2>
                    <div class="club-members-mini">
                        <?php foreach ($members as $m): ?>
                            <a class="club-member-row"
                               href="<?= e(url('profile.php?u=' . urlencode((string)$m['username']))) ?>">
                                <span class="avatar avatar--sm">
                                    <?php if (!empty($m['avatar_url'])): ?>
                                        <img src="<?= e($m['avatar_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(mb_substr((string)$m['display_name'], 0, 1)) ?>
                                    <?php endif; ?>
                                </span>
                                <span><?= e($m['display_name']) ?></span>
                                <?php if ($m['role'] === 'owner'): ?>
                                    <span class="club-member-row__role club-member-row__role--owner">👑</span>
                                <?php elseif ($m['role'] === 'admin'): ?>
                                    <span class="club-member-row__role">⭐</span>
                                <?php elseif ($m['role'] === 'moderator'): ?>
                                    <span class="club-member-row__role">🛡</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <aside class="club-view__side">
            <?php if ($topWeek): ?>
                <div class="club-card-block">
                    <h3 class="club-card-block__title">🏆 Топ недели</h3>
                    <?php foreach ($topWeek as $i => $u): ?>
                        <a class="club-leader-row"
                           href="<?= e(url('profile.php?u=' . urlencode((string)$u['username']))) ?>">
                            <span class="club-leader-row__rank"><?= $i + 1 ?></span>
                            <span><?= e($u['display_name']) ?></span>
                            <span class="club-leader-row__val">
                                <?= number_format((float)$u['distance_m']/1000, 1, '.', ' ') ?> км
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($myClubs): ?>
                <div class="club-card-block">
                    <h3 class="club-card-block__title">Мои клубы</h3>
                    <?php foreach ($myClubs as $mc): ?>
                        <?php $mcSlug = trim((string)($mc['slug'] ?? '')); ?>
                        <a class="sidebar-club <?= (int)$mc['id'] === $clubId ? 'is-current' : '' ?>"
                           href="<?= $mcSlug !== ''
                                ? e(url('club.php?slug=' . urlencode($mcSlug)))
                                : e(url('club.php?id=' . (int)$mc['id'])) ?>">
                            <span class="sidebar-club__avatar">
                                <?php if (!empty($mc['avatar_url'])): ?>
                                    <img src="<?= e($mc['avatar_url']) ?>" alt="">
                                <?php else: ?>
                                    <?= e(mb_substr((string)$mc['name'], 0, 1)) ?>
                                <?php endif; ?>
                            </span>
                            <span class="sidebar-club__info">
                                <span class="sidebar-club__name"><?= e($mc['name']) ?></span>
                                <span class="sidebar-club__meta muted">
                                    <?php if ($mc['role'] === 'owner'): ?>👑 владелец
                                    <?php elseif ($mc['role'] === 'admin'): ?>⭐ админ
                                    <?php else: ?>участник<?php endif; ?>
                                </span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                    <a class="sidebar-clubs__more" href="<?= e(url('clubs.php')) ?>">Все клубы →</a>
                </div>
            <?php endif; ?>

            <div class="club-card-block">
                <h3 class="club-card-block__title">Информация</h3>
                <div class="club-info-list">
                    <div class="club-info-list__row">
                        <span class="muted">Основан</span>
                        <strong><?= e(date('d.m.Y', strtotime((string)$club['created_at']))) ?></strong>
                    </div>
                    <div class="club-info-list__row">
                        <span class="muted">Основатель</span>
                        <a href="<?= e(url('profile.php?u=' . urlencode((string)$club['owner_username']))) ?>">
                            <?= e($club['owner_display_name']) ?>
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($canManage): ?>
                <div class="club-card-block club-card-block--invite">
                    <h3 class="club-card-block__title">🔗 Приглашение</h3>
                    <?php if ($inviteUrl): ?>
                        <div class="js-invite-wrap">
                            <input type="text" class="js-invite-input" readonly
                                   value="<?= e($inviteUrl) ?>"
                                   style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--border-strong);font-size:12px;font-family:inherit;margin-bottom:8px">
                            <button type="button" class="btn btn--primary btn--sm js-copy-invite" style="width:100%">
                                📋 Скопировать ссылку
                            </button>
                        </div>
                        <form method="post" style="margin-top:8px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_invite">
                            <button class="btn btn--ghost btn--sm" style="width:100%">🔄 Создать новую</button>
                        </form>
                    <?php else: ?>
                        <p class="muted" style="font-size:13px;margin:0 0 10px">
                            Сгенерируйте ссылку и отправьте друзьям.
                        </p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_invite">
                            <button class="btn btn--primary btn--sm" style="width:100%">
                                Создать ссылку-приглашение
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</section>

<script>
window.__CLUB__ = {
    id: <?= $clubId ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    isMember: <?= $isMember ? 'true' : 'false' ?>,
    canManage: <?= $canManage ? 'true' : 'false' ?>,
    meId: <?= (int)($me['id'] ?? 0) ?>,
    urls: {
        wall: <?= json_encode(url('api/club-wall.php')) ?>,
        post: <?= json_encode(url('api/club-post.php')) ?>,
        edit: <?= json_encode(url('api/club-post-edit.php')) ?>,
        del:  <?= json_encode(url('api/club-post-delete.php')) ?>
    }
};
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
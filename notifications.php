<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Notification.php';

auth_start();
$me = require_login();

// ---- POST: отметить прочитанными ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        Notification::markAllRead((int)$me['id']);
        flash('Все уведомления отмечены прочитанными', 'success');
    } elseif ($action === 'mark_read' && !empty($_POST['id'])) {
        Notification::markRead((int)$_POST['id'], (int)$me['id']);
    }
    redirect(url('notifications.php'));
}

// ---- Загрузка ----
$notifications = [];
$error = null;
$unreadCount = 0;

try {
    $notifications = Notification::allForUser((int)$me['id'], 100);
    $unreadCount   = Notification::unreadCount((int)$me['id']);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Уведомления';
require __DIR__ . '/includes/header.php';

/* ============================================================
   ХЕЛПЕРЫ РЕНДЕРА
   ============================================================ */

if (!function_exists('notif_target')) {
    function notif_target(string $targetType): array
    {
        if (strpos($targetType, 'club_post:') === 0) {
            return [
                'kind'    => 'club_post',
                'club_id' => (int)substr($targetType, strlen('club_post:')),
            ];
        }
        if (strpos($targetType, 'club:') === 0) {
            return [
                'kind'    => 'club',
                'club_id' => (int)substr($targetType, strlen('club:')),
            ];
        }
        return ['kind' => $targetType !== '' ? $targetType : null, 'club_id' => 0];
    }
}

if (!function_exists('notif_text')) {
    function notif_text(array $n): string
    {
        $actor   = '<strong>' . e((string)($n['display_name'] ?? 'Кто-то')) . '</strong>';
        $message = (string)($n['message'] ?? '');
        $targetType = (string)($n['target_type'] ?? '');

        if (strpos($targetType, 'club') === 0 && $message !== '') {
            return e($message);
        }

        return match ($n['type']) {
            'like'              => $actor . ' оценил вашу активность',
            'comment'           => $actor . ' оставил комментарий',
            'follow'            => $actor . ' подписался на вас',
            'mention'           => $actor . ' упомянул вас',
            'segment_lost_lead' => $actor . ' обошёл вас на сегменте',
            'segment_new_lead'  => 'Вы вышли на <strong>1-е место</strong> на сегменте',
            'club_join'         => e($message !== '' ? $message : ($actor . ' вступил в клуб')),
            'club_post'         => e($message !== '' ? $message : ($actor . ' написал на стене клуба')),
            'club_role'         => e($message !== '' ? $message : 'Ваша роль в клубе изменена'),
            'club_event'        => e($message !== '' ? $message : 'Новое событие в клубе'),
            'system'            => e($message !== '' ? $message : 'Системное уведомление'),
            default             => e($message !== '' ? $message : 'Уведомление'),
        };
    }
}

if (!function_exists('notif_icon')) {
    function notif_icon(string $type): array
    {
        return match ($type) {
            'like'              => ['♥',  'like'],
            'comment'           => ['💬', 'comment'],
            'follow'            => ['👤', 'follow'],
            'mention'           => ['@',  'mention'],
            'segment_lost_lead' => ['🥈', 'lost'],
            'segment_new_lead'  => ['🏆', 'new'],
            'club_join'         => ['🏁', 'system'],
            'club_post'         => ['📝', 'comment'],
            'club_role'         => ['⭐', 'system'],
            'club_event'        => ['📅', 'system'],
            'system'            => ['🔔', 'system'],
            default             => ['🔔', 'system'],
        };
    }
}

if (!function_exists('notif_url')) {
    function notif_url(array $n): string
    {
        // 1. Если в уведомлении явно задан URL — используем его
        if (!empty($n['url'])) {
            return (string)$n['url'];
        }

        // 2. Иначе строим из target_type / target_id
        $targetType = (string)($n['target_type'] ?? '');
        $targetId   = (int)($n['target_id'] ?? 0);
        $target     = notif_target($targetType);

        if ($target['kind'] === 'club_post' && $target['club_id'] > 0) {
            return url('club.php?id=' . $target['club_id'] . '#post-' . $targetId);
        }
        if ($target['kind'] === 'club' && $target['club_id'] > 0) {
            return url('club.php?id=' . $target['club_id']);
        }
        if ($targetType === 'club' && $targetId > 0) {
            return url('club.php?id=' . $targetId);
        }
        if ($targetType === 'club_event' && $targetId > 0) {
            return url('club-event.php?id=' . $targetId);
        }
        if ($targetType === 'activity' && $targetId > 0) {
            return url('activity.php?id=' . $targetId);
        }
        if ($targetType === 'segment' && $targetId > 0) {
            return url('segment.php?id=' . $targetId);
        }
        if ($targetType === 'post' && $targetId > 0) {
            return url('post.php?id=' . $targetId);
        }
        if (!empty($n['username'])) {
            return url('profile.php?u=' . urlencode((string)$n['username']));
        }
        return url('notifications.php');
    }
}
?>

<section class="notif-page">

    <header class="notif-page__head">
        <div class="notif-page__title-wrap">
            <h1 class="notif-page__title">Уведомления</h1>
            <?php if ($unreadCount > 0): ?>
                <span class="notif-page__badge"><?= (int)$unreadCount ?></span>
            <?php endif; ?>
        </div>

        <?php if ($unreadCount > 0): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button class="btn btn--ghost btn--sm">✓ Отметить все прочитанными</button>
            </form>
        <?php endif; ?>
    </header>

    <?php if ($error !== null): ?>
        <div class="alert alert--error"><strong>Ошибка загрузки:</strong> <?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$notifications): ?>
        <div class="notif-empty">
            <div class="notif-empty__illustration">
                <div class="notif-empty__bell">🔔</div>
            </div>
            <h2 class="notif-empty__title">Здесь пока пусто</h2>
            <p class="notif-empty__text">
                Как только кто-то оценит вашу активность, оставит комментарий,
                подпишется или обойдёт вас на сегменте — вы увидите это здесь.
            </p>
            <a href="<?= e(url('feed.php')) ?>" class="btn btn--primary">Перейти в ленту</a>
        </div>
    <?php else: ?>
        <div class="notif-card">
            <?php foreach ($notifications as $n): ?>
                <?php
                    [$icon, $kind] = notif_icon((string)$n['type']);
                    $text  = notif_text($n);
                    $url   = notif_url($n);
                    $isNew = !(int)$n['is_read'];
                ?>
                <a href="<?= e($url) ?>"
                   class="notif-row <?= $isNew ? 'notif-row--unread' : '' ?>">
                    <span class="notif-row__icon notif-row__icon--<?= e($kind) ?>">
                        <?= $icon ?>
                    </span>

                    <span class="notif-row__body">
                        <span class="notif-row__text"><?= $text ?></span>
                        <span class="notif-row__meta">
                            <span class="notif-row__time"><?= e(time_ago((string)$n['created_at'])) ?></span>
                            <?php if ($isNew): ?>
                                <span class="notif-row__new">новое</span>
                            <?php endif; ?>
                        </span>
                    </span>

                    <span class="notif-row__arrow" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             width="16" height="16">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
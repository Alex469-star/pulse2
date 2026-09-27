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

try {
    $notifications = Notification::allForUser((int)$me['id'], 100);
    $unreadCount   = Notification::unreadCount((int)$me['id']);
} catch (Throwable $e) {
    $error = $e->getMessage();
    $unreadCount = 0;
}

$pageTitle = 'Уведомления';
require __DIR__ . '/includes/header.php';

/**
 * Текст уведомления.
 */
function notif_text(array $n): string
{
    $actor = '<strong>' . e((string)($n['display_name'] ?? 'Кто-то')) . '</strong>';
    $message = (string)($n['message'] ?? '');

    return match ($n['type']) {
        'like'              => $actor . ' оценил вашу активность',
        'comment'           => $actor . ' оставил комментарий',
        'follow'            => $actor . ' подписался на вас',
        'mention'           => $actor . ' упомянул вас',
        'segment_lost_lead' => $actor . ' обошёл вас на сегменте',
        'segment_new_lead'  => 'Вы вышли на <strong>1-е место</strong> на сегменте',
        'system'            => e($message !== '' ? $message : 'Системное уведомление'),
        default             => e($message !== '' ? $message : 'Уведомление'),
    };
}

/**
 * Иконка и CSS-класс.
 */
function notif_icon(string $type): array
{
    return match ($type) {
        'like'              => ['♥',  'like'],
        'comment'           => ['💬', 'comment'],
        'follow'            => ['👤', 'follow'],
        'mention'           => ['@',  'mention'],
        'segment_lost_lead' => ['🥈', 'lost'],
        'segment_new_lead'  => ['🏆', 'new'],
        'system'            => ['🔔', 'system'],
        default             => ['🔔', 'system'],
    };
}

/**
 * URL, куда ведёт уведомление.
 */
function notif_url(array $n): string
{
    $targetType = (string)($n['target_type'] ?? '');
    $targetId   = (int)($n['target_id'] ?? 0);

    if ($targetType === 'activity' && $targetId > 0) {
        return url('activity.php?id=' . $targetId);
    }
    if ($targetType === 'segment' && $targetId > 0) {
        return url('segment.php?id=' . $targetId);
    }
    if (!empty($n['username'])) {
        return url('profile.php?u=' . urlencode((string)$n['username']));
    }
    return url('notifications.php');
}
?>

<section class="notif-page">

    <!-- Заголовок -->
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
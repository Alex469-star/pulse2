<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Follow.php';

auth_start();
$me = current_user();

$username = trim((string)($_GET['u'] ?? ''));
if ($username === '' && $me) $username = (string)$me['username'];
if ($username === '') redirect(url('index.php'));

$user = User::findByUsername($username);
if (!$user) { http_response_code(404); exit('Пользователь не найден'); }

$isMe = $me && (int)$me['id'] === (int)$user['id'];

// ---- POST: действия ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me) {
    csrf_check($_POST['csrf'] ?? null);
    $action    = $_POST['action'] ?? '';
    $targetId  = (int)($_POST['user_id'] ?? 0);

    if ($targetId > 0 && $targetId !== (int)$me['id']) {
        // Удалить подписчика может только владелец профиля
        if ($action === 'remove_follower' && $isMe) {
            Follow::removeFollower((int)$me['id'], $targetId);
            flash('Подписчик удалён', 'success');
        }
        // Подписаться/отписаться от любого в списке
        if ($action === 'follow') {
            Follow::follow((int)$me['id'], $targetId);
            flash('Вы подписались', 'success');
        }
        if ($action === 'unfollow') {
            Follow::unfollow((int)$me['id'], $targetId);
            flash('Вы отписались', 'success');
        }
    }
    redirect(url('profile-followers.php?u=' . urlencode((string)$user['username'])));
}

$followers = Follow::followers((int)$user['id'], 200, 0);
$pageTitle = 'Подписчики @' . $user['username'];
require __DIR__ . '/includes/header.php';
?>

<section class="connections-page">
    <header class="connections-page__head">
        <div>
            <a href="<?= e(url('profile.php?u=' . urlencode((string)$user['username']))) ?>" class="muted">← Назад в профиль</a>
            <h1 class="section-title" style="text-align:left;margin:8px 0 4px">Подписчики</h1>
            <p class="muted">@<?= e($user['username']) ?> · <?= count($followers) ?></p>
        </div>
        <div class="connections-tabs">
            <a href="<?= e(url('profile-followers.php?u=' . urlencode((string)$user['username']))) ?>"
               class="connections-tab is-active">Подписчики</a>
            <a href="<?= e(url('profile-following.php?u=' . urlencode((string)$user['username']))) ?>"
               class="connections-tab">Подписки</a>
        </div>
    </header>

    <?php if (!$followers): ?>
        <div class="empty"><p>Пока нет подписчиков.</p></div>
    <?php else: ?>
        <ul class="connection-list">
            <?php foreach ($followers as $f): ?>
                <?php
                    $fid = (int)$f['id'];
                    $iFollowBack = (bool)$f['i_follow_back'];
                    $isSelfRow = $me && (int)$me['id'] === $fid;
                ?>
                <li class="connection-row">
                    <a href="<?= e(url('profile.php?u=' . urlencode((string)$f['username']))) ?>"
                       class="connection-row__link">
                        <span class="avatar">
                            <?php if (!empty($f['avatar_url'])): ?>
                                <img src="<?= e($f['avatar_url']) ?>" alt="">
                            <?php else: ?>
                                <?= e(mb_substr((string)$f['display_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </span>
                        <span class="connection-row__info">
                            <span class="connection-row__name"><?= e($f['display_name']) ?></span>
                            <span class="connection-row__meta muted">
                                @<?= e($f['username']) ?>
                                <?php if (!empty($f['city'])): ?> · 📍 <?= e($f['city']) ?><?php endif; ?>
                            </span>
                        </span>
                    </a>

                    <?php if (!$isSelfRow && $me): ?>
                        <div class="connection-row__actions">
                            <?php if ($isMe): ?>
                                <form method="post" onsubmit="return confirm('Удалить @<?= e($f['username']) ?> из подписчиков?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_follower">
                                    <input type="hidden" name="user_id" value="<?= $fid ?>">
                                    <button class="btn btn--ghost btn--sm">Удалить</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($iFollowBack): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="unfollow">
                                    <input type="hidden" name="user_id" value="<?= $fid ?>">
                                    <button class="btn btn--ghost btn--sm">Отписаться</button>
                                </form>
                            <?php else: ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="follow">
                                    <input type="hidden" name="user_id" value="<?= $fid ?>">
                                    <button class="btn btn--primary btn--sm">Подписаться</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
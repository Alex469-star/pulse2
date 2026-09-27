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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me) {
    csrf_check($_POST['csrf'] ?? null);
    $action   = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    if ($targetId > 0 && $targetId !== (int)$me['id']) {
        if ($action === 'unfollow') {
            Follow::unfollow((int)$me['id'], $targetId);
            flash('Вы отписались', 'success');
        }
        if ($action === 'follow') {
            Follow::follow((int)$me['id'], $targetId);
            flash('Вы подписались', 'success');
        }
    }
    redirect(url('profile-following.php?u=' . urlencode((string)$user['username'])));
}

$following = Follow::following((int)$user['id'], 200, 0);
$pageTitle = 'Подписки @' . $user['username'];
require __DIR__ . '/includes/header.php';
?>

<section class="connections-page">
    <header class="connections-page__head">
        <div>
            <a href="<?= e(url('profile.php?u=' . urlencode((string)$user['username']))) ?>" class="muted">← Назад в профиль</a>
            <h1 class="section-title" style="text-align:left;margin:8px 0 4px">Подписки</h1>
            <p class="muted">@<?= e($user['username']) ?> · <?= count($following) ?></p>
        </div>
        <div class="connections-tabs">
            <a href="<?= e(url('profile-followers.php?u=' . urlencode((string)$user['username']))) ?>"
               class="connections-tab">Подписчики</a>
            <a href="<?= e(url('profile-following.php?u=' . urlencode((string)$user['username']))) ?>"
               class="connections-tab is-active">Подписки</a>
        </div>
    </header>

    <?php if (!$following): ?>
        <div class="empty"><p>Пока нет подписок.</p></div>
    <?php else: ?>
        <ul class="connection-list">
            <?php foreach ($following as $f): ?>
                <?php $fid = (int)$f['id']; ?>
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

                    <?php if ($isMe): ?>
                        <div class="connection-row__actions">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="unfollow">
                                <input type="hidden" name="user_id" value="<?= $fid ?>">
                                <button class="btn btn--ghost btn--sm">Отписаться</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
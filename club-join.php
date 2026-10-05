<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';

auth_start();
$me = current_user();

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Токен не указан');
}

$invite = Club::findInvite($token);

if (!$invite) {
    $pageTitle = 'Приглашение недействительно';
    require __DIR__ . '/includes/header.php';
    echo '<section class="form-page"><div class="form-card">';
    echo '<h1 class="form-card__title">Приглашение недействительно</h1>';
    echo '<p>Ссылка истекла, использована максимальное число раз, или клуб удалён.</p>';
    echo '<p style="margin-top:16px"><a class="btn btn--primary" href="' . e(url('clubs.php')) . '">К каталогу клубов</a></p>';
    echo '</div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$clubId = (int)$invite['club_id'];
$club = Club::findById($clubId);

if (!$club || (int)$club['is_banned']) {
    http_response_code(404);
    exit('Клуб недоступен');
}

// Если пользователь не залогинен — запоминаем токен и ведём на вход
if (!$me) {
    $_SESSION['pending_invite_token'] = $token;
    redirect(url('login.php?redirect=' . urlencode(url('club-join.php?token=' . $token))));
}

// Уже участник?
$currentRole = Club::roleOf($clubId, (int)$me['id']);
if ($currentRole) {
    flash('Вы уже состоите в этом клубе', 'info');
    redirect(url('club.php?id=' . $clubId));
}

// Принимаем приглашение
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    try {
        // Принудительно ставим active, так как это инвайт
        db()->prepare(
            'INSERT INTO club_members (club_id, user_id, role, status)
             VALUES (?, ?, "member", "active")
             ON DUPLICATE KEY UPDATE status = "active", joined_at = NOW()'
        )->execute([$clubId, (int)$me['id']]);

        Club::consumeInvite((int)$invite['id']);

        // Пересчитываем количество участников
        db()->prepare(
            'UPDATE clubs SET member_count = (
                SELECT COUNT(*) FROM club_members WHERE club_id = ? AND status = "active"
             ) WHERE id = ?'
        )->execute([$clubId, $clubId]);

        // Уведомляем владельца
        if (function_exists('notify_club_join')) {
            notify_club_join($clubId, (int)$me['id']);
        }

        unset($_SESSION['pending_invite_token']);
        flash('Вы вступили в клуб «' . $club['name'] . '»', 'success');
        redirect(url('club.php?id=' . $clubId));
    } catch (Throwable $e) {
        flash('Ошибка: ' . $e->getMessage(), 'error');
    }
}

$pageTitle = 'Приглашение в клуб';
require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Приглашение в клуб</h1>
        <p class="form-card__subtitle">Вас приглашают вступить в клуб</p>

        <div class="club-join-preview">
            <div class="club-join-preview__avatar">
                <?php if (!empty($club['avatar_url'])): ?>
                    <img src="<?= e($club['avatar_url']) ?>" alt="">
                <?php else: ?>
                    <?= e(mb_substr((string)$club['name'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="club-join-preview__name"><?= e($club['name']) ?></div>
                <div class="muted"><?= (int)$club['member_count'] ?> участников</div>
            </div>
        </div>

        <?php if (!empty($club['description'])): ?>
            <p class="muted" style="margin:16px 0"><?= nl2br(e(mb_substr((string)$club['description'], 0, 300))) ?></p>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="form-actions">
                <button class="btn btn--primary">Вступить в клуб</button>
                <a class="btn btn--ghost" href="<?= e(url('clubs.php')) ?>">Отмена</a>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
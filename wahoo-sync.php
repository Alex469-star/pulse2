<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Activity.php';

auth_start();
$me = require_login();

$user = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$user->execute([(int)$me['id']]);
$user = $user->fetch();

$isConnected = !empty($user['wahoo_access_token']);

$syncedCount = 0;
if ($isConnected) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM wahoo_synced_workouts WHERE user_id = ?');
    $stmt->execute([(int)$me['id']]);
    $syncedCount = (int)$stmt->fetchColumn();
}

$pageTitle = 'Wahoo';
$extraJs = [url('assets/js/wahoo-sync.js') . '?v=' . @filemtime(__DIR__ . '/assets/js/wahoo-sync.js')];
require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Wahoo</h1>
        <p class="form-card__subtitle">
            Автоматическая синхронизация тренировок из Wahoo ELEMNT / KICKR.
        </p>

        <?php if (!$isConnected): ?>
            <div class="alert alert--info">
                Wahoo ещё не подключён. Нажмите кнопку — откроется страница Wahoo, где нужно разрешить доступ.
            </div>
            <a href="<?= e(url('wahoo-connect.php')) ?>" class="btn btn--primary btn--large" style="width:100%">
                Подключить Wahoo
            </a>
        <?php else: ?>
            <div class="alert alert--success">
                Wahoo подключён.
                <?php if (!empty($user['wahoo_connected_at'])): ?>
                    Дата подключения: <?= e(date('d.m.Y H:i', strtotime((string)$user['wahoo_connected_at']))) ?>.
                <?php endif; ?>
                Синхронизировано тренировок: <strong><?= $syncedCount ?></strong>.
            </div>

            <div id="wahoo-sync-results" hidden></div>

            <button type="button"
                    id="wahoo-sync-btn"
                    class="btn btn--primary btn--large"
                    style="width:100%"
                    data-api="<?= e(url('api/wahoo-sync.php')) ?>"
                    data-csrf="<?= e(csrf_token()) ?>">
                Синхронизировать тренировки
            </button>

            <div id="wahoo-sync-progress" class="upload-progress" hidden style="margin-top:14px">
                <div class="upload-progress__bar-wrap">
                    <div id="wahoo-sync-progress-bar" class="upload-progress__bar"></div>
                </div>
                <div id="wahoo-sync-progress-text" class="upload-progress__text">Подготовка…</div>
            </div>

            <div style="margin-top:14px">
                <a href="<?= e(url('wahoo-disconnect.php')) ?>"
                   class="btn btn--ghost"
                   onclick="return confirm('Отключить Wahoo? Синхронизация перестанет работать.')">
                    Отключить Wahoo
                </a>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
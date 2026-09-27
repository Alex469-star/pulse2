<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Post.php';
require_once __DIR__ . '/models/Follow.php';

auth_start();
$me = current_user();

$postId = (int)($_GET['id'] ?? 0);
if ($postId <= 0) {
    http_response_code(404);
    exit('Запись не найдена');
}

$post = Post::findById($postId);
if (!$post) {
    http_response_code(404);
    exit('Запись не найдена');
}

$isOwner = $me && (int)$me['id'] === (int)$post['user_id'];
$visibility = (string)$post['visibility'];

// Проверка доступа
if ($visibility === 'private' && !$isOwner) {
    http_response_code(403);
    $pageTitle = 'Доступ запрещён';
    require __DIR__ . '/includes/header.php';
    echo '<section class="form-page"><div class="form-card"><h1 class="form-card__title">Доступ запрещён</h1><p>Это приватная запись.</p></div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
if ($visibility === 'followers' && !$isOwner) {
    $ok = $me ? Follow::isFollowing((int)$me['id'], (int)$post['user_id']) : false;
    if (!$ok) {
        http_response_code(403);
        $pageTitle = 'Доступ запрещён';
        require __DIR__ . '/includes/header.php';
        echo '<section class="form-page"><div class="form-card"><h1 class="form-card__title">Доступ запрещён</h1><p>Запись доступна только подписчикам автора.</p></div></section>';
        require __DIR__ . '/includes/footer.php';
        exit;
    }
}

// POST: комментарий, удаление комментария, удаление записи
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me) {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'comment') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '') {
            Post::addComment($postId, (int)$me['id'], $body);
            flash('Комментарий добавлен', 'success');
        }
        redirect(url('post.php?id=' . $postId . '#comments'));
    }

    if ($action === 'delete_comment' && !empty($_POST['comment_id'])) {
        Post::deleteComment((int)$_POST['comment_id'], (int)$me['id']);
        flash('Комментарий удалён', 'success');
        redirect(url('post.php?id=' . $postId . '#comments'));
    }

    if ($action === 'delete' && $isOwner) {
        Post::delete($postId, (int)$me['id']);
        flash('Запись удалена', 'success');
        redirect(url('feed.php'));
    }
}

$photos = Post::photos($postId);
$comments = Post::comments($postId);

$stmt = db()->prepare('SELECT COUNT(*) FROM post_likes WHERE post_id = ?');
$stmt->execute([$postId]);
$likesCount = (int)$stmt->fetchColumn();

$likedByMe = false;
if ($me) {
    $stmt = db()->prepare('SELECT 1 FROM post_likes WHERE post_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$postId, (int)$me['id']]);
    $likedByMe = (bool)$stmt->fetchColumn();
}

$pageTitle = $post['title'];
require __DIR__ . '/includes/header.php';
?>

<section class="post-page">
    <header class="post-page__head">
        <div class="post-page__title-block">
            <h1 class="post-page__title"><?= e($post['title']) ?></h1>
            <p class="muted">
                <a href="<?= e(url('profile.php?u=' . urlencode((string)$post['username']))) ?>">
                    <strong><?= e($post['display_name']) ?></strong>
                </a>
                @<?= e($post['username']) ?>
                · <?= e(time_ago((string)$post['created_at'])) ?>
                <?php if ($visibility === 'private'): ?> · 🔒 приватная<?php endif; ?>
                <?php if ($visibility === 'followers'): ?> · 👥 для подписчиков<?php endif; ?>
            </p>
        </div>

        <?php if ($isOwner): ?>
            <div class="post-page__actions">
                <a href="<?= e(url('post-create.php?id=' . $postId)) ?>"
                   class="btn btn--ghost btn--sm">✏️ Редактировать</a>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Удалить запись? Это необратимо.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn--ghost btn--sm">🗑 Удалить</button>
                </form>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($photos): ?>
    <div class="post-carousel" data-count="<?= count($photos) ?>">
        <div class="post-carousel__viewport">
            <div class="post-carousel__track" id="post-carousel-track">
                <?php foreach ($photos as $i => $ph): ?>
                    <button type="button"
                            class="post-carousel__slide"
                            data-lightbox="post"
                            data-index="<?= $i ?>"
                            data-src="<?= e($ph['url']) ?>"
                            aria-label="Открыть фото <?= $i + 1 ?> из <?= count($photos) ?>">
                        <img src="<?= e($ph['url']) ?>" alt="" loading="lazy">
                    </button>
                <?php endforeach; ?>
            </div>

            <?php if (count($photos) > 1): ?>
                <button type="button" class="post-carousel__nav post-carousel__nav--prev" aria-label="Предыдущее фото">‹</button>
                <button type="button" class="post-carousel__nav post-carousel__nav--next" aria-label="Следующее фото">›</button>
            <?php endif; ?>
        </div>

        <?php if (count($photos) > 1): ?>
            <div class="post-carousel__dots" role="tablist">
                <?php foreach ($photos as $i => $ph): ?>
                    <button type="button"
                            class="post-carousel__dot <?= $i === 0 ? 'is-active' : '' ?>"
                            data-index="<?= $i ?>"
                            aria-label="Перейти к фото <?= $i + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

    <div class="post-page__body">
        <?= nl2br(e((string)$post['body'])) ?>
    </div>

    <div class="activity-view__actions">
        <?php if ($me): ?>
            <button type="button"
                    class="action js-post-like <?= $likedByMe ? 'is-active' : '' ?>"
                    data-post-id="<?= $postId ?>">
                <span class="action__icon">♥</span>
                <span class="js-post-like-count"><?= $likesCount ?></span>
            </button>
        <?php else: ?>
            <span class="action"><span class="action__icon">♥</span> <?= $likesCount ?></span>
        <?php endif; ?>

        <a class="action" href="#comments">
            <span class="action__icon">💬</span>
            <span><?= count($comments) ?></span>
        </a>
    </div>

    <h2 id="comments" class="section-title" style="text-align:left;font-size:20px">
        Комментарии (<?= count($comments) ?>)
    </h2>

    <div class="comments">
        <?php if (!$comments): ?>
            <div class="empty" style="padding:24px">
                <p class="muted">Пока нет комментариев.</p>
            </div>
        <?php else: ?>
            <?php foreach ($comments as $c): ?>
                <?php $canDelete = $me && ((int)$me['id'] === (int)$c['user_id'] || $isOwner); ?>
                <div class="comment">
                    <span class="avatar avatar--sm">
                        <?php if (!empty($c['avatar_url'])): ?>
                            <img src="<?= e($c['avatar_url']) ?>" alt="">
                        <?php else: ?>
                            <?= e(mb_substr((string)$c['display_name'], 0, 1)) ?>
                        <?php endif; ?>
                    </span>
                    <div class="comment__body">
                        <div class="comment__head">
                            <a href="<?= e(url('profile.php?u=' . urlencode((string)$c['username']))) ?>">
                                <strong><?= e($c['display_name']) ?></strong>
                            </a>
                            <span class="comment__time muted"><?= e(time_ago((string)$c['created_at'])) ?></span>
                            <?php if ($canDelete): ?>
                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('Удалить комментарий?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                                    <button class="comment__delete" title="Удалить">×</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div><?= nl2br(e($c['body'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($me): ?>
        <form method="post" class="comment-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="comment">
            <textarea name="body" rows="2" placeholder="Написать комментарий..." required maxlength="1000"></textarea>
            <button class="btn btn--primary">Отправить</button>
        </form>
    <?php else: ?>
        <div class="activity-view__login-cta">
            <a href="<?= e(url('login.php')) ?>" class="btn btn--primary">Войдите</a>, чтобы оставить комментарий.
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    var btn = document.querySelector('.js-post-like');
    if (!btn) return;
    var API = <?= json_encode(url('api/post-like.php')) ?>;
    var CSRF = <?= json_encode(csrf_token()) ?>;

    btn.addEventListener('click', function () {
        if (btn.dataset.loading === '1') return;
        btn.dataset.loading = '1';

        var countEl = btn.querySelector('.js-post-like-count');
        var wasActive = btn.classList.contains('is-active');
        var oldCount = parseInt(countEl.textContent, 10) || 0;

        btn.classList.toggle('is-active', !wasActive);
        countEl.textContent = wasActive ? Math.max(0, oldCount - 1) : oldCount + 1;

        fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ post_id: <?= (int)$postId ?> })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.ok) {
                btn.classList.toggle('is-active', wasActive);
                countEl.textContent = oldCount;
                return;
            }
            btn.classList.toggle('is-active', !!res.data.liked);
            countEl.textContent = res.data.count;
        })
        .catch(function () {
            btn.classList.toggle('is-active', wasActive);
            countEl.textContent = oldCount;
        })
        .finally(function () { btn.dataset.loading = '0'; });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
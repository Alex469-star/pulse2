<?php
declare(strict_types=1);

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
    $parentId = isset($_POST['parent_id']) && (int)$_POST['parent_id'] > 0
        ? (int)$_POST['parent_id']
        : null;
    if ($body !== '') {
        Post::addComment($postId, (int)$me['id'], $body, $parentId);
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
$photoUrls = array_map(function ($p) { return (string)$p['url']; }, $photos);
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
                    <div class="post-carousel__counter"><span id="post-carousel-counter">1</span> / <?= count($photos) ?></div>
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

    <?php
/**
 * Рекурсивный рендер дерева комментариев.
 * $tree — массив: parent_id => [комментарии]
 */
if (!function_exists('render_post_comment')) {
    function render_post_comment(array $c, array $tree, bool $isOwner, ?array $me, int $depth = 0): void
    {
        $canDelete = $me && ((int)$me['id'] === (int)$c['user_id'] || $isOwner);
        $children  = $tree[(int)$c['id']] ?? [];
        ?>
        <div class="comment <?= $depth > 0 ? 'comment--reply' : '' ?>"
             id="comment-<?= (int)$c['id'] ?>"
             data-comment-id="<?= (int)$c['id'] ?>">
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
                    <?php if ($me): ?>
                        <button type="button"
                                class="comment__reply js-reply-btn"
                                data-comment-id="<?= (int)$c['id'] ?>"
                                data-display-name="<?= e($c['display_name']) ?>"
                                title="Ответить">↩ Ответить</button>
                    <?php endif; ?>
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

                <?php if ($children): ?>
                    <div class="comment__children">
                        <?php foreach ($children as $child): ?>
                            <?php render_post_comment($child, $tree, $isOwner, $me, $depth + 1); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

// Строим дерево: parent_id => список комментариев
$tree = [];
foreach ($comments as $c) {
    $pid = $c['parent_id'] !== null ? (int)$c['parent_id'] : 0;
    $tree[$pid][] = $c;
}
$roots = $tree[0] ?? [];
?>

<div class="comments">
    <?php if (!$roots): ?>
        <div class="empty" style="padding:24px">
            <p class="muted">Пока нет комментариев.</p>
        </div>
    <?php else: ?>
        <?php foreach ($roots as $c): ?>
            <?php render_post_comment($c, $tree, $isOwner, $me); ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

    <?php if ($me): ?>
    <form method="post" class="comment-form" id="post-comment-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="comment">
        <input type="hidden" name="parent_id" id="comment-parent-id" value="">
        <div class="comment-form__reply-hint" id="comment-reply-hint" hidden>
            Ответ на <strong id="comment-reply-name"></strong>
            <button type="button" class="comment-form__reply-cancel" id="comment-reply-cancel" aria-label="Отменить ответ">×</button>
        </div>
        <textarea name="body" rows="2" placeholder="Написать комментарий..." required maxlength="1000"></textarea>
        <button class="btn btn--primary">Отправить</button>
    </form>
<?php else: ?>
        <div class="activity-view__login-cta">
            <a href="<?= e(url('login.php')) ?>" class="btn btn--primary">Войдите</a>, чтобы оставить комментарий.
        </div>
    <?php endif; ?>
</section>

<?php if ($photos): ?>
    <div class="lightbox" id="post-lightbox" hidden>
        <button type="button" class="lightbox__close" data-close aria-label="Закрыть">×</button>
        <button type="button" class="lightbox__prev"  data-prev aria-label="Предыдущее">‹</button>
        <button type="button" class="lightbox__next"  data-next aria-label="Следующее">›</button>
        <div class="lightbox__counter" id="post-lb-counter"></div>
        <div class="lightbox__img-wrap">
            <img src="" alt="" id="post-lb-img">
        </div>
    </div>
<?php endif; ?>

<script>
/* ============================================================
   AJAX-ЛАЙК
   ============================================================ */
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

/* ============================================================
   КАРУСЕЛЬ ФОТО
   ============================================================ */
(function () {
    "use strict";

    var carousel = document.querySelector(".post-carousel");
    if (!carousel) return;

    var track    = carousel.querySelector(".post-carousel__track");
    var slides   = carousel.querySelectorAll(".post-carousel__slide");
    var prevBtn  = carousel.querySelector(".post-carousel__nav--prev");
    var nextBtn  = carousel.querySelector(".post-carousel__nav--next");
    var dots     = carousel.querySelectorAll(".post-carousel__dot");
    var counter  = document.getElementById("post-carousel-counter");

    if (!track || !slides.length) return;

    function slideWidth() { return track.clientWidth || 1; }
    function currentIndex() {
        return Math.round(track.scrollLeft / slideWidth());
    }

    function goTo(i) {
        i = Math.max(0, Math.min(slides.length - 1, i));
        track.scrollTo({ left: i * slideWidth(), behavior: "smooth" });
    }

    function syncUI() {
        var idx = currentIndex();
        dots.forEach(function (d, i) { d.classList.toggle("is-active", i === idx); });
        if (counter) counter.textContent = (idx + 1);
        if (prevBtn) prevBtn.disabled = idx === 0;
        if (nextBtn) nextBtn.disabled = idx === slides.length - 1;
    }

    if (prevBtn) prevBtn.addEventListener("click", function (e) { e.preventDefault(); e.stopPropagation(); goTo(currentIndex() - 1); });
    if (nextBtn) nextBtn.addEventListener("click", function (e) { e.preventDefault(); e.stopPropagation(); goTo(currentIndex() + 1); });
    dots.forEach(function (d, i) {
        d.addEventListener("click", function (e) { e.preventDefault(); e.stopPropagation(); goTo(i); });
    });

    var scrollTimer = null;
    track.addEventListener("scroll", function () {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(syncUI, 80);
    }, { passive: true });

    window.addEventListener("resize", function () { goTo(currentIndex()); });
    syncUI();
})();

/* ============================================================
   ЛАЙТБОКС
   ============================================================ */
(function () {
    "use strict";

    var photos = <?= json_encode($photoUrls, JSON_UNESCAPED_UNICODE) ?>;
    if (!photos.length) return;

    var lb        = document.getElementById("post-lightbox");
    if (!lb) return;

    var lbImg     = document.getElementById("post-lb-img");
    var lbCounter = document.getElementById("post-lb-counter");
    var btnClose  = lb.querySelector("[data-close]");
    var btnPrev   = lb.querySelector("[data-prev]");
    var btnNext   = lb.querySelector("[data-next]");

    var current = 0;
    var isOpen  = false;

    function show(index) {
        if (index < 0) index = photos.length - 1;
        if (index >= photos.length) index = 0;
        current = index;
        lbImg.src = photos[index];
        lbCounter.textContent = (index + 1) + " / " + photos.length;
    }

    function open(index) {
        if (isOpen) return;
        isOpen = true;
        show(index);
        lb.hidden = false;
        document.body.style.overflow = "hidden";
    }

    function close() {
        if (!isOpen) return;
        isOpen = false;
        lb.hidden = true;
        document.body.style.overflow = "";
        lbImg.src = "";
    }

    /* ---- Открытие: клик по слайду карусели ---- */
    document.addEventListener("click", function (e) {
        var slide = e.target.closest(".post-carousel__slide");
        if (!slide) return;
        if (isOpen) return;                    // защита от повторного открытия
        e.preventDefault();
        e.stopPropagation();
        var idx = parseInt(slide.dataset.index, 10) || 0;
        open(idx);
    }, true);   // ← capture: true — перехватываем ДО того, как событие дойдёт до остальных обработчиков

    /* ---- Закрытие: клик по × ---- */
    btnClose.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        close();
    });

    /* ---- Навигация ---- */
    btnPrev.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        show(current - 1);
    });

    btnNext.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        show(current + 1);
    });

    /* ---- Клик по фону лайтбокса (не по картинке, не по кнопкам) ---- */
    lb.addEventListener("click", function (e) {
        // Закрываем только если клик пришёлся на сам .lightbox
        // или на .lightbox__img-wrap (обёртку), но не на img и не на кнопки.
        if (e.target === lb || e.target.classList.contains("lightbox__img-wrap")) {
            close();
        }
    });

    /* ---- Клавиатура ---- */
    document.addEventListener("keydown", function (e) {
        if (!isOpen) return;
        if (e.key === "Escape")      { e.preventDefault(); close(); }
        if (e.key === "ArrowLeft")   { e.preventDefault(); show(current - 1); }
        if (e.key === "ArrowRight")  { e.preventDefault(); show(current + 1); }
    });

    /* ---- Свайпы ---- */
    var touchStartX = 0;
    lb.addEventListener("touchstart", function (e) {
        touchStartX = e.touches[0].clientX;
    }, { passive: true });
    lb.addEventListener("touchend", function (e) {
        var diff = e.changedTouches[0].clientX - touchStartX;
        if (Math.abs(diff) < 50) return;
        if (diff > 0) show(current - 1);
        else          show(current + 1);
    });
})();

/* ============================================================
   ОТВЕТЫ НА КОММЕНТАРИИ
   ============================================================ */
(function () {
    "use strict";

    var form       = document.getElementById("post-comment-form");
    if (!form) return;

    var parentInput = document.getElementById("comment-parent-id");
    var hint        = document.getElementById("comment-reply-hint");
    var hintName    = document.getElementById("comment-reply-name");
    var cancelBtn   = document.getElementById("comment-reply-cancel");
    var textarea    = form.querySelector("textarea[name=body]");

    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-reply-btn");
        if (!btn) return;
        e.preventDefault();

        parentInput.value = btn.dataset.commentId;
        hintName.textContent = btn.dataset.displayName || "";
        hint.hidden = false;
        textarea.focus();
        textarea.placeholder = "Ответ " + (btn.dataset.displayName || "") + "...";
    });

    if (cancelBtn) {
        cancelBtn.addEventListener("click", function () {
            parentInput.value = "";
            hint.hidden = true;
            textarea.placeholder = "Написать комментарий...";
        });
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
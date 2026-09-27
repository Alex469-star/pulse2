<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Post.php';

auth_start();
$me = require_login();

// ============================================================
// POST: удаление
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'delete' && !empty($_POST['post_id'])) {
        try {
            $postId = (int)$_POST['post_id'];
            Post::delete($postId, (int)$me['id']);
            flash('Запись удалена', 'success');
        } catch (Throwable $e) {
            flash('Не удалось удалить: ' . $e->getMessage(), 'error');
        }
        redirect(url('my-posts.php'));
    }
}

// ============================================================
// Загрузка всех записей пользователя
// ============================================================
$limit  = 50;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$posts = [];
$error = null;

try {
    $posts = Post::byUser((int)$me['id'], $limit, $offset);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// Фото для всех постов одним запросом
$photosByPost = [];
if ($posts) {
    try {
        $ids = array_column($posts, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT * FROM post_photos WHERE post_id IN ($ph)
             ORDER BY post_id ASC, order_index ASC, id ASC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $photosByPost[(int)$row['post_id']][] = $row;
        }
    } catch (Throwable $e) {
        $photosByPost = [];
    }
}

// Сводная статистика
$totalPosts = count($posts);
$totalPublic = 0;
$totalFollowers = 0;
$totalPrivate = 0;
foreach ($posts as $p) {
    if ($p['visibility'] === 'public') $totalPublic++;
    elseif ($p['visibility'] === 'followers') $totalFollowers++;
    else $totalPrivate++;
}

$hasMore = count($posts) === $limit;

$pageTitle = 'Мои записи';
require __DIR__ . '/includes/header.php';
?>

<section class="my-posts-page">
    <header class="my-posts-page__head">
        <div>
            <h1 class="section-title" style="text-align:left;margin-bottom:4px">Мои записи</h1>
            <p class="muted">
                Всего: <strong><?= (int)$totalPosts ?></strong>
                · публичных: <?= (int)$totalPublic ?>
                · для подписчиков: <?= (int)$totalFollowers ?>
                · приватных: <?= (int)$totalPrivate ?>
            </p>
        </div>
        <a href="<?= e(url('post-create.php')) ?>" class="btn btn--primary">+ Написать</a>
    </header>

    <?php if ($error !== null): ?>
        <div class="alert alert--error"><strong>Ошибка:</strong> <?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$posts): ?>
        <div class="empty">
            <p>У вас пока нет записей.</p>
            <p class="muted" style="margin-top:8px">Начните с первой — поделитесь историей или впечатлением.</p>
            <a href="<?= e(url('post-create.php')) ?>" class="btn btn--primary">Написать первую</a>
        </div>
    <?php else: ?>
        <div class="my-posts-list">
            <?php foreach ($posts as $p): ?>
                <?php
                    $pid = (int)$p['id'];
                    $photos = $photosByPost[$pid] ?? [];
                    $visibility = (string)$p['visibility'];
                ?>
                <article class="my-post-row">
                    <!-- Превью -->
                    <div class="my-post-row__thumb">
                        <?php if ($photos): ?>
                            <img src="<?= e($photos[0]['url']) ?>" alt="">
                        <?php else: ?>
                            <div class="my-post-row__thumb-empty">📖</div>
                        <?php endif; ?>
                    </div>

                    <!-- Основное -->
                    <div class="my-post-row__body">
                        <div class="my-post-row__head">
                            <span class="my-post-row__visibility my-post-row__visibility--<?= e($visibility) ?>">
                                <?php if ($visibility === 'public'): ?>
                                    🌐 Публичная
                                <?php elseif ($visibility === 'followers'): ?>
                                    👥 Подписчики
                                <?php else: ?>
                                    🔒 Приватная
                                <?php endif; ?>
                            </span>
                            <span class="muted"><?= e(time_ago((string)$p['created_at'])) ?></span>
                        </div>

                        <a href="<?= e(url('post.php?id=' . $pid)) ?>" class="my-post-row__title-link">
                            <h3 class="my-post-row__title"><?= e($p['title']) ?></h3>
                        </a>

                        <p class="my-post-row__excerpt">
                            <?= e(mb_substr(strip_tags((string)$p['body']), 0, 200)) ?>
                            <?= mb_strlen((string)$p['body']) > 200 ? '…' : '' ?>
                        </p>

                        <div class="my-post-row__meta">
                            <?php if ($photos): ?>
                                <span class="my-post-row__meta-item">🖼 <?= count($photos) ?></span>
                            <?php endif; ?>
                            <span class="my-post-row__meta-item">♥ <?= (int)($p['likes_count'] ?? 0) ?></span>
                            <span class="my-post-row__meta-item">💬 <?= (int)($p['comments_count'] ?? 0) ?></span>
                        </div>
                    </div>

                    <!-- Действия -->
                    <div class="my-post-row__actions">
                        <a href="<?= e(url('post.php?id=' . $pid)) ?>"
                           class="btn btn--ghost btn--sm">👁 Открыть</a>
                        <a href="<?= e(url('post-create.php?id=' . $pid)) ?>"
                           class="btn btn--ghost btn--sm">✏️ Редактировать</a>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Удалить запись? Это необратимо.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="post_id" value="<?= $pid ?>">
                            <button class="btn btn--ghost btn--sm my-post-row__delete">🗑 Удалить</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($hasMore || $page > 1): ?>
            <nav class="feed-pagination">
                <?php $prevQs = http_build_query(['page' => $page - 1]); ?>
                <?php $nextQs = http_build_query(['page' => $page + 1]); ?>
                <?php if ($page > 1): ?>
                    <a href="?<?= e($prevQs) ?>" class="btn btn--ghost">← Новее</a>
                <?php else: ?><span></span><?php endif; ?>
                <?php if ($hasMore): ?>
                    <a href="?<?= e($nextQs) ?>" class="btn btn--ghost">Старее →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
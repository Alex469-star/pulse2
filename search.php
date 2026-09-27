<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/models/Post.php';

auth_start();
$me = require_login();

// ---- Параметры ----
$q       = trim((string)($_GET['q'] ?? ''));
$tab     = (string)($_GET['tab'] ?? 'users');   // users | activities | posts
$city    = trim((string)($_GET['city'] ?? ''));
$type    = (string)($_GET['type'] ?? '');       // для активностей
$limit   = 40;

$allowedTabs = ['users', 'activities', 'posts'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'users';

$allowedTypes = ['', 'run', 'ride', 'swim', 'ski', 'walk', 'hike', 'other'];
if (!in_array($type, $allowedTypes, true)) $type = '';

$users      = [];
$activities = [];
$posts      = [];
$error      = null;

// ============================================================
// ПОИСК
// ============================================================

if ($q !== '' || $city !== '' || $type !== '') {
    try {
        if ($tab === 'users') {
            $users = search_users($q, $city, $limit);
        } elseif ($tab === 'activities') {
            $activities = search_activities($q, $type, (int)$me['id'], $limit);
        } elseif ($tab === 'posts') {
            $posts = search_posts($q, (int)$me['id'], $limit);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Поиск';
require __DIR__ . '/includes/header.php';

// ============================================================
// ФУНКЦИИ ПОИСКА
// ============================================================

/**
 * Поиск пользователей по имени / username / городу.
 */
function search_users(string $q, string $city, int $limit): array
{
    $sql = 'SELECT id, username, display_name, avatar_url, city, country, bio, is_public
            FROM users
            WHERE 1=1';
    $params = [];

    if ($q !== '') {
        $sql .= ' AND (username LIKE :q OR display_name LIKE :q2)';
        $params[':q']  = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
    }
    if ($city !== '') {
        $sql .= ' AND city LIKE :city';
        $params[':city'] = '%' . $city . '%';
    }

    $sql .= ' ORDER BY display_name ASC LIMIT :lim';

    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Поиск активностей. Учитываем видимость:
 *  - public: видят все
 *  - followers: видят подписчики
 *  - private: только автор
 */
function search_activities(string $q, string $type, int $viewerId, int $limit): array
{
    $sql = 'SELECT a.*, u.username, u.display_name, u.avatar_url,
                   (SELECT COUNT(*) FROM activity_likes l WHERE l.activity_id = a.id) AS likes_count,
                   (SELECT COUNT(*) FROM activity_comments c WHERE c.activity_id = a.id) AS comments_count
            FROM activities a
            JOIN users u ON u.id = a.user_id
            WHERE (a.visibility = "public"
                   OR a.user_id = :me
                   OR (a.visibility = "followers" AND a.user_id IN (
                        SELECT following_id FROM follows WHERE follower_id = :me2
                   )))';
    $params = [':me' => $viewerId, ':me2' => $viewerId];

    if ($q !== '') {
        $sql .= ' AND (a.title LIKE :q OR a.description LIKE :q2)';
        $params[':q']  = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
    }
    if ($type !== '') {
        $sql .= ' AND a.type = :type';
        $params[':type'] = $type;
    }

    $sql .= ' ORDER BY a.created_at DESC LIMIT :lim';

    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Поиск записей блога с учётом видимости.
 */
function search_posts(string $q, int $viewerId, int $limit): array
{
    $sql = 'SELECT p.*, u.username, u.display_name, u.avatar_url,
                   (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id) AS likes_count,
                   (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id) AS comments_count
            FROM posts p
            JOIN users u ON u.id = p.user_id
            WHERE (p.visibility = "public"
                   OR p.user_id = :me
                   OR (p.visibility = "followers" AND p.user_id IN (
                        SELECT following_id FROM follows WHERE follower_id = :me2
                   )))';
    $params = [':me' => $viewerId, ':me2' => $viewerId];

    if ($q !== '') {
        $sql .= ' AND (p.title LIKE :q OR p.body LIKE :q2)';
        $params[':q']  = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
    }

    $sql .= ' ORDER BY p.created_at DESC LIMIT :lim';

    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Фото постов
    if ($rows) {
        $ids = array_column($rows, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT * FROM post_photos WHERE post_id IN ($ph)
             ORDER BY post_id ASC, order_index ASC, id ASC"
        );
        $stmt->execute($ids);
        $photos = [];
        foreach ($stmt->fetchAll() as $p) {
            $photos[(int)$p['post_id']][] = $p;
        }
        foreach ($rows as &$r) {
            $r['photos'] = $photos[(int)$r['id']] ?? [];
        }
        unset($r);
    }

    return $rows;
}

function search_activity_icon(string $t): string
{
    return match ($t) {
        'run' => '🏃', 'ride' => '🚴', 'swim' => '🏊', 'ski' => '⛷️',
        'walk' => '🚶', 'hike' => '🥾', default => '📦',
    };
}
function search_activity_label(string $t): string
{
    return match ($t) {
        'run' => 'Бег', 'ride' => 'Велосипед', 'swim' => 'Плавание',
        'ski' => 'Лыжи', 'walk' => 'Ходьба', 'hike' => 'Хайкинг',
        default => 'Другое',
    };
}
?>

<section class="search-page">
    <h1 class="section-title" style="text-align:left;margin-bottom:20px">Поиск</h1>

    <form method="get" class="search-form">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">

        <div class="search-form__row">
            <input type="text"
                   name="q"
                   class="search-form__input"
                   placeholder="Имя, username, город, название активности..."
                   value="<?= e($q) ?>"
                   autofocus>
            <button class="btn btn--primary">Найти</button>
        </div>

        <?php if ($tab === 'users'): ?>
            <div class="search-form__row">
                <input type="text"
                       name="city"
                       class="search-form__input"
                       placeholder="Город (например: Москва)"
                       value="<?= e($city) ?>">
            </div>
        <?php endif; ?>

        <?php if ($tab === 'activities'): ?>
            <div class="search-form__tabs" style="margin-bottom:12px">
                <a href="?<?= e(http_build_query(['tab' => 'activities', 'q' => $q, 'type' => ''])) ?>"
                   class="search-tab <?= $type === '' ? 'is-active' : '' ?>">Все типы</a>
                <?php
                    $typeOptions = [
                        'run'  => '🏃 Бег',
                        'ride' => '🚴 Вело',
                        'swim' => '🏊 Плавание',
                        'ski'  => '⛷️ Лыжи',
                        'walk' => '🚶 Ходьба',
                        'hike' => '🥾 Хайкинг',
                    ];
                ?>
                <?php foreach ($typeOptions as $key => $label): ?>
                    <?php $qs = http_build_query(['tab' => 'activities', 'q' => $q, 'type' => $key]); ?>
                    <a href="?<?= e($qs) ?>" class="search-tab <?= $type === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="search-form__tabs">
            <?php
                $tabLabels = [
                    'users'      => '👤 Люди',
                    'activities' => '🏃 Активности',
                    'posts'      => '📖 Записи',
                ];
            ?>
            <?php foreach ($tabLabels as $key => $label): ?>
                <?php
                    $qs = http_build_query([
                        'tab'  => $key,
                        'q'    => $q,
                        'city' => $key === 'users' ? $city : '',
                        'type' => $key === 'activities' ? $type : '',
                    ]);
                ?>
                <a href="?<?= e($qs) ?>" class="search-tab <?= $tab === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <div class="alert alert--error"><strong>Ошибка поиска:</strong> <?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($q === '' && $city === '' && $type === ''): ?>
        <div class="empty">
            <p>Введите запрос, чтобы найти людей, активности или записи.</p>
            <p class="muted" style="margin-top:8px">
                Например: <strong>Москва</strong>, <strong>марафон</strong>, <strong>велосипед</strong>
            </p>
        </div>

    <?php elseif ($tab === 'users'): ?>
        <?php if (!$users): ?>
            <div class="empty">
                <p>Ничего не найдено по запросу «<?= e($q) ?>»<?= $city ? ' в городе «' . e($city) . '»' : '' ?>.</p>
                <p class="muted" style="margin-top:8px">Попробуйте изменить запрос.</p>
            </div>
        <?php else: ?>
            <p class="muted" style="margin-bottom:14px">Найдено пользователей: <strong><?= count($users) ?></strong></p>
            <ul class="user-list">
                <?php foreach ($users as $u): ?>
                    <li class="user-row">
                        <a href="<?= e(url('profile.php?u=' . urlencode((string)$u['username']))) ?>" class="user-row__link">
                            <span class="avatar">
                                <?php if (!empty($u['avatar_url'])): ?>
                                    <img src="<?= e($u['avatar_url']) ?>" alt="">
                                <?php else: ?>
                                    <?= e(mb_substr((string)$u['display_name'], 0, 1)) ?>
                                <?php endif; ?>
                            </span>
                            <span class="user-row__info">
                                <span class="user-row__name"><?= e($u['display_name']) ?></span>
                                <span class="user-row__meta muted">
                                    @<?= e($u['username']) ?>
                                    <?php if (!empty($u['city'])): ?> · 📍 <?= e($u['city']) ?><?php endif; ?>
                                    <?php if (!empty($u['country'])): ?>, <?= e($u['country']) ?><?php endif; ?>
                                </span>
                                <?php if (!empty($u['bio'])): ?>
                                    <span class="user-row__bio muted"><?= e(mb_substr((string)$u['bio'], 0, 100)) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="user-row__cta">Открыть →</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    <?php elseif ($tab === 'activities'): ?>
        <?php if (!$activities): ?>
            <div class="empty">
                <p>Ничего не найдено по запросу «<?= e($q) ?>».</p>
            </div>
        <?php else: ?>
            <p class="muted" style="margin-bottom:14px">Найдено активностей: <strong><?= count($activities) ?></strong></p>
            <div class="feed__list">
                <?php foreach ($activities as $a): ?>
                    <article class="activity-card">
                        <div class="activity-card__head">
                            <a class="activity-card__user" href="<?= e(url('profile.php?u=' . urlencode((string)$a['username']))) ?>">
                                <span class="avatar avatar--sm">
                                    <?php if (!empty($a['avatar_url'])): ?>
                                        <img src="<?= e($a['avatar_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(mb_substr((string)$a['display_name'], 0, 1)) ?>
                                    <?php endif; ?>
                                </span>
                                <span class="activity-card__meta">
                                    <span class="activity-card__name"><?= e($a['display_name']) ?></span>
                                    <span class="activity-card__sub muted">@<?= e($a['username']) ?> · <?= e(time_ago((string)$a['created_at'])) ?></span>
                                </span>
                            </a>
                            <span class="activity-type">
                                <?= e(search_activity_icon((string)$a['type'])) ?>
                                <?= e(search_activity_label((string)$a['type'])) ?>
                            </span>
                        </div>

                        <a href="<?= e(url('activity.php?id=' . (int)$a['id'])) ?>" class="activity-card__title-link">
                            <h3 class="activity-card__title"><?= e($a['title']) ?></h3>
                        </a>

                        <?php if (!empty($a['description'])): ?>
                            <div class="activity-card__description">
                                <?= nl2br(e(mb_substr((string)$a['description'], 0, 200))) ?><?= mb_strlen((string)$a['description']) > 200 ? '…' : '' ?>
                            </div>
                        <?php endif; ?>

                        <div class="activity-card__stats">
                            <div class="stat"><span class="stat__value"><?= e(format_distance((float)$a['distance_m'])) ?></span><span class="stat__label">Дистанция</span></div>
                            <div class="stat"><span class="stat__value"><?= e(format_duration((int)$a['duration_sec'])) ?></span><span class="stat__label">Время</span></div>
                            <div class="stat"><span class="stat__value"><?= (int)$a['likes_count'] ?></span><span class="stat__label">Лайков</span></div>
                            <div class="stat"><span class="stat__value"><?= (int)$a['comments_count'] ?></span><span class="stat__label">Комм.</span></div>
                        </div>

                        <div class="activity-card__actions">
                            <a class="action" href="<?= e(url('activity.php?id=' . (int)$a['id'])) ?>">
                                <span class="action__icon">🔗</span><span>Открыть</span>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($tab === 'posts'): ?>
        <?php if (!$posts): ?>
            <div class="empty">
                <p>Ничего не найдено по запросу «<?= e($q) ?>».</p>
            </div>
        <?php else: ?>
            <p class="muted" style="margin-bottom:14px">Найдено записей: <strong><?= count($posts) ?></strong></p>
            <div class="feed__list">
                <?php foreach ($posts as $p): ?>
                    <?php $pid = (int)$p['id']; ?>
                    <article class="post-card">
                        <div class="post-card__head">
                            <a class="post-card__user" href="<?= e(url('profile.php?u=' . urlencode((string)$p['username']))) ?>">
                                <span class="avatar avatar--sm">
                                    <?php if (!empty($p['avatar_url'])): ?>
                                        <img src="<?= e($p['avatar_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(mb_substr((string)$p['display_name'], 0, 1)) ?>
                                    <?php endif; ?>
                                </span>
                                <span class="activity-card__meta">
                                    <span class="activity-card__name"><?= e($p['display_name']) ?></span>
                                    <span class="activity-card__sub muted">@<?= e($p['username']) ?> · <?= e(time_ago((string)$p['created_at'])) ?></span>
                                </span>
                            </a>
                            <span class="activity-type activity-type--post">📖 Запись</span>
                        </div>

                        <a href="<?= e(url('post.php?id=' . $pid)) ?>" class="post-card__title-link">
                            <h3 class="post-card__title"><?= e($p['title']) ?></h3>
                        </a>

                        <?php if (!empty($p['photos'])): ?>
                            <div class="post-card__photos post-card__photos--<?= count($p['photos']) === 1 ? 'single' : 'multi' ?>">
                                <?php foreach ($p['photos'] as $ph): ?>
                                    <a href="<?= e(url('post.php?id=' . $pid)) ?>" class="post-card__photo">
                                        <img src="<?= e($ph['url']) ?>" alt="">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="post-card__body">
                            <?= nl2br(e(mb_substr((string)$p['body'], 0, 400))) ?><?= mb_strlen((string)$p['body']) > 400 ? '…' : '' ?>
                        </div>

                        <div class="activity-card__actions">
                            <span class="action"><span class="action__icon">♥</span> <?= (int)$p['likes_count'] ?></span>
                            <span class="action"><span class="action__icon">💬</span> <?= (int)$p['comments_count'] ?></span>
                            <a class="action" href="<?= e(url('post.php?id=' . $pid)) ?>">
                                <span class="action__icon">📖</span><span>Читать</span>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
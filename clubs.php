<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';

auth_start();
$me = current_user();

$q     = trim((string)($_GET['q'] ?? ''));
$type  = (string)($_GET['type'] ?? '');
$city  = trim((string)($_GET['city'] ?? ''));
$sort  = (string)($_GET['sort'] ?? 'popular');
$page  = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;

$clubs = Club::publicList($q, $type, $city, $sort, $perPage, $offset);
$total = Club::countPublic($q, $type, $city);

$pageTitle = 'Клубы';
$extraCss = [url('assets/css/clubs.css')];
require __DIR__ . '/includes/header.php';
?>

<section class="clubs-page">
    <header class="clubs-page__head">
        <h1 class="clubs-page__title">Клубы</h1>
        <?php if ($me): ?>
            <a href="<?= e(url('club-create.php')) ?>" class="btn btn--primary">+ Создать клуб</a>
        <?php endif; ?>
    </header>

    <form class="clubs-filters" method="get">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Название или описание">
        <select name="type">
            <option value="">Все виды спорта</option>
            <?php foreach (['run'=>'Бег','ride'=>'Велосипед','swim'=>'Плавание','ski'=>'Лыжи','walk'=>'Ходьба','hike'=>'Хайкинг','mixed'=>'Смешанный'] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="city" value="<?= e($city) ?>" placeholder="Город">
        <select name="sort">
            <option value="popular"  <?= $sort === 'popular' ? 'selected' : '' ?>>Популярные</option>
            <option value="new"      <?= $sort === 'new' ? 'selected' : '' ?>>Новые</option>
            <option value="distance" <?= $sort === 'distance' ? 'selected' : '' ?>>По объёму за неделю</option>
            <option value="alpha"    <?= $sort === 'alpha' ? 'selected' : '' ?>>По алфавиту</option>
        </select>
        <button class="btn btn--primary">Найти</button>
    </form>

    <?php if (!$clubs): ?>
        <div class="empty">
            <p>Клубов по заданным фильтрам не найдено.</p>
            <?php if ($me): ?>
                <a class="btn btn--primary" href="<?= e(url('club-create.php')) ?>">Создать первый</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="clubs-grid">
            <?php foreach ($clubs as $c): ?>
                <a class="club-card" href="<?= e(url('club.php?slug=' . urlencode((string)$c['slug']))) ?>">
                    <div class="club-card__cover"
                         style="background-image:url('<?= e($c['cover_url'] ?: '') ?>')"></div>
                    <div class="club-card__avatar">
                        <?php if (!empty($c['avatar_url'])): ?>
                            <img src="<?= e($c['avatar_url']) ?>" alt="">
                        <?php else: ?>
                            <?= e(mb_substr((string)$c['name'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="club-card__body">
                        <h3 class="club-card__name"><?= e($c['name']) ?></h3>
                        <div class="club-card__meta muted">
                            <?= e(mb_substr((string)($c['description'] ?? ''), 0, 100)) ?>
                        </div>
                        <div class="club-card__stats">
                            <span><strong><?= (int)($c['members_active'] ?? 0) ?></strong> участников</span>
                            <?php if ((float)($c['week_distance_m'] ?? 0) > 0): ?>
                                <span><strong><?= number_format((float)$c['week_distance_m']/1000, 0, '.', ' ') ?></strong> км за неделю</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($total > $perPage): ?>
            <nav class="pagination">
                <?php for ($i = 1; $i <= ceil($total / $perPage); $i++): ?>
                    <a class="<?= $i === $page ? 'is-active' : '' ?>"
                       href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
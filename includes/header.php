<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../models/Notification.php';
auth_start();

$me = current_user();
$pageTitle = $pageTitle ?? config('app_name');
$flash = flash();

$unreadCount = 0;
if ($me) {
    try {
        $unreadCount = Notification::unreadCount((int)$me['id']);
    } catch (Throwable $e) {
        $unreadCount = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title><?= e($pageTitle) ?> — <?= e((string)config('app_name')) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <?php foreach (($extraCss ?? []) as $css): ?>
        <link rel="stylesheet" href="<?= e($css) ?>">
    <?php endforeach; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">

<!-- ============ HEADER ============ -->
<header class="site-header" id="site-header">
    <div class="container site-header__inner">

        <!-- Бургер (только мобильные) -->
        <button type="button" class="burger" id="burger" aria-label="Меню" aria-expanded="false">
            <span class="burger__line"></span>
            <span class="burger__line"></span>
            <span class="burger__line"></span>
        </button>

        <!-- Логотип -->
        <a href="<?= e(url('index.php')) ?>" class="logo">
            <span class="logo__mark">P</span>
            <span class="logo__text">Pulse</span>
        </a>

        <!-- Навигация (десктоп) -->
        <nav class="nav" id="desktop-nav">
            <?php if ($me): ?>
                <a href="<?= e(url('feed.php')) ?>" class="nav__link <?= is_current('feed.php') ?>">Лента</a>
                <a href="<?= e(url('calendar.php')) ?>" class="nav__link <?= is_current('calendar.php') ?>">Календарь</a>
                <a href="<?= e(url('routes.php')) ?>" class="nav__link <?= is_current('routes.php') ?>">Маршруты</a>
                <a href="<?= e(url('segments.php')) ?>" class="nav__link <?= is_current('segments.php') ?>">Сегменты</a>
                <a href="<?= e(url('gear.php')) ?>" class="nav__link <?= is_current('gear.php') ?>">Инвентарь</a>
            <?php else: ?>
                <a href="<?= e(url('index.php#features')) ?>" class="nav__link">Возможности</a>
                <a href="<?= e(url('index.php#activities')) ?>" class="nav__link">Активности</a>
            <?php endif; ?>
        </nav>

        <!-- Действия (десктоп + мобильные иконки) -->
        <div class="site-header__actions">
            <?php if ($me): ?>
                <a href="<?= e(url('search.php')) ?>" class="icon-btn" title="Поиск" aria-label="Поиск">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </a>

                <a href="<?= e(url('notifications.php')) ?>" class="icon-btn icon-btn--badge" title="Уведомления" aria-label="Уведомления">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    <?php if ($unreadCount > 0): ?>
                        <span class="badge"><?= $unreadCount > 99 ? '99+' : (int)$unreadCount ?></span>
                    <?php endif; ?>
                </a>

                <a href="<?= e(url('activity-upload.php')) ?>"
                   class="btn btn--primary btn--sm site-header__upload"
                   title="Загрузить активность">
                    <span class="site-header__upload-icon">+</span>
                    <span class="site-header__upload-text">Активность</span>
                </a>

                <!-- Аватар с выпадающим меню -->
                <div class="user-menu" id="user-menu">
                    <button type="button" class="user-menu__toggle" id="user-menu-toggle" aria-haspopup="true" aria-expanded="false">
                        <span class="avatar avatar--sm">
                            <?php if (!empty($me['avatar_url'])): ?>
                                <img src="<?= e($me['avatar_url']) ?>" alt="">
                            <?php else: ?>
                                <?= e(mb_substr((string)$me['display_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </span>
                    </button>

                    <div class="user-menu__dropdown" id="user-menu-dropdown" hidden>
                        <div class="user-menu__head">
                            <div class="user-menu__name"><?= e($me['display_name']) ?></div>
                            <div class="user-menu__meta">@<?= e($me['username']) ?></div>
                        </div>
                        <a href="<?= e(url('profile.php?u=' . urlencode((string)$me['username']))) ?>" class="user-menu__item">
                            <span class="user-menu__icon">👤</span> Мой профиль
                        </a>
                        <a href="<?= e(url('profile-edit.php')) ?>" class="user-menu__item">
                            <span class="user-menu__icon">⚙️</span> Настройки
                        </a>
                        <a href="<?= e(url('gear.php')) ?>" class="user-menu__item">
                            <span class="user-menu__icon">🎒</span> Инвентарь
                        </a>
                        
                        <a href="<?= e(url('my-posts.php')) ?>" class="user-menu__item">
                            <span class="user-menu__icon">📖</span> Мои посты
                        </a>
                        
                        <div class="user-menu__divider"></div>
                        <a href="<?= e(url('logout.php')) ?>" class="user-menu__item user-menu__item--danger">
                            <span class="user-menu__icon">🚪</span> Выйти
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= e(url('login.php')) ?>" class="btn btn--ghost btn--sm">Войти</a>
                <a href="<?= e(url('register.php')) ?>" class="btn btn--primary btn--sm">Регистрация</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- ============ МОБИЛЬНОЕ МЕНЮ ============ -->
<?php if ($me): ?>
    <div class="mobile-menu" id="mobile-menu" hidden>
        <div class="mobile-menu__backdrop" id="mobile-menu-backdrop"></div>
        <nav class="mobile-menu__panel">
            <div class="mobile-menu__head">
                <span class="avatar">
                    <?php if (!empty($me['avatar_url'])): ?>
                        <img src="<?= e($me['avatar_url']) ?>" alt="">
                    <?php else: ?>
                        <?= e(mb_substr((string)$me['display_name'], 0, 1)) ?>
                    <?php endif; ?>
                </span>
                <div>
                    <div class="mobile-menu__name"><?= e($me['display_name']) ?></div>
                    <div class="mobile-menu__meta">@<?= e($me['username']) ?></div>
                </div>
            </div>

            <div class="mobile-menu__list">
                <a href="<?= e(url('feed.php')) ?>" class="mobile-menu__item">
                    <span class="mobile-menu__icon">📰</span> Лента
                </a>
                <a href="<?= e(url('profile.php?u=' . urlencode((string)$me['username']))) ?>" class="mobile-menu__item">
                    <span class="mobile-menu__icon">👤</span> Мой профиль
                </a>
                <a href="<?= e(url('activity-upload.php')) ?>" class="mobile-menu__item">
                    <span class="mobile-menu__icon">📂</span> Загрузить активность
                </a>
                <a href="<?= e(url('routes.php')) ?>" class="mobile-menu__item">
                    <span class="mobile-menu__icon">🗺️</span> Маршруты
                </a>
                <a href="<?= e(url('segments.php')) ?>" class="mobile-menu__item">
                    <span class="mobile-menu__icon">⚡</span> Сегменты
                </a>
                
                <a href="<?= e(url('calendar.php')) ?>" class="mobile-menu__item">
    <span class="mobile-menu__icon">📅</span> Календарь
</a>
                
                <a href="<?= e(url('gear.php')) ?>" class="mobile-menu__item">
                    <span class="mobile-menu__icon">🎒</span> Инвентарь
                </a>
                <a href="<?= e(url('search.php')) ?>" class="mobile-menu__item">
                    <span class="mobile-menu__icon">🔍</span> Поиск
                </a>
                <a href="<?= e(url('notifications.php')) ?>" class="mobile-menu__item">
                    <span class="mobile-menu__icon">🔔</span> Уведомления
                    <?php if ($unreadCount > 0): ?>
                        <span class="mobile-menu__badge"><?= $unreadCount > 99 ? '99+' : (int)$unreadCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= e(url('profile-edit.php')) ?>" class="mobile-menu__item">
                    <span class="mobile-menu__icon">⚙️</span> Настройки
                </a>
            </div>

            <div class="mobile-menu__footer">
                <a href="<?= e(url('logout.php')) ?>" class="mobile-menu__logout">Выйти</a>
            </div>
        </nav>
    </div>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="container" style="margin-top:18px">
        <div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    </div>
<?php endif; ?>

<main>



<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Route.php';
require_once __DIR__ . '/services/GpxParser.php';

auth_start();
$me = require_login();

$routeId = (int)($_GET['id'] ?? 0);
if ($routeId <= 0) {
    http_response_code(404);
    exit('Маршрут не найден');
}

$route = Route::findById($routeId);
if (!$route) {
    http_response_code(404);
    exit('Маршрут не найден');
}

if ((int)$route['user_id'] !== (int)$me['id']) {
    http_response_code(403);
    exit('Редактировать можно только свои маршруты');
}

$errors = [];
$old = [
    'name'        => (string)$route['name'],
    'description' => (string)($route['description'] ?? ''),
    'type'        => (string)$route['type'],
    'is_public'   => (int)$route['is_public'],
];

// ============================================================
// POST: обновление
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['name']        = trim((string)($_POST['name'] ?? ''));
    $old['description'] = trim((string)($_POST['description'] ?? ''));
    $old['type']        = (string)($_POST['type'] ?? 'run');
    $old['is_public']   = isset($_POST['is_public']) ? 1 : 0;

    if ($old['name'] === '') {
        $errors['name'] = 'Введите название';
    } elseif (mb_strlen($old['name']) > 190) {
        $errors['name'] = 'Максимум 190 символов';
    }

    $allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
    if (!in_array($old['type'], $allowedTypes, true)) $old['type'] = 'run';

    $trackRaw = (string)($_POST['track_json'] ?? '');
    $track = json_decode($trackRaw, true);

    if (!is_array($track) || count($track) < 2) {
        $errors['track'] = 'Проложите маршрут на карте (минимум 2 точки)';
    } else {
        $clean = [];
        foreach ($track as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            $lat = (float)$p['lat'];
            $lng = (float)$p['lng'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            $row = ['lat' => $lat, 'lng' => $lng];
            if (isset($p['ele'])) $row['ele'] = (float)$p['ele'];
            $clean[] = $row;
        }
        if (count($clean) < 2) {
            $errors['track'] = 'Недостаточно корректных точек';
        } else {
            $track = $clean;
        }
    }

    $waypointsRaw = (string)($_POST['waypoints_json'] ?? '[]');
    $waypoints = json_decode($waypointsRaw, true);
    if (!is_array($waypoints)) $waypoints = [];
    $waypoints = array_slice($waypoints, 0, 100);

    $cleanWaypoints = [];
    foreach ($waypoints as $w) {
        if (!isset($w['lat'], $w['lng'])) continue;
        $lat = (float)$w['lat'];
        $lng = (float)$w['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $cleanWaypoints[] = [
            'lat'  => $lat,
            'lng'  => $lng,
            'name' => mb_substr(trim((string)($w['name'] ?? '')), 0, 120),
            'note' => mb_substr(trim((string)($w['note'] ?? '')), 0, 500),
        ];
    }

    if (!$errors) {
        try {
            $distance = 0.0;
            for ($i = 1; $i < count($track); $i++) {
                $distance += GpxParser::haversinePublic(
                    $track[$i-1]['lat'], $track[$i-1]['lng'],
                    $track[$i]['lat'],   $track[$i]['lng']
                );
            }

            $routeData = [
                'name'        => $old['name'],
                'description' => $old['description'] !== '' ? $old['description'] : null,
                'type'        => $old['type'],
                'distance_m'  => $distance,
                'track_json'  => json_encode($track, JSON_UNESCAPED_UNICODE),
                'is_public'   => $old['is_public'],
            ];

            if (Route::supportsWaypoints()) {
                $routeData['waypoints_json'] = json_encode($cleanWaypoints, JSON_UNESCAPED_UNICODE);
            } elseif (!empty($cleanWaypoints)) {
                // Fallback: складываем точки в описание
                $notes = "\n\nТочки маршрута:\n";
                foreach ($cleanWaypoints as $i => $w) {
                    $notes .= ($i + 1) . '. ';
                    if ($w['name'] !== '') $notes .= $w['name'] . ' — ';
                    if ($w['note'] !== '') $notes .= $w['note'] . ' ';
                    $notes .= '(' . number_format($w['lat'], 5) . ', ' . number_format($w['lng'], 5) . ")\n";
                }
                $routeData['description'] = ($routeData['description'] ?? '') . $notes;
            }

            Route::update($routeId, (int)$me['id'], $routeData);

            flash('Маршрут «' . $old['name'] . '» обновлён', 'success');
            redirect(url('route.php?id=' . $routeId));
        } catch (Throwable $e) {
            $errors['_general'] = 'Не удалось сохранить: ' . $e->getMessage();
        }
    }
}

// ============================================================
// ПАРСИНГ ДАННЫХ ДЛЯ ПРЕДЗАПОЛНЕНИЯ РЕДАКТОРА
// ============================================================
$track     = Route::parseTrack($route['track_json'] ?? null);
$waypoints = Route::parseWaypoints($route['waypoints_json'] ?? null);

// Сливаем трек + waypoints в единый список точек для редактора:
// первые точки — начало/конец трека (для snap), waypoints — с именами
$editorPoints = [];

// Начало и конец трека как обычные точки
if (count($track) >= 2) {
    $editorPoints[] = [
        'lat'  => $track[0]['lat'],
        'lng'  => $track[0]['lng'],
        'name' => '',
        'note' => '',
    ];
    $editorPoints[] = [
        'lat'  => $track[count($track) - 1]['lat'],
        'lng'  => $track[count($track) - 1]['lng'],
        'name' => '',
        'note' => '',
    ];
}

// Waypoints (с именами) вставляем между
foreach ($waypoints as $w) {
    $editorPoints[] = [
        'lat'  => $w['lat'],
        'lng'  => $w['lng'],
        'name' => $w['name'],
        'note' => $w['note'],
    ];
}

$pageTitle = 'Редактировать маршрут: ' . $route['name'];

$extraCss = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    url('assets/css/route-editor.css'),
];

$extraJs = [];

$bodyClass = 'route-create-page';

require __DIR__ . '/includes/header.php';
?>

<style>
body.route-create-page {
    display: flex;
    flex-direction: column;
    height: 100vh;
    overflow: hidden;
    margin: 0;
}
body.route-create-page .site-header {
    display: block !important;
    position: relative !important;
    top: auto !important;
    flex-shrink: 0;
    z-index: 100;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    background: #fff !important;
    box-shadow: none !important;
    border-bottom: 1px solid #e3e7ef;
}
body.route-create-page .site-footer { display: none !important; }
body.route-create-page main {
    position: relative;
    flex: 1;
    min-height: 0;
    padding: 0 !important;
    margin: 0 !important;
    overflow: hidden;
}
body.route-create-page .route-map-full {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    width: 100%;
    height: 100%;
    z-index: 1;
}
</style>

<!-- КАРТА -->
<div id="route-map" class="route-map-full"></div>

<!-- ВЕРХНИЙ ТУЛБАР -->
<div class="route-topbar">
    <div class="route-topbar__group">
        <button type="button" class="route-icon-btn" id="btn-undo" title="Отменить (Ctrl+Z)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                <path d="M3 7v6h6"/>
                <path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6.36 2.64L3 13"/>
            </svg>
        </button>
        <button type="button" class="route-icon-btn" id="btn-redo" title="Повторить (Ctrl+Y)" disabled>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                <path d="M21 7v6h-6"/>
                <path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6.36 2.64L21 13"/>
            </svg>
        </button>
        <button type="button" class="route-icon-btn" id="btn-reverse" title="Развернуть маршрут">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                <path d="M17 1l4 4-4 4"/>
                <path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                <path d="M7 23l-4-4 4-4"/>
                <path d="M21 13v2a4 4 0 0 1-4 4H3"/>
            </svg>
        </button>
        <button type="button" class="route-icon-btn" id="btn-fit" title="Показать весь маршрут">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                <path d="M3 7V5a2 2 0 0 1 2-2h2"/>
                <path d="M17 3h2a2 2 0 0 1 2 2v2"/>
                <path d="M21 17v2a2 2 0 0 1-2 2h-2"/>
                <path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
            </svg>
        </button>
        <button type="button" class="route-icon-btn route-icon-btn--danger" id="btn-clear-all" title="Очистить карту">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                <path d="M10 11v6"/>
                <path d="M14 11v6"/>
                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
        </button>
    </div>

    <div class="route-topbar__center">
        <button type="button" class="route-pill" id="btn-add-custom">
            <span>📍</span> Добавить точку
        </button>
        <button type="button" class="route-pill" id="btn-map-content">
            <span>🗺</span> Слои карты
        </button>
        <button type="button" class="route-pill" id="btn-preferences">
            <span>⚙</span> Настройки
        </button>
    </div>

    <div class="route-topbar__right">
        <button type="button" class="route-btn route-btn--ghost" id="btn-import-gpx" title="Импорт трека из GPX">
            📂 Импорт GPX
        </button>
        <button type="button" class="route-btn route-btn--ghost" id="btn-download-gpx" disabled title="Скачать текущий маршрут в GPX">
            ⬇ Скачать
        </button>
        <button type="button" class="route-btn route-btn--primary" id="btn-save" disabled>
            💾 Сохранить
        </button>
    </div>
</div>

<!-- Скрытый input для импорта GPX -->
<input type="file" id="gpx-import-input" accept=".gpx,application/gpx+xml" hidden>

<!-- МЕНЮ СЛОЁВ -->
<div class="route-layers" id="layers-menu" hidden>
    <div class="route-layers__title">Слои карты</div>
    <label class="route-layers__item">
        <input type="radio" name="tile-layer" value="osm" checked>
        <span>OpenStreetMap</span>
    </label>
    <label class="route-layers__item">
        <input type="radio" name="tile-layer" value="carto">
        <span>Светлая (CARTO)</span>
    </label>
    <label class="route-layers__item">
        <input type="radio" name="tile-layer" value="humanitarian">
        <span>Гуманитарная OSM</span>
    </label>
    <label class="route-layers__item">
        <input type="radio" name="tile-layer" value="topo">
        <span>Топографическая</span>
    </label>
    <label class="route-layers__item">
        <input type="radio" name="tile-layer" value="satellite">
        <span>Спутник</span>
    </label>
</div>

<!-- ПАНЕЛЬ НАСТРОЕК -->
<div class="route-prefs" id="prefs-menu" hidden>
    <div class="route-layers__title">Настройки</div>
    <label class="route-layers__item">
        <input type="checkbox" id="pref-autosave">
        <span>Автосохранение черновика</span>
    </label>
    <label class="route-layers__item">
        <input type="checkbox" id="pref-show-elev" checked>
        <span>Показывать профиль высот</span>
    </label>
</div>

<!-- ЛЕВАЯ ПАНЕЛЬ -->
<aside class="route-sidebar" id="route-sidebar">
    <div class="route-sidebar__head">
        <h1 class="route-sidebar__title">Редактирование</h1>
    </div>

    <div class="route-waypoints" id="route-waypoints">
        <div class="route-waypoints__empty">Нажмите на карту, чтобы начать</div>
    </div>

    <div class="route-search">
        <div class="route-search__row">
            <span class="route-search__icon">🔍</span>
            <input type="text" id="search-input" placeholder="Найти место" autocomplete="off">
        </div>
        <div class="route-search__results" id="search-results" hidden></div>
    </div>

    <div class="route-suggestions">
        <div class="route-suggestions__title">Быстрые действия</div>

        <button type="button" class="route-suggestion" id="btn-suggestion-location">
            <span class="route-suggestion__icon route-suggestion__icon--geo">📍</span>
            <span class="route-suggestion__label">Моё местоположение</span>
        </button>
        <button type="button" class="route-suggestion" id="btn-suggestion-saved">
            <span class="route-suggestion__icon route-suggestion__icon--saved">🔖</span>
            <span class="route-suggestion__label">Сохранённые места</span>
            <span class="route-suggestion__meta" id="saved-count">0</span>
        </button>
    </div>

    <div class="route-sidebar__stats">
        <div class="route-stat">
            <div class="route-stat__value" id="info-length">0 м</div>
            <div class="route-stat__label">Длина</div>
        </div>
        <div class="route-stat">
            <div class="route-stat__value" id="info-points">0</div>
            <div class="route-stat__label">Точек</div>
        </div>
        <div class="route-stat">
            <div class="route-stat__value" id="info-elev">—</div>
            <div class="route-stat__label">Набор</div>
        </div>
    </div>

    <div class="route-sidebar__status" id="info-status">Нажмите на карту, чтобы добавить точку</div>
</aside>

<!-- ПРОФИЛЬ ВЫСОТ -->
<div class="route-elevation" id="elevation-panel" hidden>
    <div class="route-elevation__head">
        <span class="route-elevation__title">Профиль высот</span>
        <div class="route-elevation__stats" id="elev-stats"></div>
    </div>
    <div class="route-elevation__chart">
        <canvas id="elev-chart"></canvas>
    </div>
</div>

<!-- МОДАЛКА МЕНЮ ТОЧКИ -->
<div class="route-modal" id="point-menu-modal" hidden>
    <div class="route-modal__box">
        <h3 class="route-modal__title" id="point-menu-title">Точка</h3>
        <div class="route-modal__coords" id="point-menu-coords"></div>

        <div class="route-modal__actions route-modal__actions--column">
            <button type="button" class="route-btn route-btn--primary" id="point-edit">✏️ Редактировать</button>
            <button type="button" class="route-btn route-btn--danger" id="point-delete">🗑 Удалить точку</button>
            <button type="button" class="route-btn route-btn--ghost" id="point-close">Отмена</button>
        </div>
    </div>
</div>

<!-- МОДАЛКА РЕДАКТИРОВАНИЯ ТОЧКИ -->
<div class="route-modal" id="point-edit-modal" hidden>
    <div class="route-modal__box">
        <h3 class="route-modal__title" id="point-edit-title">Редактировать точку</h3>

        <div class="field">
            <label for="point-name">Название</label>
            <input type="text" id="point-name" maxlength="120" placeholder="Например: Старт у парка">
        </div>
        <div class="field">
            <label for="point-note">Описание</label>
            <textarea id="point-note" rows="3" maxlength="500" placeholder="Что здесь важного?"></textarea>
        </div>

        <div class="route-modal__actions">
            <button type="button" class="route-btn route-btn--ghost" id="point-edit-cancel">Отмена</button>
            <button type="button" class="route-btn route-btn--primary" id="point-edit-save">Сохранить</button>
        </div>
    </div>
</div>

<!-- МОДАЛКА ДОБАВЛЕНИЯ ПРОИЗВОЛЬНОЙ ТОЧКИ -->
<div class="route-modal" id="custom-point-modal" hidden>
    <div class="route-modal__box">
        <h3 class="route-modal__title">Добавить точку</h3>

        <div class="form-row">
            <div class="field">
                <label for="cp-lat">Широта</label>
                <input type="number" id="cp-lat" step="0.000001" placeholder="55.7558">
            </div>
            <div class="field">
                <label for="cp-lng">Долгота</label>
                <input type="number" id="cp-lng" step="0.000001" placeholder="37.6173">
            </div>
        </div>

        <div class="route-modal__actions route-modal__actions--between">
            <button type="button" class="route-btn route-btn--ghost" id="cp-use-center">Взять центр карты</button>
        </div>

        <div class="field">
            <label for="cp-name">Название</label>
            <input type="text" id="cp-name" maxlength="120" placeholder="Например: Поворот налево">
        </div>
        <div class="field">
            <label for="cp-note">Описание</label>
            <textarea id="cp-note" rows="3" maxlength="500" placeholder="Дополнительная информация"></textarea>
        </div>

        <div class="route-modal__actions">
            <button type="button" class="route-btn route-btn--ghost" id="cp-cancel">Отмена</button>
            <button type="button" class="route-btn route-btn--primary" id="cp-save">Добавить</button>
        </div>
    </div>
</div>

<!-- МОДАЛКА СОХРАНЁННЫХ МЕСТ -->
<div class="route-modal" id="saved-places-modal" hidden>
    <div class="route-modal__box route-modal__box--wide">
        <h3 class="route-modal__title">Сохранённые места</h3>

        <div id="saved-places-list" class="saved-places-list">
            <div class="saved-places-empty">Пока нет сохранённых мест</div>
        </div>

        <form id="saved-place-form" class="saved-place-form">
            <input type="hidden" id="sp-lat">
            <input type="hidden" id="sp-lng">
            <div class="field">
                <label for="sp-name">Название</label>
                <input type="text" id="sp-name" maxlength="120" placeholder="Например: Дом, Работа, Любимый парк">
            </div>
            <div class="route-modal__actions">
                <button type="button" class="route-btn route-btn--ghost" id="sp-cancel">Закрыть</button>
                <button type="button" class="route-btn route-btn--ghost" id="sp-use-current">Использовать центр карты</button>
                <button type="submit" class="route-btn route-btn--primary">Добавить</button>
            </div>
        </form>
    </div>
</div>

<!-- МОДАЛКА СОХРАНЕНИЯ -->
<div class="route-modal" id="save-modal" hidden>
    <div class="route-modal__box route-modal__box--wide">
        <h3 class="route-modal__title">Сохранить изменения</h3>

        <form method="post" id="route-form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="track_json" id="track_json" value="">
            <input type="hidden" name="waypoints_json" id="waypoints_json" value="[]">

            <?php if (!empty($errors['_general'])): ?>
                <div class="alert alert--error"><?= e($errors['_general']) ?></div>
            <?php endif; ?>

            <div class="field">
                <label for="route-name">Название маршрута</label>
                <input type="text" id="route-name" name="name" required maxlength="190" value="<?= e($old['name']) ?>">
                <?php if (!empty($errors['name'])): ?>
                    <span class="field__error"><?= e($errors['name']) ?></span>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="route-description">Описание <span class="muted">(необязательно)</span></label>
                <textarea id="route-description" name="description" rows="3" maxlength="2000"><?= e($old['description']) ?></textarea>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="route-type">Тип активности</label>
                    <select id="route-type" name="type">
                        <option value="run"   <?= $old['type'] === 'run'   ? 'selected' : '' ?>>🏃 Бег</option>
                        <option value="ride"  <?= $old['type'] === 'ride'  ? 'selected' : '' ?>>🚴 Велосипед</option>
                        <option value="walk"  <?= $old['type'] === 'walk'  ? 'selected' : '' ?>>🚶 Ходьба</option>
                        <option value="hike"  <?= $old['type'] === 'hike'  ? 'selected' : '' ?>>🥾 Хайкинг</option>
                        <option value="ski"   <?= $old['type'] === 'ski'   ? 'selected' : '' ?>>⛷️ Лыжи</option>
                        <option value="swim"  <?= $old['type'] === 'swim'  ? 'selected' : '' ?>>🏊 Плавание</option>
                        <option value="other" <?= $old['type'] === 'other' ? 'selected' : '' ?>>📦 Другое</option>
                    </select>
                </div>
                <div class="field field--checkbox" style="align-self:end">
                    <label>
                        <input type="checkbox" name="is_public" <?= $old['is_public'] ? 'checked' : '' ?>>
                        Публичный маршрут
                    </label>
                </div>
            </div>

            <?php if (!empty($errors['track'])): ?>
                <div class="alert alert--error"><?= e($errors['track']) ?></div>
            <?php endif; ?>

            <div class="route-modal__actions">
                <button type="button" class="route-btn route-btn--ghost" id="save-cancel">Отмена</button>
                <button type="submit" class="route-btn route-btn--primary">💾 Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- СКРИПТЫ -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
window.__ROUTE_EDITOR__ = {
    osrmBase: 'https://router.project-osrm.org',
    nominatim: 'https://nominatim.openstreetmap.org',
    savedPlacesApi: <?= json_encode(url('api/saved-places.php')) ?>,
    elevationApi: <?= json_encode(url('api/elevation.php')) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    defaultCenter: [55.751244, 37.618423],
    mode: 'edit',
    routeId: <?= (int)$routeId ?>,
    initialData: {
        points:    <?= json_encode($editorPoints, JSON_UNESCAPED_UNICODE) ?>,
        track:     <?= json_encode($track, JSON_UNESCAPED_UNICODE) ?>,
        waypoints: <?= json_encode($waypoints, JSON_UNESCAPED_UNICODE) ?>
    }
};
</script>
<script src="<?= e(url('assets/js/route-editor.js')) ?>"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
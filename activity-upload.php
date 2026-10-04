<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Gear.php';

auth_start();
$me = require_login();

$gearList = Gear::allForUser((int)$me['id']);

$pageTitle = 'Загрузить активности';

$extraJs = [
    'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js',
    url('assets/js/upload-activity.js') . '?v=' . @filemtime(__DIR__ . '/assets/js/upload-activity.js'),
];

$inlineJs = '
window.__UPLOAD_API_ACTIVITY__  = ' . json_encode(url('api/upload-activity.php')) . ';
window.__UPLOAD_API_PHOTO__     = ' . json_encode(url('api/upload-activity-photo.php')) . ';
window.__UPLOAD_CSRF__          = ' . json_encode(csrf_token()) . ';
window.__UPLOAD_ACTIVITY_URL__  = ' . json_encode(url('activity.php?id=')) . ';
window.__UPLOAD_FEED_URL__      = ' . json_encode(url('feed.php')) . ';
';

require __DIR__ . '/includes/header.php';
?>



<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Загрузить активности</h1>
        

<p class="form-card__subtitle">
    Можно выбрать сразу несколько файлов. Поддерживаются
    <strong>GPX</strong>, <strong>TCX</strong> и <strong>FIT</strong>
    (до 10 файлов, каждый не более 25 МБ).
    Или <a href="<?= e(url('activity-create.php')) ?>">добавьте тренировку вручную</a>.
</p>

        <div id="upload-results" hidden></div>

<form id="upload-form"
      data-api-activity="<?= e(url('api/upload-activity.php')) ?>"
      data-api-photo="<?= e(url('api/upload-activity-photo.php')) ?>"
      data-csrf="<?= e(csrf_token()) ?>"
      data-activity-url="<?= e(url('activity.php?id=')) ?>"
      data-feed-url="<?= e(url('feed.php')) ?>"
      method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label for="files">Файлы активностей</label>
                <input type="file" id="files" name="files[]"
                       accept=".gpx,.tcx,.fit"
                       multiple required>
                <span class="field__hint">
                    Можно выбрать несколько файлов сразу (Ctrl/Cmd+клик).
                    Если оставить название пустым — оно будет взято из имени файла.
                </span>
            </div>

            <div class="field">
                <label for="title">Название <span class="muted">(необязательно)</span></label>
                <input type="text" id="title" name="title" value="" maxlength="150">
                <span class="field__hint">
                    Если загружаете несколько файлов, к каждому добавится имя файла.
                </span>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="type">Тип активности</label>
                    <select id="type" name="type">
                        <option value="run">🏃 Бег</option>
                        <option value="ride">🚴 Велосипед</option>
                        <option value="swim">🏊 Плавание</option>
                        <option value="ski">⛷️ Лыжи</option>
                        <option value="walk">🚶 Ходьба</option>
                        <option value="hike">🥾 Хайкинг</option>
                        <option value="other">📦 Другое</option>
                    </select>
                </div>

                <div class="field">
                    <label for="visibility">Кто видит</label>
                    <select id="visibility" name="visibility">
                        <option value="public">Публичная</option>
                        <option value="followers">Только подписчики</option>
                        <option value="private">Приватная</option>
                    </select>
                </div>
            </div>

            <?php if ($gearList): ?>
                <div class="field">
                    <label for="gear_id">Инвентарь <span class="muted">(необязательно)</span></label>
                    <select id="gear_id" name="gear_id">
                        <option value="">— не привязывать —</option>
                        <?php foreach ($gearList as $g): ?>
                            <option value="<?= (int)$g['id'] ?>">
                                <?= e($g['name']) ?><?php if ($g['brand']): ?> · <?= e($g['brand']) ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="photos">Фотографии <span class="muted">(до 10 штук, необязательно)</span></label>
                <input type="file" id="photos" name="photos[]" accept="image/*,.heic,.heif" multiple>
                <span class="field__hint">JPG, PNG, WEBP, HEIC · до 10 МБ каждое · максимум 10 фото</span>
            </div>

            

            <div id="upload-progress" class="upload-progress" hidden>
                <div class="upload-progress__bar-wrap">
                    <div id="upload-progress-bar" class="upload-progress__bar"></div>
                </div>
                <div id="upload-progress-text" class="upload-progress__text">Подготовка…</div>
                <div id="upload-progress-hint" class="upload-progress__hint"></div>
            </div>

            <button type="submit" id="upload-submit" class="btn btn--primary btn--large" style="width:100%">
                Загрузить
            </button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
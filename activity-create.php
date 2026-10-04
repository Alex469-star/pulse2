<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Gear.php';

auth_start();
$me = require_login();

$gearList = Gear::allForUser((int)$me['id']);

$pageTitle = 'Добавить тренировку';

$extraJs = [
    'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js',
    url('assets/js/manual-activity.js') . '?v=' . @filemtime(__DIR__ . '/assets/js/manual-activity.js'),
];

require __DIR__ . '/includes/header.php';
?>

<section class="form-page form-page--wide">
    <div class="form-card">
        <h1 class="form-card__title">Добавить тренировку вручную</h1>
        <p class="form-card__subtitle">
            Если у вас нет файла GPX/TCX/FIT — заполните данные вручную.
            Обязательны только название, тип и дата. Остальные поля — по желанию.
        </p>

        <div id="manual-results" hidden></div>

        <form id="manual-form"
      data-api="<?= e(url('api/create-manual-activity.php')) ?>"
      data-api-photo="<?= e(url('api/upload-activity-photo.php')) ?>"
      data-csrf="<?= e(csrf_token()) ?>"
      data-activity-url="<?= e(url('activity.php?id=')) ?>"
      data-feed-url="<?= e(url('feed.php')) ?>"
      method="post" novalidate enctype="multipart/form-data">

                        <div class="field">
                <label for="photos">Фотографии <span class="muted">(до 10 штук, необязательно)</span></label>
                <input type="file" id="photos" name="photos[]" accept="image/*,.heic,.heif" multiple>
                <span class="field__hint">JPG, PNG, WEBP, HEIC · до 10 МБ каждое · максимум 10 фото</span>
            </div>
            
            <div class="field">
                <label for="title">Название <span class="muted">(обязательно)</span></label>
                <input type="text" id="title" name="title" required maxlength="190"
                       placeholder="Например: Утренняя пробежка в парке">
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

            <div class="form-row">
                <div class="field">
                    <label for="started_date">Дата <span class="muted">(обязательно)</span></label>
                    <input type="date" id="started_date" name="started_date" required>
                </div>

                <div class="field">
                    <label for="started_time">Время начала</label>
                    <input type="time" id="started_time" name="started_time" value="08:00">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="distance_km">Дистанция (км)</label>
                    <input type="number" id="distance_km" name="distance_km"
                           step="0.01" min="0" max="1000" placeholder="10.5">
                </div>

                <div class="field">
                    <label for="duration_hms">Длительность (ЧЧ:ММ:СС)</label>
                    <input type="text" id="duration_hms" name="duration_hms"
                           placeholder="0:52:18" pattern="[0-9:]+">
                    <span class="field__hint">Например: 0:52:18 или 52:18</span>
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="elevation_gain_m">Набор высоты (м)</label>
                    <input type="number" id="elevation_gain_m" name="elevation_gain_m"
                           step="1" min="0" max="10000" placeholder="120">
                </div>

                <div class="field">
                    <label for="calories">Калории (ккал)</label>
                    <input type="number" id="calories" name="calories"
                           step="1" min="0" max="10000" placeholder="650">
                </div>
            </div>

            <h3 class="manual-section-title">Данные с датчиков <span class="muted">(необязательно)</span></h3>

            <div class="form-row">
                <div class="field">
                    <label for="avg_hr">Средний пульс (уд/мин)</label>
                    <input type="number" id="avg_hr" name="avg_hr"
                           step="1" min="0" max="250" placeholder="142">
                </div>

                <div class="field">
                    <label for="max_hr">Максимальный пульс (уд/мин)</label>
                    <input type="number" id="max_hr" name="max_hr"
                           step="1" min="0" max="250" placeholder="178">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="avg_cadence">Средний каденс (об/мин)</label>
                    <input type="number" id="avg_cadence" name="avg_cadence"
                           step="1" min="0" max="300" placeholder="85">
                </div>

                <div class="field">
                    <label for="avg_power_w">Средняя мощность (Вт)</label>
                    <input type="number" id="avg_power_w" name="avg_power_w"
                           step="1" min="0" max="2500" placeholder="210">
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
                <label for="description">Заметки <span class="muted">(необязательно)</span></label>
                <textarea id="description" name="description" rows="4" maxlength="2000"
                          placeholder="Как прошла тренировка, самочувствие, погода..."></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" id="manual-submit" class="btn btn--primary btn--large">
                    Сохранить тренировку
                </button>
                <a href="<?= e(url('feed.php')) ?>" class="btn btn--ghost btn--large">Отмена</a>
            </div>
            
                        <div id="manual-upload-progress" class="upload-progress" hidden>
                <div class="upload-progress__bar-wrap">
                    <div id="manual-upload-progress-bar" class="upload-progress__bar"></div>
                </div>
                <div id="manual-upload-progress-text" class="upload-progress__text">Подготовка…</div>
                <div id="manual-upload-progress-hint" class="upload-progress__hint"></div>
            </div>
            
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/models/Gear.php';

auth_start();
$me = require_login();

$activityId = (int)($_GET['id'] ?? 0);
if ($activityId <= 0) {
    http_response_code(404);
    exit('Активность не найдена');
}

$activity = Activity::findById($activityId);
if (!$activity) {
    http_response_code(404);
    exit('Активность не найдена');
}

if ((int)$activity['user_id'] !== (int)$me['id']) {
    http_response_code(403);
    exit('Редактировать можно только свои активности');
}

$gearList = Gear::allForUser((int)$me['id']);
$editPhotos = Activity::photos($activityId);

$pageTitle = 'Редактировать активность';

$extraJs = [
    'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js',
    url('assets/js/edit-activity.js') . '?v=' . @filemtime(__DIR__ . '/assets/js/edit-activity.js'),
];

require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Редактировать активность</h1>
        <p class="form-card__subtitle">
            Изменить название, описание, тип, видимость, привязку к инвентарю и фотографии.
        </p>

        <div id="edit-results" hidden></div>

        <form id="activity-edit-form"
              data-api-update="<?= e(url('api/update-activity.php')) ?>"
              data-api-add-photo="<?= e(url('api/add-activity-photo.php')) ?>"
              data-api-del-photo="<?= e(url('api/activity-photo-delete.php')) ?>"
              data-activity-id="<?= (int)$activityId ?>"
              data-activity-url="<?= e(url('activity.php?id=')) ?>"
              data-csrf="<?= e(csrf_token()) ?>"
              method="post" enctype="multipart/form-data" novalidate>

            <div class="field">
                <label for="title">Название</label>
                <input type="text" id="title" name="title"
                       value="<?= e((string)$activity['title']) ?>"
                       required maxlength="190">
            </div>

            <div class="field">
                <label for="description">Описание</label>
                <textarea id="description" name="description" rows="3" maxlength="2000"><?= e((string)($activity['description'] ?? '')) ?></textarea>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="type">Тип</label>
                    <select id="type" name="type">
                        <?php
                        $types = [
                            'run' => '🏃 Бег', 'ride' => '🚴 Велосипед', 'swim' => '🏊 Плавание',
                            'ski' => '⛷️ Лыжи', 'walk' => '🚶 Ходьба', 'hike' => '🥾 Хайкинг',
                            'other' => '📦 Другое',
                        ];
                        foreach ($types as $val => $label):
                            $sel = ((string)$activity['type'] === $val) ? 'selected' : '';
                        ?>
                            <option value="<?= e($val) ?>" <?= $sel ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="visibility">Кто видит</label>
                    <select id="visibility" name="visibility">
                        <?php
                        $vis = [
                            'public' => 'Публичная',
                            'followers' => 'Только подписчики',
                            'private' => 'Приватная',
                        ];
                        foreach ($vis as $val => $label):
                            $sel = ((string)$activity['visibility'] === $val) ? 'selected' : '';
                        ?>
                            <option value="<?= e($val) ?>" <?= $sel ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if ($gearList): ?>
                <div class="field">
                    <label for="gear_id">Инвентарь</label>
                    <select id="gear_id" name="gear_id">
                        <option value="">— не привязывать —</option>
                        <?php foreach ($gearList as $g): ?>
                            <?php $sel = ((string)($activity['gear_id'] ?? '') === (string)$g['id']) ? 'selected' : ''; ?>
                            <option value="<?= (int)$g['id'] ?>" <?= $sel ?>>
                                <?= e($g['name']) ?><?php if (!empty($g['brand'])): ?> · <?= e($g['brand']) ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($editPhotos): ?>
                <div class="post-existing-photos">
                    <label>Фотографии активности</label>
                    <div class="post-photos-grid">
                        <?php foreach ($editPhotos as $ph): ?>
                            <div class="post-photo-item">
                                <img src="<?= e($ph['url']) ?>" alt="">
                                <button type="button"
                                        class="post-photo-item__delete js-delete-activity-photo"
                                        data-photo-id="<?= (int)$ph['id'] ?>"
                                        title="Удалить фото">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <span class="field__hint">Нажмите × на фото, чтобы удалить. Сохраняется сразу.</span>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="photos"><?= $editPhotos ? 'Добавить ещё фото' : 'Фото' ?> <span class="muted">(до 10 всего)</span></label>
                <input type="file" id="photos" name="photos[]" accept="image/*,.heic,.heif" multiple>
                <span class="field__hint">JPG, PNG, WEBP, HEIC · до 10 МБ каждое · максимум 10 фото на активность</span>
            </div>

            

            

            <div class="form-actions">
                <button type="submit" class="btn btn--primary btn--large">Сохранить</button>
                <a href="<?= e(url('activity.php?id=' . $activityId)) ?>" class="btn btn--ghost btn--large">Отмена</a>
            </div>
        </form>

        <div class="danger-zone">
            <h3 class="danger-zone__title">Опасная зона</h3>
            <p class="muted">Удаление необратимо. Все лайки, комментарии, фото и усилия по сегментам будут удалены.</p>
            <form method="post" action="<?= e(url('activity.php?id=' . $activityId)) ?>"
                  onsubmit="return confirm('Удалить активность? Это необратимо.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="btn btn--danger">🗑 Удалить активность</button>
            </form>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
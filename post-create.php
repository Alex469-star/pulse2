<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Post.php';

auth_start();
$me = require_login();

// ---- Режим редактирования ----
$editId = (int)($_GET['id'] ?? 0);
$editPost = null;
if ($editId > 0) {
    $editPost = Post::findById($editId);
    if (!$editPost || (int)$editPost['user_id'] !== (int)$me['id']) {
        flash('Запись не найдена', 'error');
        redirect(url('feed.php'));
    }
}

$existingPhotos = $editPost ? Post::photos($editId) : [];

$pageTitle = $editPost ? 'Редактировать запись' : 'Новая запись';

$extraJs = [
    'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js',
    url('assets/js/create-post.js') . '?v=' . @filemtime(__DIR__ . '/assets/js/create-post.js'),
];

require __DIR__ . '/includes/header.php';
?>

<section class="form-page form-page--wide">
    <div class="form-card">
        <h1 class="form-card__title">
            <?= $editPost ? 'Редактировать запись' : 'Новая запись в блог' ?>
        </h1>
        <p class="form-card__subtitle">
            Поделитесь историей, впечатлением или заметкой. Можно прикрепить несколько фото.
        </p>

        <div id="post-results" hidden></div>

        <form id="post-form"
              data-api-save="<?= e(url('api/save-post.php')) ?>"
              data-api-add-photo="<?= e(url('api/add-post-photo.php')) ?>"
              data-api-del-photo="<?= e(url('api/post-photo-delete.php')) ?>"
              data-post-id="<?= (int)$editId ?>"
              data-post-url="<?= e(url('post.php?id=')) ?>"
              data-feed-url="<?= e(url('feed.php')) ?>"
              data-csrf="<?= e(csrf_token()) ?>"
              method="post" enctype="multipart/form-data" novalidate>

            <div class="field">
                <label for="title">Заголовок</label>
                <input type="text" id="title" name="title"
                       value="<?= e($editPost ? (string)$editPost['title'] : '') ?>"
                       required maxlength="190" placeholder="Например: Первый марафон — как это было">
            </div>

            <div class="field">
                <label for="body">Текст</label>
                <textarea id="body" name="body" rows="12" required maxlength="20000"
                          placeholder="Расскажите, что произошло..."><?= e($editPost ? (string)$editPost['body'] : '') ?></textarea>
                <span class="field__hint">До 20000 символов. Переводы строк сохраняются.</span>
            </div>

            <div class="field">
                <label for="visibility">Кто видит</label>
                <select id="visibility" name="visibility">
                    <?php
                    $vis = [
                        'public'    => 'Публичная — видят все',
                        'followers' => 'Только подписчики',
                        'private'   => 'Приватная — только я',
                    ];
                    $current = $editPost ? (string)$editPost['visibility'] : 'public';
                    foreach ($vis as $val => $label):
                        $sel = ($current === $val) ? 'selected' : '';
                    ?>
                        <option value="<?= e($val) ?>" <?= $sel ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($existingPhotos): ?>
                <div class="post-existing-photos">
                    <label>Текущие фото</label>
                    <div class="post-photos-grid">
                        <?php foreach ($existingPhotos as $ph): ?>
                            <div class="post-photo-item">
                                <img src="<?= e($ph['url']) ?>" alt="">
                                <button type="button"
                                        class="post-photo-item__delete js-delete-photo"
                                        data-photo-id="<?= (int)$ph['id'] ?>"
                                        title="Удалить фото">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <span class="field__hint">Нажмите × на фото, чтобы удалить. Сохраняется сразу.</span>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="photos"><?= $existingPhotos ? 'Добавить ещё фото' : 'Фото' ?></label>
                <input type="file" id="photos" name="photos[]" accept="image/*,.heic,.heif" multiple>
                <span class="field__hint">JPG, PNG, WEBP, HEIC · до 10 МБ каждое · до 10 фото</span>
            </div>

            

            <div id="post-upload-progress" class="upload-progress" hidden>
                <div class="upload-progress__bar-wrap">
                    <div id="post-upload-progress-bar" class="upload-progress__bar"></div>
                </div>
                <div id="post-upload-progress-text" class="upload-progress__text">Подготовка…</div>
                <div id="post-upload-progress-hint" class="upload-progress__hint"></div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary btn--large">
                    <?= $editPost ? 'Сохранить' : 'Опубликовать' ?>
                </button>
                <a href="<?= e($editPost ? url('post.php?id=' . $editId) : url('feed.php')) ?>"
                   class="btn btn--ghost btn--large">Отмена</a>
            </div>
        </form>

        <?php if ($editPost): ?>
            <div class="danger-zone">
                <h3 class="danger-zone__title">Опасная зона</h3>
                <p class="muted">Удаление записи необратимо. Все лайки, комментарии и фото будут удалены.</p>
                <form method="post" action="<?= e(url('post.php?id=' . $editId)) ?>"
                      onsubmit="return confirm('Удалить запись? Это необратимо.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn--danger">🗑 Удалить запись</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
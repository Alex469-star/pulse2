<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Club.php';

auth_start();
$me = current_user();
require_login();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $name = trim((string)($_POST['name'] ?? ''));
    if (mb_strlen($name) < 3) $error = 'Название минимум 3 символа';
    elseif (mb_strlen($name) > 120) $error = 'Название слишком длинное';
    else {
        try {
            $clubId = Club::create((int)$me['id'], [
                'name'        => $name,
                'description' => trim((string)($_POST['description'] ?? '')),
                'city'        => trim((string)($_POST['city'] ?? '')),
                'country'     => trim((string)($_POST['country'] ?? '')),
                'sport_type'  => (string)($_POST['sport_type'] ?? 'mixed'),
                'visibility'  => (string)($_POST['visibility'] ?? 'public'),
                'join_policy' => (string)($_POST['join_policy'] ?? 'open'),
            ]);
            flash('Клуб создан', 'success');
            redirect(url('club.php?id=' . $clubId));
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Создать клуб';
require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Новый клуб</h1>
        <p class="form-card__subtitle">Объедините спортсменов вокруг общей цели</p>

        <?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>

            <div class="field">
                <label>Название *</label>
                <input type="text" name="name" required maxlength="120" autofocus>
            </div>

            <div class="field">
                <label>Описание</label>
                <textarea name="description" rows="4" maxlength="2000"
                          placeholder="О клубе, цели, регулярные встречи..."></textarea>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Город</label>
                    <input type="text" name="city" maxlength="120">
                </div>
                <div class="field">
                    <label>Страна</label>
                    <input type="text" name="country" maxlength="120">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Вид спорта</label>
                    <select name="sport_type">
                        <option value="mixed">Смешанный</option>
                        <option value="run">Бег</option>
                        <option value="ride">Велосипед</option>
                        <option value="swim">Плавание</option>
                        <option value="ski">Лыжи</option>
                        <option value="walk">Ходьба</option>
                        <option value="hike">Хайкинг</option>
                    </select>
                </div>
                <div class="field">
                    <label>Видимость</label>
                    <select name="visibility">
                        <option value="public">Публичный — виден всем</option>
                        <option value="private">Приватный — только участникам</option>
                        <option value="hidden">Скрытый — только по ссылке</option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label>Как вступать</label>
                <select name="join_policy">
                    <option value="open">Свободно</option>
                    <option value="request">По заявке</option>
                    <option value="invite">Только по приглашению</option>
                </select>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary">Создать клуб</button>
                <a class="btn btn--ghost" href="<?= e(url('clubs.php')) ?>">Отмена</a>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
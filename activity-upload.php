<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/models/Gear.php';
require_once __DIR__ . '/services/GpxParser.php';
require_once __DIR__ . '/services/TcxParser.php';
require_once __DIR__ . '/services/FitParser.php';

auth_start();
$me = require_login();

$errors  = [];
$results = [];

$allowedExt = ['gpx', 'tcx', 'fit'];
$maxSize    = 25 * 1024 * 1024;
$maxFiles   = 10;

$old = [
    'title'      => '',
    'type'       => 'run',
    'visibility' => 'public',
    'gear_id'    => '',
];

// ============================================================
// POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['title']      = trim((string)($_POST['title'] ?? ''));
    $old['type']       = (string)($_POST['type'] ?? 'run');
    $old['visibility'] = (string)($_POST['visibility'] ?? 'public');
    $old['gear_id']    = (string)($_POST['gear_id'] ?? '');

    $allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
    if (!in_array($old['type'], $allowedTypes, true)) $old['type'] = 'run';

    $allowedVisibility = ['public','followers','private'];
    if (!in_array($old['visibility'], $allowedVisibility, true)) $old['visibility'] = 'public';

    $gearId = null;
    if ($old['gear_id'] !== '') {
        $g = Gear::findById((int)$old['gear_id']);
        if ($g && (int)$g['user_id'] === (int)$me['id']) {
            $gearId = (int)$g['id'];
        }
    }

    if (empty($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        $errors['files'] = 'Выберите хотя бы один файл';
    } else {
        $files = pulse_rearrange_files($_FILES['files']);

        if (count($files) > $maxFiles) {
            $errors['files'] = 'Не более ' . $maxFiles . ' файлов за раз';
        }

        if (!$errors) {
            $index = 0;
            foreach ($files as $file) {
                $index++;
                $originalName = (string)($file['name'] ?? ('Файл ' . $index));

                $result = [
                    'index'         => $index,
                    'original_name' => $originalName,
                    'status'        => 'error',
                    'message'       => '',
                    'activity_id'   => null,
                    'summary'       => null,
                ];

                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $result['message'] = pulse_upload_error_message((int)$file['error']);
                    $results[] = $result;
                    continue;
                }

                if (($file['size'] ?? 0) > $maxSize) {
                    $result['message'] = 'Файл больше 25 МБ';
                    $results[] = $result;
                    continue;
                }

                $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    $result['message'] = 'Недопустимое расширение: ' . $ext;
                    $results[] = $result;
                    continue;
                }

                if (!is_uploaded_file($file['tmp_name'] ?? '')) {
                    $result['message'] = 'Файл не был загружен через HTTP';
                    $results[] = $result;
                    continue;
                }

                $parsed = null;
                try {
                    $parsed = match ($ext) {
                        'gpx' => GpxParser::parse($file['tmp_name']),
                        'tcx' => TcxParser::parse($file['tmp_name']),
                        'fit' => FitParser::parse($file['tmp_name']),
                        default => throw new RuntimeException('Неподдерживаемый формат'),
                    };
                } catch (Throwable $ex) {
                    $result['message'] = $ex->getMessage();
                    $results[] = $result;
                    continue;
                }

                try {
                    $points = $parsed['points'];
                    if (count($points) > 20000) {
                        $step = (int)ceil(count($points) / 20000);
                        $reduced = [];
                        for ($i = 0; $i < count($points); $i += $step) {
                            $reduced[] = $points[$i];
                        }
                        if (end($reduced) !== end($points)) {
                            $reduced[] = end($points);
                        }
                        $points = $reduced;
                    }

                    $fileBase = pathinfo($originalName, PATHINFO_FILENAME);

                    $title = $old['title'] !== ''
                        ? $old['title'] . (count($files) > 1 ? ' — ' . $fileBase : '')
                        : $fileBase;

                    $title = mb_substr($title, 0, 190);

                    $activityId = Activity::create((int)$me['id'], [
                        'type'             => $old['type'],
                        'title'            => $title,
                        'description'      => null,
                        'started_at'       => $parsed['started_at'],
                        'duration_sec'     => $parsed['duration_sec'],
                        'distance_m'       => $parsed['distance_m'],
                        'elevation_gain_m' => $parsed['elevation_gain_m'],
                        'avg_speed_mps'    => $parsed['avg_speed_mps'],
                        'max_speed_mps'    => $parsed['max_speed_mps'],
                        'gear_id'          => $gearId,
                        'track_json'       => json_encode($points, JSON_UNESCAPED_UNICODE),
                        'visibility'       => $old['visibility'],
                        'avg_hr'      => $parsed['avg_hr']      ?? null,
'max_hr'      => $parsed['max_hr']      ?? null,
'avg_cadence' => $parsed['avg_cadence'] ?? null,
'max_cadence' => $parsed['max_cadence'] ?? null,
'avg_power_w' => $parsed['avg_power_w'] ?? null,
'max_power_w' => $parsed['max_power_w'] ?? null,
'avg_temp_c'  => $parsed['avg_temp_c']  ?? null,
'has_sensors' => $parsed['has_sensors'] ?? 0,
                    ]);

                    // Загрузка фото (только к первой активности)
                    if ($index === 1 && !empty($_FILES['photos']['tmp_name'][0])) {
                        $order = 0;
                        foreach ($_FILES['photos']['tmp_name'] as $pi => $tmp) {
                            if ($order >= 10) break;
                            if (!is_uploaded_file($tmp)) continue;
                            $perr = (int)($_FILES['photos']['error'][$pi] ?? UPLOAD_ERR_NO_FILE);
                            if ($perr !== UPLOAD_ERR_OK) continue;

                            $res = upload_photo([
                                'tmp_name' => $tmp,
                                'size'     => (int)($_FILES['photos']['size'][$pi] ?? 0),
                                'error'    => $perr,
                            ], 'activities', (int)$me['id']);

                            if ($res['url']) {
                                Activity::addPhoto($activityId, $res['url'], $order++);
                            }
                        }
                    }

                    $result['status']      = 'ok';
                    $result['message']     = 'Загружено успешно';
                    $result['activity_id'] = $activityId;
                    $result['summary']     = [
                        'distance_m'       => $parsed['distance_m'],
                        'duration_sec'     => $parsed['duration_sec'],
                        'elevation_gain_m' => $parsed['elevation_gain_m'],
                        'points'           => count($points),
                    ];
                } catch (Throwable $ex) {
                    $result['message'] = 'Ошибка сохранения: ' . $ex->getMessage();
                }

                $results[] = $result;
            }

            $okCount = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
            if ($okCount > 0) {
                flash('Загружено файлов: ' . $okCount . ' из ' . count($results), 'success');
            }
        }
    }
}

/**
 * Преобразует $_FILES['files'] в плоский массив.
 */
function pulse_rearrange_files(array $files): array
{
    $out = [];
    $count = count($files['name'] ?? []);
    for ($i = 0; $i < $count; $i++) {
        $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if (empty($files['name'][$i]) && $err === UPLOAD_ERR_NO_FILE) continue;
        $out[] = [
            'name'     => $files['name'][$i]     ?? '',
            'type'     => $files['type'][$i]     ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error'    => $err,
            'size'     => $files['size'][$i]     ?? 0,
        ];
    }
    return $out;
}

function pulse_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => 'Файл превышает upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE  => 'Файл превышает MAX_FILE_SIZE в форме',
        UPLOAD_ERR_PARTIAL    => 'Файл загружен частично',
        UPLOAD_ERR_NO_FILE    => 'Файл не был загружен',
        UPLOAD_ERR_NO_TMP_DIR => 'Нет временной папки на сервере',
        UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
        UPLOAD_ERR_EXTENSION  => 'Загрузка остановлена PHP-расширением',
        default               => 'Неизвестная ошибка загрузки (код ' . $code . ')',
    };
}

$gearList = Gear::allForUser((int)$me['id']);

$pageTitle = 'Загрузить активности';

$extraJs = array_merge($extraJs ?? [], [
    'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js',
]);
require __DIR__ . '/includes/header.php';
?>

<section class="form-page">
    <div class="form-card">
        <h1 class="form-card__title">Загрузить активности</h1>
        <p class="form-card__subtitle">
            Можно выбрать сразу несколько файлов. Поддерживаются
            <strong>GPX</strong>, <strong>TCX</strong> и <strong>FIT</strong>
            (до 10 файлов, каждый не более 25 МБ).
        </p>

        <?php if (!empty($errors['files'])): ?>
            <div class="alert alert--error"><?= e($errors['files']) ?></div>
        <?php endif; ?>

        <?php if ($results): ?>
            <div class="upload-results">
                <h2 class="upload-results__title">
                    Результат загрузки:
                    <?php $okCount = count(array_filter($results, fn($r) => $r['status'] === 'ok')); ?>
                    <span class="<?= $okCount > 0 ? 'text-success' : 'text-error' ?>">
                        <?= $okCount ?> из <?= count($results) ?>
                    </span>
                </h2>

                <?php foreach ($results as $r): ?>
                    <div class="upload-result upload-result--<?= $r['status'] === 'ok' ? 'ok' : 'error' ?>">
                        <div class="upload-result__head">
                            <span class="upload-result__icon">
                                <?= $r['status'] === 'ok' ? '✓' : '✕' ?>
                            </span>
                            <span class="upload-result__name"><?= e($r['original_name']) ?></span>
                        </div>
                        <div class="upload-result__message"><?= e($r['message']) ?></div>

                        <?php if ($r['status'] === 'ok' && $r['summary']): ?>
                            <div class="upload-result__summary">
                                <span><?= e(format_distance((float)$r['summary']['distance_m'])) ?></span>
                                <span><?= e(format_duration((int)($r['summary']['duration_sec'] ?? 0))) ?></span>
                                <?php if (!empty($r['summary']['elevation_gain_m'])): ?>
                                    <span>↑<?= (int)$r['summary']['elevation_gain_m'] ?> м</span>
                                <?php endif; ?>
                                <span><?= (int)$r['summary']['points'] ?> точек</span>
                            </div>
                            <a href="<?= e(url('activity.php?id=' . (int)$r['activity_id'])) ?>"
                               class="upload-result__link">Открыть активность →</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="upload-results__actions">
                    <a href="<?= e(url('feed.php')) ?>" class="btn btn--primary">Перейти в ленту</a>
                    <a href="<?= e(url('activity-upload.php')) ?>" class="btn btn--ghost">Загрузить ещё</a>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" novalidate>
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
                <input type="text" id="title" name="title" value="<?= e($old['title']) ?>" maxlength="150">
                <span class="field__hint">
                    Если загружаете несколько файлов, к каждому добавится имя файла.
                </span>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="type">Тип активности</label>
                    <select id="type" name="type">
                        <option value="run"   <?= $old['type'] === 'run'   ? 'selected' : '' ?>>🏃 Бег</option>
                        <option value="ride"  <?= $old['type'] === 'ride'  ? 'selected' : '' ?>>🚴 Велосипед</option>
                        <option value="swim"  <?= $old['type'] === 'swim'  ? 'selected' : '' ?>>🏊 Плавание</option>
                        <option value="ski"   <?= $old['type'] === 'ski'   ? 'selected' : '' ?>>⛷️ Лыжи</option>
                        <option value="walk"  <?= $old['type'] === 'walk'  ? 'selected' : '' ?>>🚶 Ходьба</option>
                        <option value="hike"  <?= $old['type'] === 'hike'  ? 'selected' : '' ?>>🥾 Хайкинг</option>
                        <option value="other" <?= $old['type'] === 'other' ? 'selected' : '' ?>>📦 Другое</option>
                    </select>
                </div>

                <div class="field">
                    <label for="visibility">Кто видит</label>
                    <select id="visibility" name="visibility">
                        <option value="public"    <?= $old['visibility'] === 'public'    ? 'selected' : '' ?>>Публичная</option>
                        <option value="followers" <?= $old['visibility'] === 'followers' ? 'selected' : '' ?>>Только подписчики</option>
                        <option value="private"   <?= $old['visibility'] === 'private'   ? 'selected' : '' ?>>Приватная</option>
                    </select>
                </div>
            </div>

            <?php if ($gearList): ?>
                <div class="field">
                    <label for="gear_id">Инвентарь <span class="muted">(необязательно)</span></label>
                    <select id="gear_id" name="gear_id">
                        <option value="">— не привязывать —</option>
                        <?php foreach ($gearList as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= $old['gear_id'] === (string)$g['id'] ? 'selected' : '' ?>>
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

<!-- Прогресс конвертации HEIC -->
<div id="heic-progress" class="heic-progress" hidden>
    <span class="heic-progress__spinner"></span>
    <span class="heic-progress__text">Конвертирую HEIC-фото…</span>
</div>

            <button type="submit" class="btn btn--primary btn--large" style="width:100%">
                Загрузить
            </button>
        </form>
    </div>
</section>

<script>
(function () {
    "use strict";

    var input = document.getElementById("photos");
    if (!input) return;

    if (typeof heic2any === "undefined") {
        console.warn("heic2any не загрузился — HEIC не будет конвертироваться");
    }

    var progress = document.getElementById("heic-progress");

    // Перехватываем отправку формы: конвертируем HEIC и подменяем файлы
    var form = input.closest("form");
    if (!form) return;

    form.addEventListener("submit", function (e) {
        if (!input.files || !input.files.length) return;

        var hasHeic = false;
        for (var i = 0; i < input.files.length; i++) {
            var name = (input.files[i].name || "").toLowerCase();
            if (name.endsWith(".heic") || name.endsWith(".heif")) {
                hasHeic = true;
                break;
            }
        }
        if (!hasHeic) return;   // обычная отправка

        e.preventDefault();

        if (progress) progress.hidden = false;

        var files = Array.from(input.files);
        var converted = [];

        var chain = Promise.resolve();

        files.forEach(function (file, index) {
            chain = chain.then(function () {
                var name = (file.name || "").toLowerCase();
                var isHeic = name.endsWith(".heic") || name.endsWith(".heif");

                if (!isHeic) {
                    converted.push(file);
                    return;
                }

                if (typeof heic2any === "undefined") {
                    // Не получилось — оставляем как есть, сервер вернёт ошибку
                    converted.push(file);
                    return;
                }

                return heic2any({
                    blob: file,
                    toType: "image/jpeg",
                    quality: 0.85
                }).then(function (result) {
                    var blob = Array.isArray(result) ? result[0] : result;
                    var newName = file.name.replace(/\.(heic|heif)$/i, ".jpg");
                    var newFile = new File([blob], newName, { type: "image/jpeg" });
                    converted.push(newFile);
                }).catch(function (err) {
                    console.error("Ошибка конвертации HEIC:", err);
                    // Оставляем оригинал — сервер вернёт ошибку
                    converted.push(file);
                });
            });
        });

        chain.then(function () {
            // Собираем новый DataTransfer, чтобы подменить input.files
            var dt = new DataTransfer();
            converted.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;

            if (progress) progress.hidden = true;

// Отправляем через fetch, чтобы гарантированно передать cookies и csrf
var fd = new FormData(form);
// Явно перекладываем сконвертированные файлы
fd.delete('files[]');
converted.forEach(function (f) {
    fd.append('files[]', f, f.name);
});
// Перекладываем фото активностей, если были
if (input === photosInput) {
    fd.delete('photos[]');
    converted.forEach(function (f) {
        fd.append('photos[]', f, f.name);
    });
}

fetch(form.action || window.location.href, {
    method: 'POST',
    body: fd,
    credentials: 'same-origin'
}).then(function (response) {
    // Сервер ответил — переходим на ответ (обычно это redirect → HTML)
    return response.text().then(function (html) {
        // Простейший способ: заменить содержимое страницы
        document.open();
        document.write(html);
        document.close();
    });
}).catch(function (err) {
    console.error(err);
    alert('Ошибка отправки. Попробуйте ещё раз.');
});
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/models/Segment.php';
require_once __DIR__ . '/services/GpxParser.php';

auth_start();
$me = require_login();

$errors = [];
$old = [
    'name'         => '',
    'type'         => 'run',
    'elapsed_time' => '',
    'is_public'    => 1,
];

// ---- Активность, из которой создаём сегмент (опционально) ----
$activityId = (int)($_GET['activity_id'] ?? $_POST['activity_id'] ?? 0);
$activity   = null;

if ($activityId > 0) {
    try {
        $activity = Activity::findById($activityId);
        if (!$activity) {
            $errors['_general'] = 'Активность не найдена';
        } elseif ((int)$activity['user_id'] !== (int)$me['id']) {
            $errors['_general'] = 'Можно создавать сегменты только из своих активностей';
            $activity = null;
        }
    } catch (Throwable $e) {
        $errors['_general'] = 'Ошибка загрузки активности: ' . $e->getMessage();
    }
}

// ---- POST: создание сегмента ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors['_general'])) {
    csrf_check($_POST['csrf'] ?? null);

    $old['name']         = trim((string)($_POST['name'] ?? ''));
    $old['type']         = (string)($_POST['type'] ?? 'run');
    $old['elapsed_time'] = trim((string)($_POST['elapsed_time'] ?? ''));
    $old['is_public']    = isset($_POST['is_public']) ? 1 : 0;

    if ($old['name'] === '') {
        $errors['name'] = 'Введите название';
    } elseif (mb_strlen($old['name']) > 190) {
        $errors['name'] = 'Максимум 190 символов';
    }

    $allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
    if (!in_array($old['type'], $allowedTypes, true)) {
        $old['type'] = 'run';
    }

    $trackRaw = (string)($_POST['track_json'] ?? '');
    $track = json_decode($trackRaw, true);

    if (!is_array($track) || count($track) < 2) {
        $errors['track'] = 'Выберите участок сегмента (минимум 2 точки)';
    } else {
        $cleanTrack = [];
        foreach ($track as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            $lat = (float)$p['lat'];
            $lng = (float)$p['lng'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            $cleanTrack[] = ['lat' => $lat, 'lng' => $lng];
        }
        if (count($cleanTrack) < 2) {
            $errors['track'] = 'Получилось меньше 2 корректных точек';
        } else {
            $track = $cleanTrack;
        }
    }

    // ---- Время прохождения ----
    $elapsedSec = null;

    // 1. Если передано auto_elapsed из JS — используем его
    if (!empty($_POST['auto_elapsed_sec']) && ctype_digit((string)$_POST['auto_elapsed_sec'])) {
        $candidate = (int)$_POST['auto_elapsed_sec'];
        if ($candidate > 0) {
            $elapsedSec = $candidate;
        }
    }

    // 2. Иначе — парсим введённое вручную
    if ($elapsedSec === null && $activity && $old['elapsed_time'] !== '') {
        $parts = array_map('intval', explode(':', $old['elapsed_time']));
        if (count($parts) === 2) {
            $elapsedSec = $parts[0] * 60 + $parts[1];
        } elseif (count($parts) === 3) {
            $elapsedSec = $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        } else {
            $errors['elapsed_time'] = 'Формат: MM:SS или HH:MM:SS';
        }
        if ($elapsedSec !== null && $elapsedSec <= 0) {
            $errors['elapsed_time'] = 'Время должно быть больше нуля';
            $elapsedSec = null;
        }
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

            $segmentId = Segment::create((int)$me['id'], [
                'name'              => $old['name'],
                'type'              => $old['type'],
                'distance_m'        => $distance,
                'elevation_gain_m'  => null,
                'track_json'        => json_encode($track, JSON_UNESCAPED_UNICODE),
                'is_public'         => $old['is_public'],
            ]);

            if ($activity && $elapsedSec !== null) {
                Segment::addEffort(
                    $segmentId,
                    (int)$activity['id'],
                    (int)$me['id'],
                    $elapsedSec,
                    $activity['started_at']
                );
            }

            flash('Сегмент «' . $old['name'] . '» создан', 'success');
            redirect(url('segment.php?id=' . $segmentId));
        } catch (Throwable $e) {
            $errors['_general'] = 'Не удалось сохранить: ' . $e->getMessage();
        }
    }
}

// ---- Данные трека активности для JS ----
$activityTrack = [];
if ($activity && !empty($activity['track_json'])) {
    $decoded = json_decode((string)$activity['track_json'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $p) {
            if (isset($p['lat'], $p['lng'])) {
                $activityTrack[] = [
                    'lat' => (float)$p['lat'],
                    'lng' => (float)$p['lng'],
                    'ele' => isset($p['ele']) ? (float)$p['ele'] : null,
                    't'   => isset($p['t'])   ? (int)$p['t']     : null,
                ];
            }
        }
    }
}

$pageTitle = $activity ? 'Новый сегмент из активности' : 'Новый сегмент';

$extraCss = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'];
$extraJs  = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

$inlineJs = '
window.__ACTIVITY_TRACK__ = ' . json_encode($activityTrack, JSON_UNESCAPED_UNICODE) . ';

(function () {
    "use strict";

    /* ============================================================
       ИНИЦИАЛИЗАЦИЯ КАРТЫ
       ============================================================ */
    if (typeof L === "undefined") {
        console.error("Leaflet не загрузился");
        var el = document.getElementById("seg-map");
        if (el) el.innerHTML = "<div style=\"padding:40px;text-align:center;color:#b3261e\">Не удалось загрузить карту</div>";
        return;
    }

    var track = window.__ACTIVITY_TRACK__ || [];
    var mapEl = document.getElementById("seg-map");
    if (!mapEl) return;

    var map = L.map("seg-map", { scrollWheelZoom: true }).setView([55.751244, 37.618423], 12);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap"
    }).addTo(map);

    // Слой для «базового» трека (серый)
    var baseLine = null;
    // Слой для выделенного сегмента (оранжевый)
    var selectedLine = null;
    // Маркеры начала и конца
    var startMarker = null;
    var endMarker = null;

    /* ============================================================
       УТИЛИТЫ
       ============================================================ */
    function haversine(a, b) {
        var R = 6371000;
        var dLat = (b.lat - a.lat) * Math.PI / 180;
        var dLng = (b.lng - a.lng) * Math.PI / 180;
        var lat1 = a.lat * Math.PI / 180;
        var lat2 = b.lat * Math.PI / 180;
        var h = Math.sin(dLat/2)**2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng/2)**2;
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
    }

    function totalLength(pts) {
        var d = 0;
        for (var i = 1; i < pts.length; i++) d += haversine(pts[i-1], pts[i]);
        return d;
    }

    function formatMeters(m) {
        if (m < 1000) return Math.round(m) + " м";
        return (m / 1000).toFixed(2) + " км";
    }

    function formatDuration(sec) {
        sec = Math.round(sec);
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        if (h > 0) return h + ":" + String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
        return m + ":" + String(s).padStart(2, "0");
    }

    function fmtLatLng(p) {
        return "<span class=\"seg-marker__label\">" + p.lat.toFixed(5) + ", " + p.lng.toFixed(5) + "</span>";
    }

    /* ============================================================
       ОТРИСОВКА БАЗОВОГО ТРЕКА
       ============================================================ */
    if (track.length >= 2) {
        baseLine = L.polyline(track.map(function(p) { return [p.lat, p.lng]; }), {
            color: "#9aa3b2",
            weight: 5,
            opacity: 0.55,
            interactive: false
        }).addTo(map);

        map.fitBounds(baseLine.getBounds(), { padding: [30, 30] });
    }

    /* ============================================================
       ЭЛЕМЕНТЫ UI
       ============================================================ */
    var infoEl       = document.getElementById("track-info");
    var countEl      = document.getElementById("track-count");
    var lengthEl     = document.getElementById("track-length");
    var startLabel   = document.getElementById("start-label");
    var endLabel     = document.getElementById("end-label");
    var startRange   = document.getElementById("start-range");
    var endRange     = document.getElementById("end-range");
    var startValueEl = document.getElementById("start-value");
    var endValueEl   = document.getElementById("end-value");
    var elevationEl  = document.getElementById("seg-elevation");
    var hiddenInput  = document.getElementById("track_json");
    var autoElapsedEl = document.getElementById("auto-elapsed-sec");
    var form         = document.getElementById("seg-form");

    /* ============================================================
       ФУНКЦИЯ ВЫДЕЛЕНИЯ СЕГМЕНТА
       ============================================================ */
    function applySegment(startIdx, endIdx) {
        if (!track.length) return;

        if (startIdx > endIdx) {
            var t = startIdx; startIdx = endIdx; endIdx = t;
        }
        startIdx = Math.max(0, Math.min(track.length - 1, startIdx));
        endIdx   = Math.max(0, Math.min(track.length - 1, endIdx));

        var slice = track.slice(startIdx, endIdx + 1);

        // Линия сегмента
        if (selectedLine) map.removeLayer(selectedLine);
        if (slice.length >= 2) {
            selectedLine = L.polyline(slice.map(function(p) { return [p.lat, p.lng]; }), {
                color: "#ff5a1f",
                weight: 6,
                opacity: 0.95
            }).addTo(map);
        }

        // Маркеры начала и конца
        if (startMarker) map.removeLayer(startMarker);
        if (endMarker)   map.removeLayer(endMarker);

        var first = slice[0];
        var last  = slice[slice.length - 1];

        startMarker = L.marker([first.lat, first.lng], {
            icon: L.divIcon({
                className: "seg-marker seg-marker--start",
                html: "<div class=\"seg-marker__dot\"></div>",
                iconSize: [22, 22],
                iconAnchor: [11, 11]
            })
        }).addTo(map).bindPopup("Начало сегмента");

        endMarker = L.marker([last.lat, last.lng], {
            icon: L.divIcon({
                className: "seg-marker seg-marker--end",
                html: "<div class=\"seg-marker__dot\"></div>",
                iconSize: [22, 22],
                iconAnchor: [11, 11]
            })
        }).addTo(map).bindPopup("Конец сегмента");

        // Инфо-плашка
        var dist = totalLength(slice);
        if (infoEl)     infoEl.style.display = "flex";
        if (countEl)    countEl.textContent = slice.length;
        if (lengthEl)   lengthEl.textContent = formatMeters(dist);
        if (startLabel) startLabel.textContent = "Точка " + (startIdx + 1);
        if (endLabel)   endLabel.textContent   = "Точка " + (endIdx + 1);
        if (startValueEl) startValueEl.textContent = (startIdx + 1);
        if (endValueEl)   endValueEl.textContent   = (endIdx + 1);

        // Скрытый input для отправки
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(
                slice.map(function(p) { return { lat: +p.lat.toFixed(6), lng: +p.lng.toFixed(6) }; })
            );
        }

        // Авто-время прохождения (если есть t в точках)
        if (autoElapsedEl) {
            var startT = first.t;
            var endT = last.t;
            if (startT && endT && endT > startT) {
                autoElapsedEl.value = String(endT - startT);
            } else {
                autoElapsedEl.value = "";
            }
        }

        // График высот
        renderElevation(slice);
    }

    /* ============================================================
       ГРАФИК ВЫСОТ (мини)
       ============================================================ */
    function renderElevation(slice) {
        if (!elevationEl) return;
        var withEle = slice.filter(function(p) { return p.ele !== null && p.ele !== undefined; });
        if (withEle.length < 2) {
            elevationEl.innerHTML = "<div class=\"seg-elevation__empty\">Нет данных о высоте</div>";
            return;
        }

        var minEle = Infinity, maxEle = -Infinity;
        var dist = 0;
        var xs = [0];
        var ys = [withEle[0].ele];

        for (var i = 1; i < withEle.length; i++) {
            dist += haversine(withEle[i-1], withEle[i]);
            xs.push(dist);
            ys.push(withEle[i].ele);
            if (withEle[i].ele < minEle) minEle = withEle[i].ele;
            if (withEle[i].ele > maxEle) maxEle = withEle[i].ele;
        }
        if (withEle[0].ele < minEle) minEle = withEle[0].ele;
        if (withEle[0].ele > maxEle) maxEle = withEle[0].ele;

        var W = elevationEl.clientWidth || 600;
        var H = 100;
        var padX = 4, padY = 6;
        var spanX = xs[xs.length - 1] || 1;
        var spanY = (maxEle - minEle) || 1;

        var points = xs.map(function(x, i) {
            var px = padX + (x / spanX) * (W - 2 * padX);
            var py = H - padY - ((ys[i] - minEle) / spanY) * (H - 2 * padY);
            return px.toFixed(1) + "," + py.toFixed(1);
        }).join(" ");

        var area = points + " " + (W - padX) + "," + (H - padY) + " " + padX + "," + (H - padY);

        elevationEl.innerHTML =
            "<svg viewBox=\"0 0 " + W + " " + H + "\" preserveAspectRatio=\"none\" width=\"100%\" height=\"" + H + "\">" +
                "<defs>" +
                    "<linearGradient id=\"segElevGrad\" x1=\"0\" y1=\"0\" x2=\"0\" y2=\"1\">" +
                        "<stop offset=\"0%\" stop-color=\"#ff5a1f\" stop-opacity=\".35\"/>" +
                        "<stop offset=\"100%\" stop-color=\"#ff5a1f\" stop-opacity=\".02\"/>" +
                    "</linearGradient>" +
                "</defs>" +
                "<polygon points=\"" + area + "\" fill=\"url(#segElevGrad)\"/>" +
                "<polyline points=\"" + points + "\" fill=\"none\" stroke=\"#ff5a1f\" stroke-width=\"1.5\"/>" +
            "</svg>" +
            "<div class=\"seg-elevation__stats\">" +
                "<span>мин <strong>" + Math.round(minEle) + " м</strong></span>" +
                "<span>макс <strong>" + Math.round(maxEle) + " м</strong></span>" +
            "</div>";
    }

    /* ============================================================
       ПРИВЯЗКА ПОЛЗУНКОВ
       ============================================================ */
    if (startRange && endRange && track.length) {
        startRange.min = "0";
        startRange.max = String(track.length - 1);
        startRange.value = "0";

        endRange.min = "0";
        endRange.max = String(track.length - 1);
        endRange.value = String(track.length - 1);

        function syncFromRanges() {
            var s = parseInt(startRange.value, 10);
            var e = parseInt(endRange.value, 10);
            if (s > e) {
                // Двигаем тот ползунок, который сейчас «не ведущий»
                if (this === startRange) endRange.value = String(s);
                else startRange.value = String(e);
                s = Math.min(s, e);
                e = Math.max(s, e);
            }
            applySegment(parseInt(startRange.value, 10), parseInt(endRange.value, 10));
        }

        startRange.addEventListener("input", syncFromRanges);
        endRange.addEventListener("input", syncFromRanges);

        // Инициализация: весь трек выделен
        applySegment(0, track.length - 1);
    }

    /* ============================================================
       КНОПКИ: ВЕСЬ ТРЕК / СБРОС
       ============================================================ */
    var btnFull  = document.getElementById("seg-full");
    var btnReset = document.getElementById("seg-reset");

    if (btnFull && startRange && endRange) {
        btnFull.addEventListener("click", function() {
            startRange.value = "0";
            endRange.value = String(track.length - 1);
            applySegment(0, track.length - 1);
        });
    }
    if (btnReset && startRange && endRange) {
        btnReset.addEventListener("click", function() {
            startRange.value = "0";
            endRange.value = "0";
            applySegment(0, 0);
        });
    }

    /* ============================================================
       РУЧНОЙ РЕЖИМ (рисование по клику) — если нет трека
       ============================================================ */
    if (!track.length) {
        var manualPts = [];
        var manualLine = null;

        function redrawManual() {
            if (manualLine) map.removeLayer(manualLine);
            if (manualPts.length >= 2) {
                manualLine = L.polyline(manualPts.map(function(p) { return [p.lat, p.lng]; }), {
                    color: "#ff5a1f", weight: 6, opacity: 0.95
                }).addTo(map);
            }

            if (hiddenInput) {
                hiddenInput.value = JSON.stringify(
                    manualPts.map(function(p) { return { lat: +p.lat.toFixed(6), lng: +p.lng.toFixed(6) }; })
                );
            }

            if (infoEl) infoEl.style.display = manualPts.length ? "flex" : "none";
            if (countEl) countEl.textContent = manualPts.length;
            if (lengthEl) lengthEl.textContent = formatMeters(totalLength(manualPts));
        }

        map.on("click", function(e) {
            manualPts.push({ lat: e.latlng.lat, lng: e.latlng.lng, ele: null, t: null });
            redrawManual();
        });

        var resetManual = document.getElementById("track-reset");
        if (resetManual) {
            resetManual.addEventListener("click", function() {
                manualPts = [];
                redrawManual();
            });
        }
    }

    /* ============================================================
       ВАЛИДАЦИЯ ФОРМЫ
       ============================================================ */
    if (form) {
        form.addEventListener("submit", function(e) {
            if (!hiddenInput || !hiddenInput.value) {
                e.preventDefault();
                alert("Выберите участок на карте (минимум 2 точки)");
                return;
            }
            try {
                var arr = JSON.parse(hiddenInput.value);
                if (!Array.isArray(arr) || arr.length < 2) {
                    e.preventDefault();
                    alert("Выберите участок на карте (минимум 2 точки)");
                }
            } catch (err) {
                e.preventDefault();
                alert("Ошибка с точками трека. Обновите страницу.");
            }
        });
    }
})();
';

require __DIR__ . '/includes/header.php';
?>

<section class="form-page form-page--wide">
    <div class="form-card">
        <h1 class="form-card__title">
            <?= $activity ? 'Сегмент из активности' : 'Создать сегмент' ?>
        </h1>
        <p class="form-card__subtitle">
            <?php if ($activity): ?>
                Перетаскивайте <strong>два ползунка</strong> под картой, чтобы выбрать начало и конец сегмента.
                Время прохождения подставится автоматически, если в треке есть временные метки.
            <?php else: ?>
                Кликайте по карте, чтобы нарисовать линию сегмента. Минимум 2 точки.
            <?php endif; ?>
        </p>

        <?php if (!empty($errors['_general'])): ?>
            <div class="alert alert--error"><?= e($errors['_general']) ?></div>
        <?php endif; ?>

        <div id="seg-map" class="map" style="height: 460px; margin-bottom: 18px"></div>

        <?php if ($activity && $activityTrack): ?>
            <!-- ============ ПОЛЗУНКИ ============ -->
            <div class="seg-slider-wrap">
                <div class="seg-slider__labels">
                    <span id="start-label" class="seg-slider__label">Начало</span>
                    <span id="end-label"   class="seg-slider__label">Конец</span>
                </div>

                <div class="seg-slider">
                    <div class="seg-slider__track"></div>
                    <div class="seg-slider__fill" id="seg-slider-fill"></div>

                    <input type="range" id="start-range" class="seg-slider__input seg-slider__input--start" value="0" step="1">
                    <input type="range" id="end-range"   class="seg-slider__input seg-slider__input--end"   value="0" step="1">
                </div>

                <div class="seg-slider__values">
                    <span>точка <strong id="start-value">1</strong></span>
                    <span>точка <strong id="end-value">1</strong></span>
                </div>
            </div>

            <!-- ============ ПРОФИЛЬ ВЫСОТ (мини) ============ -->
            <div class="seg-elevation" id="seg-elevation"></div>

            <!-- ============ ИНФО ============ -->
            <div id="track-info" class="seg-track-info">
                <span>📍 Точек: <strong id="track-count">0</strong></span>
                <span>📏 Длина: <strong id="track-length">0 м</strong></span>
                <button type="button" id="seg-full"  class="seg-track-reset">Весь трек</button>
                <button type="button" id="seg-reset" class="seg-track-reset">Сбросить</button>
            </div>
        <?php else: ?>
            <div id="track-info" class="seg-track-info" style="display:none">
                <span>📍 Точек: <strong id="track-count">0</strong></span>
                <span>📏 Длина: <strong id="track-length">0 м</strong></span>
                <button type="button" id="track-reset" class="seg-track-reset">Сбросить</button>
            </div>
        <?php endif; ?>

        <form method="post" id="seg-form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="track_json" id="track_json" value="">
            <input type="hidden" name="auto_elapsed_sec" id="auto-elapsed-sec" value="">
            <?php if ($activity): ?>
                <input type="hidden" name="activity_id" value="<?= (int)$activity['id'] ?>">
            <?php endif; ?>

            <div class="field">
                <label for="name">Название сегмента</label>
                <input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required maxlength="190">
                <?php if (!empty($errors['name'])): ?>
                    <span class="field__error"><?= e($errors['name']) ?></span>
                <?php endif; ?>
            </div>

            <?php if (!empty($errors['track'])): ?>
                <div class="alert alert--error" style="margin-bottom:16px">
                    <?= e($errors['track']) ?>
                </div>
            <?php endif; ?>

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

                <?php if ($activity): ?>
                    <div class="field">
                        <label for="elapsed_time">Ваше время (MM:SS или HH:MM:SS)</label>
                        <input type="text" id="elapsed_time" name="elapsed_time"
                               value="<?= e($old['elapsed_time']) ?>"
                               placeholder="05:30" pattern="[0-9:]+">
                        <?php if (!empty($errors['elapsed_time'])): ?>
                            <span class="field__error"><?= e($errors['elapsed_time']) ?></span>
                        <?php endif; ?>
                        <span class="field__hint">Можно оставить пустым — если в треке есть временные метки, время подставится автоматически.</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="field field--checkbox">
                <label>
                    <input type="checkbox" name="is_public" <?= $old['is_public'] ? 'checked' : '' ?>>
                    Публичный сегмент (виден всем)
                </label>
            </div>

            <button type="submit" class="btn btn--primary btn--large" style="width:100%">
                Создать сегмент
            </button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
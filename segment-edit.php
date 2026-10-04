<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Segment.php';
require_once __DIR__ . '/services/GpxParser.php';
require_once __DIR__ . '/services/SegmentMatcher.php';

auth_start();
$me = require_login();

$segmentId = (int)($_GET['id'] ?? $_POST['segment_id'] ?? 0);
if ($segmentId <= 0) {
    http_response_code(404);
    exit('Сегмент не найден');
}

$segment = Segment::findById($segmentId);
if (!$segment) {
    http_response_code(404);
    exit('Сегмент не найден');
}

// Редактировать может только создатель
$isCreator = (int)$segment['creator_id'] === (int)$me['id'];
if (!$isCreator) {
    http_response_code(403);
    exit('Редактировать можно только свои сегменты');
}

$errors = [];
$old = [
    'name'      => (string)$segment['name'],
    'type'      => (string)$segment['type'],
    'is_public' => (int)$segment['is_public'],
];

// Исходный трек — для JS
$segmentTrack = [];
if (!empty($segment['track_json'])) {
    $decoded = json_decode((string)$segment['track_json'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $p) {
            if (isset($p['lat'], $p['lng'])) {
                $segmentTrack[] = [
                    'lat' => (float)$p['lat'],
                    'lng' => (float)$p['lng'],
                    'ele' => isset($p['ele']) ? (float)$p['ele'] : null,
                    't'   => isset($p['t'])   ? (int)$p['t']     : null,
                ];
            }
        }
    }
}

// ---- POST: сохранение ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['name']      = trim((string)($_POST['name'] ?? ''));
    $old['type']      = (string)($_POST['type'] ?? 'run');
    $old['is_public'] = isset($_POST['is_public']) ? 1 : 0;

    if ($old['name'] === '') {
        $errors['name'] = 'Введите название';
    } elseif (mb_strlen($old['name']) > 190) {
        $errors['name'] = 'Максимум 190 символов';
    }

    $allowedTypes = ['run','ride','swim','ski','walk','hike','other'];
    if (!in_array($old['type'], $allowedTypes, true)) {
        $old['type'] = 'run';
    }

    // ---- Трек (может быть изменён через ползунки) ----
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

    if (!$errors) {
        try {
            $distance = 0.0;
            for ($i = 1; $i < count($track); $i++) {
                $distance += GpxParser::haversinePublic(
                    $track[$i-1]['lat'], $track[$i-1]['lng'],
                    $track[$i]['lat'],   $track[$i]['lng']
                );
            }

            // Обновляем сам сегмент
            db()->prepare(
                'UPDATE segments
                 SET name = :name,
                     type = :type,
                     distance_m = :distance_m,
                     track_json = :track_json,
                     is_public = :is_public
                 WHERE id = :id AND creator_id = :uid'
            )->execute([
                ':name'        => $old['name'],
                ':type'        => $old['type'],
                ':distance_m'  => $distance,
                ':track_json'  => json_encode($track, JSON_UNESCAPED_UNICODE),
                ':is_public'   => $old['is_public'],
                ':id'          => $segmentId,
                ':uid'         => (int)$me['id'],
            ]);

            // Все усилия по этому сегменту устарели — удаляем авто
            db()->prepare(
                'DELETE FROM segment_efforts
                 WHERE segment_id = ? AND is_auto = 1'
            )->execute([$segmentId]);

            // Пересчитываем усилия всех пользователей по новому треку
            try {
                SegmentMatcher::matchAllUsersForSegment($segmentId);
            } catch (Throwable $e) {
                log_to_file('segment-match.log', 'Rematch after edit failed: ' . $e->getMessage());
            }

            flash('Сегмент обновлён', 'success');
            redirect(url('segment.php?id=' . $segmentId));
        } catch (Throwable $e) {
            $errors['_general'] = 'Не удалось сохранить: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Редактировать сегмент: ' . $segment['name'];

$extraCss = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'];
$extraJs  = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

$inlineJs = '
window.__SEGMENT_TRACK__ = ' . json_encode($segmentTrack, JSON_UNESCAPED_UNICODE) . ';

(function () {
    "use strict";

    if (typeof L === "undefined") {
        console.error("Leaflet не загрузился");
        return;
    }

    var track = window.__SEGMENT_TRACK__ || [];
    var mapEl = document.getElementById("seg-map");
    if (!mapEl) return;

    var map = L.map("seg-map", { scrollWheelZoom: true }).setView([55.751244, 37.618423], 12);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19, attribution: "&copy; OpenStreetMap"
    }).addTo(map);

    var baseLine = null;
    var selectedLine = null;
    var startMarker = null;
    var endMarker = null;

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

    if (track.length >= 2) {
        baseLine = L.polyline(track.map(function(p) { return [p.lat, p.lng]; }), {
            color: "#9aa3b2", weight: 5, opacity: 0.55, interactive: false
        }).addTo(map);
        map.fitBounds(baseLine.getBounds(), { padding: [30, 30] });
    }

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
    var form         = document.getElementById("seg-form");

    function applySegment(startIdx, endIdx) {
        if (!track.length) return;

        if (startIdx > endIdx) { var t = startIdx; startIdx = endIdx; endIdx = t; }
        startIdx = Math.max(0, Math.min(track.length - 1, startIdx));
        endIdx   = Math.max(0, Math.min(track.length - 1, endIdx));

        var slice = track.slice(startIdx, endIdx + 1);

        if (selectedLine) map.removeLayer(selectedLine);
        if (slice.length >= 2) {
            selectedLine = L.polyline(slice.map(function(p) { return [p.lat, p.lng]; }), {
                color: "#ff5a1f", weight: 6, opacity: 0.95
            }).addTo(map);
        }

        if (startMarker) map.removeLayer(startMarker);
        if (endMarker)   map.removeLayer(endMarker);

        var first = slice[0];
        var last  = slice[slice.length - 1];

        startMarker = L.marker([first.lat, first.lng], {
            icon: L.divIcon({
                className: "seg-marker seg-marker--start",
                html: "<div class=\"seg-marker__dot\"></div>",
                iconSize: [22, 22], iconAnchor: [11, 11]
            })
        }).addTo(map).bindPopup("Начало");

        endMarker = L.marker([last.lat, last.lng], {
            icon: L.divIcon({
                className: "seg-marker seg-marker--end",
                html: "<div class=\"seg-marker__dot\"></div>",
                iconSize: [22, 22], iconAnchor: [11, 11]
            })
        }).addTo(map).bindPopup("Конец");

        var dist = totalLength(slice);
        if (countEl)    countEl.textContent = slice.length;
        if (lengthEl)   lengthEl.textContent = formatMeters(dist);
        if (startLabel) startLabel.textContent = "Точка " + (startIdx + 1);
        if (endLabel)   endLabel.textContent   = "Точка " + (endIdx + 1);
        if (startValueEl) startValueEl.textContent = (startIdx + 1);
        if (endValueEl)   endValueEl.textContent   = (endIdx + 1);

        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(
                slice.map(function(p) { return { lat: +p.lat.toFixed(6), lng: +p.lng.toFixed(6) }; })
            );
        }

        renderElevation(slice);
    }

    function renderElevation(slice) {
        if (!elevationEl) return;
        var withEle = slice.filter(function(p) { return p.ele !== null && p.ele !== undefined; });
        if (withEle.length < 2) {
            elevationEl.innerHTML = "<div class=\"seg-elevation__empty\">Нет данных о высоте</div>";
            return;
        }

        var minEle = Infinity, maxEle = -Infinity;
        var dist = 0;
        var xs = [0], ys = [withEle[0].ele];

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
                "<defs><linearGradient id=\"segElevGrad\" x1=\"0\" y1=\"0\" x2=\"0\" y2=\"1\">" +
                    "<stop offset=\"0%\" stop-color=\"#ff5a1f\" stop-opacity=\".35\"/>" +
                    "<stop offset=\"100%\" stop-color=\"#ff5a1f\" stop-opacity=\".02\"/>" +
                "</linearGradient></defs>" +
                "<polygon points=\"" + area + "\" fill=\"url(#segElevGrad)\"/>" +
                "<polyline points=\"" + points + "\" fill=\"none\" stroke=\"#ff5a1f\" stroke-width=\"1.5\"/>" +
            "</svg>" +
            "<div class=\"seg-elevation__stats\">" +
                "<span>мин <strong>" + Math.round(minEle) + " м</strong></span>" +
                "<span>макс <strong>" + Math.round(maxEle) + " м</strong></span>" +
            "</div>";
    }

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
                if (this === startRange) endRange.value = String(s);
                else startRange.value = String(e);
            }
            applySegment(parseInt(startRange.value, 10), parseInt(endRange.value, 10));
        }

        startRange.addEventListener("input", syncFromRanges);
        endRange.addEventListener("input", syncFromRanges);

        // Инициализация: весь трек выделен
        applySegment(0, track.length - 1);
    }

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
        <h1 class="form-card__title">Редактировать сегмент</h1>
        <p class="form-card__subtitle">
            Меняйте название, тип, видимость и границы сегмента. После сохранения
            все усилия будут пересчитаны автоматически.
        </p>

        <?php if (!empty($errors['_general'])): ?>
            <div class="alert alert--error"><?= e($errors['_general']) ?></div>
        <?php endif; ?>

        <div id="seg-map" class="map" style="height: 460px; margin-bottom: 18px"></div>

        <?php if ($segmentTrack): ?>
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

            <div class="seg-elevation" id="seg-elevation"></div>

            <div id="track-info" class="seg-track-info">
                <span>📍 Точек: <strong id="track-count">0</strong></span>
                <span>📏 Длина: <strong id="track-length">0 м</strong></span>
                <button type="button" id="seg-full"  class="seg-track-reset">Весь трек</button>
                <button type="button" id="seg-reset" class="seg-track-reset">Сбросить</button>
            </div>
        <?php endif; ?>

        <form method="post" id="seg-form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="segment_id" value="<?= (int)$segmentId ?>">
            <input type="hidden" name="track_json" id="track_json" value="">

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
            </div>

            <div class="field field--checkbox">
                <label>
                    <input type="checkbox" name="is_public" <?= $old['is_public'] ? 'checked' : '' ?>>
                    Публичный сегмент (виден всем)
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary btn--large">Сохранить</button>
                <a href="<?= e(url('segment.php?id=' . $segmentId)) ?>" class="btn btn--ghost btn--large">Отмена</a>
            </div>
        </form>

        <div class="danger-zone">
            <h3 class="danger-zone__title">Опасная зона</h3>
            <p class="muted">
                Удаление необратимо. Все усилия и записи в лидерборде будут удалены.
            </p>
            <form method="post" action="<?= e(url('segment.php?id=' . $segmentId)) ?>"
                  onsubmit="return confirm('Удалить сегмент? Это необратимо.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="btn btn--danger">🗑 Удалить сегмент</button>
            </form>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
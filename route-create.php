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

$errors = [];
$old = [
    'name'        => '',
    'description' => '',
    'type'        => 'run',
    'is_public'   => 1,
];

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
            $clean[] = ['lat' => $lat, 'lng' => $lng];
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
    $waypoints = array_slice($waypoints, 0, 50);

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
                $notes = "\n\nТочки маршрута:\n";
                foreach ($cleanWaypoints as $i => $w) {
                    $notes .= ($i + 1) . '. ';
                    if ($w['name'] !== '') $notes .= $w['name'] . ' — ';
                    if ($w['note'] !== '') $notes .= $w['note'] . ' ';
                    $notes .= '(' . number_format($w['lat'], 5) . ', ' . number_format($w['lng'], 5) . ")\n";
                }
                $routeData['description'] = ($routeData['description'] ?? '') . $notes;
            }

            $routeId = Route::create((int)$me['id'], $routeData);

            flash('Маршрут «' . $old['name'] . '» сохранён', 'success');
            redirect(url('route.php?id=' . $routeId));
        } catch (Throwable $e) {
            $errors['_general'] = 'Не удалось сохранить: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Новый маршрут';

$extraCss = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
];

$extraJs = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
];

$inlineJs = <<<'JS'
(function () {
    if (typeof L === "undefined") {
        console.error("Leaflet не загрузился");
        return;
    }

    var map = L.map("route-map", {
        zoomControl: true,
        scrollWheelZoom: true
    }).setView([55.751244, 37.618423], 11);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap"
    }).addTo(map);

    // ---- Состояние ----
    var controlPoints = [];
    var routeLine = null;
    var controlMarkers = [];
    var noteMarkers = [];
    var snappedTrack = [];

    var infoPointsEl = document.getElementById("info-points");
    var infoLengthEl = document.getElementById("info-length");
    var infoStatusEl = document.getElementById("info-status");
    var infoEl = document.getElementById("route-info");

    // ---- Haversine ----
    function haversine(a, b) {
        var R = 6371000;
        var dLat = (b.lat - a.lat) * Math.PI / 180;
        var dLng = (b.lng - a.lng) * Math.PI / 180;
        var lat1 = a.lat * Math.PI / 180;
        var lat2 = b.lat * Math.PI / 180;
        var h = Math.sin(dLat/2)**2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng/2)**2;
        return 2 * R * Math.asin(Math.sqrt(h));
    }

    function totalLength(pts) {
        var d = 0;
        for (var i = 1; i < pts.length; i++) d += haversine(pts[i-1], pts[i]);
        return d;
    }

    function fmtMeters(m) {
        if (m < 1000) return Math.round(m) + " м";
        return (m / 1000).toFixed(2) + " км";
    }

    function updateInfo() {
        if (!snappedTrack.length) {
            infoPointsEl.textContent = "0";
            infoLengthEl.textContent = "0 м";
            infoStatusEl.textContent = "Кликните по карте, чтобы добавить точку";
            return;
        }
        infoPointsEl.textContent = snappedTrack.length;
        infoLengthEl.textContent = fmtMeters(totalLength(snappedTrack));
        infoStatusEl.textContent = "Готово";
    }

    // ---- OSRM ----
    function fetchRoute() {
        if (controlPoints.length < 2) {
            if (routeLine) { map.removeLayer(routeLine); routeLine = null; }
            snappedTrack = controlPoints.slice();
            updateInfo();
            return;
        }

        infoStatusEl.textContent = "Прокладываем маршрут…";

        var coords = controlPoints.map(function (p) {
            return p.lng + "," + p.lat;
        }).join(";");

        var url = "https://router.project-osrm.org/route/v1/driving/" + coords +
                  "?overview=full&geometries=geojson&steps=false";

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.routes || !data.routes.length) {
                    throw new Error("Маршрут не найден");
                }
                var route = data.routes[0];
                var coordsArr = route.geometry.coordinates;

                snappedTrack = coordsArr.map(function (c) {
                    return { lat: +c[1].toFixed(6), lng: +c[0].toFixed(6) };
                });

                if (routeLine) map.removeLayer(routeLine);
                routeLine = L.polyline(
                    snappedTrack.map(function (p) { return [p.lat, p.lng]; }),
                    { color: "#ff5a1f", weight: 5, opacity: 0.95 }
                ).addTo(map);

                updateInfo();
            })
            .catch(function (err) {
                console.error("OSRM:", err);
                infoStatusEl.textContent = "Ошибка: " + err.message;
            });
    }

    // ---- Маркеры ----
    function redrawControlMarkers() {
        controlMarkers.forEach(function (m) { map.removeLayer(m); });
        controlMarkers = [];

        controlPoints.forEach(function (pt, idx) {
            var isStart = idx === 0;
            var isEnd   = idx === controlPoints.length - 1 && controlPoints.length > 1;
            var color   = isStart ? "#0a7a3a" : isEnd ? "#b3261e" : "#ff5a1f";

            var marker = L.circleMarker([pt.lat, pt.lng], {
                radius: 8, color: color, fillColor: color, fillOpacity: 1, weight: 2
            }).addTo(map);

            marker.bindTooltip("Точка " + (idx + 1), { direction: "top" });

            marker.on("click", function (e) {
                L.DomEvent.stopPropagation(e);
                if (!confirm("Удалить точку " + (idx + 1) + "?")) return;
                controlPoints.splice(idx, 1);
                redrawControlMarkers();
                fetchRoute();
            });

            controlMarkers.push(marker);
        });
    }

    // ---- Клик по карте ----
    map.on("click", function (e) {
        if (window.__ADDING_NOTE__) return;
        controlPoints.push({
            lat: +e.latlng.lat.toFixed(6),
            lng: +e.latlng.lng.toFixed(6)
        });
        redrawControlMarkers();
        fetchRoute();
    });

    // ---- Метки ----
    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function addNoteMarker(lat, lng, name, note) {
        var marker = L.marker([lat, lng], {
            icon: L.divIcon({
                className: "note-marker",
                html: "📍",
                iconSize: [24, 24],
                iconAnchor: [12, 22]
            })
        }).addTo(map);

        var popupHtml = "<strong>" + escapeHtml(name || "Точка") + "</strong>";
        if (note) popupHtml += "<br>" + escapeHtml(note);
        marker.bindPopup(popupHtml);

        marker._meta = { lat: lat, lng: lng, name: name || "", note: note || "" };
        noteMarkers.push(marker);
        return marker;
    }

    // ---- UI ----
    var addNoteBtn = document.getElementById("btn-add-note");
    var undoBtn = document.getElementById("btn-undo");
    var clearBtn = document.getElementById("btn-clear");
    var saveBtn = document.getElementById("btn-save");

    var noteModal = document.getElementById("note-modal");
    var noteForm = document.getElementById("note-form");
    var noteLatEl = document.getElementById("note-lat");
    var noteLngEl = document.getElementById("note-lng");
    var noteNameEl = document.getElementById("note-name");
    var noteTextEl = document.getElementById("note-text");

    var saveModal = document.getElementById("save-modal");
    var routeForm = document.getElementById("route-form");
    var trackJsonEl = document.getElementById("track_json");
    var waypointsJsonEl = document.getElementById("waypoints_json");

    var pendingNote = null;

    addNoteBtn.addEventListener("click", function () {
        if (window.__ADDING_NOTE__) {
            window.__ADDING_NOTE__ = false;
            addNoteBtn.classList.remove("is-active");
            addNoteBtn.textContent = "📍 Метка";
            map.getContainer().style.cursor = "";
            return;
        }
        window.__ADDING_NOTE__ = true;
        addNoteBtn.classList.add("is-active");
        addNoteBtn.textContent = "✕ Отменить";
        map.getContainer().style.cursor = "crosshair";
        infoStatusEl.textContent = "Кликните по карте, чтобы поставить метку";
    });

    map.on("click", function (e) {
        if (!window.__ADDING_NOTE__) return;

        pendingNote = {
            lat: +e.latlng.lat.toFixed(6),
            lng: +e.latlng.lng.toFixed(6)
        };

        noteLatEl.value = pendingNote.lat;
        noteLngEl.value = pendingNote.lng;
        noteNameEl.value = "";
        noteTextEl.value = "";

        noteModal.hidden = false;
        noteNameEl.focus();

        window.__ADDING_NOTE__ = false;
        addNoteBtn.classList.remove("is-active");
        addNoteBtn.textContent = "📍 Метка";
        map.getContainer().style.cursor = "";
    });

    document.getElementById("note-save").addEventListener("click", function (ev) {
        ev.preventDefault();
        if (!pendingNote) return;

        var name = noteNameEl.value.trim();
        var note = noteTextEl.value.trim();
        if (!name && !note) {
            alert("Введите название или описание");
            return;
        }

        addNoteMarker(pendingNote.lat, pendingNote.lng, name, note);
        pendingNote = null;
        noteModal.hidden = true;
        serializeWaypoints();
        infoStatusEl.textContent = "Метка добавлена";
    });

    document.getElementById("note-cancel").addEventListener("click", function (ev) {
        ev.preventDefault();
        pendingNote = null;
        noteModal.hidden = true;
    });

    undoBtn.addEventListener("click", function () {
        if (!controlPoints.length) return;
        controlPoints.pop();
        redrawControlMarkers();
        fetchRoute();
    });

    clearBtn.addEventListener("click", function () {
        if (!confirm("Удалить все точки и метки?")) return;
        controlPoints = [];
        redrawControlMarkers();

        if (routeLine) { map.removeLayer(routeLine); routeLine = null; }
        snappedTrack = [];

        noteMarkers.forEach(function (m) { map.removeLayer(m); });
        noteMarkers = [];

        updateInfo();
        serializeWaypoints();
    });

    function serializeWaypoints() {
        var arr = noteMarkers.map(function (m) {
            return {
                lat:  m._meta.lat,
                lng:  m._meta.lng,
                name: m._meta.name,
                note: m._meta.note
            };
        });
        waypointsJsonEl.value = JSON.stringify(arr);
    }

    // ---- Модальное окно сохранения ----
    saveBtn.addEventListener("click", function () {
        if (snappedTrack.length < 2) {
            alert("Проложите маршрут — нужно минимум 2 точки");
            return;
        }
        trackJsonEl.value = JSON.stringify(snappedTrack);
        serializeWaypoints();
        saveModal.hidden = false;
        document.getElementById("route-name").focus();
    });

    document.getElementById("save-cancel").addEventListener("click", function (ev) {
        ev.preventDefault();
        saveModal.hidden = true;
    });

    // Закрытие по клику на фон
    [noteModal, saveModal].forEach(function (modal) {
        modal.addEventListener("click", function (e) {
            if (e.target === modal) modal.hidden = true;
        });
    });

    // Escape закрывает
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        if (!noteModal.hidden) noteModal.hidden = true;
        if (!saveModal.hidden) saveModal.hidden = true;
    });

    // ---- Начальное состояние ----
    updateInfo();
})();
JS;

require __DIR__ . '/includes/header.php';
?>

<!-- ============================================================
     КАРТА НА ВЕСЬ ЭКРАН
     ============================================================ -->
<div class="route-map-full" id="route-map"></div>

<!-- ============================================================
     ЛЕВАЯ ИНФО-ПАНЕЛЬ
     ============================================================ -->
<div class="route-hud" id="route-info">
    <div class="route-hud__row">
        <span class="route-hud__label">Точек трека</span>
        <span class="route-hud__value" id="info-points">0</span>
    </div>
    <div class="route-hud__row">
        <span class="route-hud__label">Длина</span>
        <span class="route-hud__value" id="info-length">0 м</span>
    </div>
    <div class="route-hud__status" id="info-status">Кликните по карте, чтобы добавить точку</div>
</div>

<!-- ============================================================
     НИЖНЯЯ ПАНЕЛЬ УПРАВЛЕНИЯ
     ============================================================ -->
<div class="route-bottom-bar">
    <button type="button" class="route-btn route-btn--ghost" id="btn-undo">
        ↩ Отменить точку
    </button>
    <button type="button" class="route-btn route-btn--ghost" id="btn-add-note">
        📍 Метка
    </button>
    <button type="button" class="route-btn route-btn--danger" id="btn-clear">
        🗑 Очистить
    </button>
    <div class="route-bottom-bar__spacer"></div>
    <button type="button" class="route-btn route-btn--primary" id="btn-save">
        💾 Сохранить маршрут
    </button>
</div>

<!-- ============================================================
     МОДАЛЬНОЕ ОКНО МЕТКИ
     ============================================================ -->
<div class="route-modal" id="note-modal" hidden>
    <div class="route-modal__box">
        <h3 class="route-modal__title">Новая метка</h3>
        <input type="hidden" id="note-lat">
        <input type="hidden" id="note-lng">
        <div class="field">
            <label for="note-name">Название</label>
            <input type="text" id="note-name" maxlength="120" placeholder="Например: Питьевая вода">
        </div>
        <div class="field">
            <label for="note-text">Описание</label>
            <textarea id="note-text" rows="3" maxlength="500" placeholder="Что здесь важного?"></textarea>
        </div>
        <div class="route-modal__actions">
            <button type="button" class="route-btn route-btn--ghost" id="note-cancel">Отмена</button>
            <button type="button" class="route-btn route-btn--primary" id="note-save">Добавить</button>
        </div>
    </div>
</div>

<!-- ============================================================
     МОДАЛЬНОЕ ОКНО СОХРАНЕНИЯ
     ============================================================ -->
<div class="route-modal" id="save-modal" hidden>
    <div class="route-modal__box route-modal__box--wide">
        <h3 class="route-modal__title">Сохранить маршрут</h3>

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

<?php require __DIR__ . '/includes/footer.php'; ?>
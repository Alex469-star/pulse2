(function () {
    "use strict";

    if (typeof L === "undefined") {
        console.error("Leaflet не загрузился");
        return;
    }

    var CFG = window.__ROUTE_EDITOR__ || {};
    var mapEl = document.getElementById("route-map");
    if (!mapEl) return;

    // ============================================================
    // СОСТОЯНИЕ
    // ============================================================
    var state = {
    points: [],
    snappedTrack: [],
    elevation: [],
    history: [],
    historyIndex: -1,
    savedPlaces: [],
    editingIndex: null,
    activePointIndex: null,
    elevChart: null,
    cursorMarker: null,
    lastElevations: [],
    lastLabels: [],
    lastTrackPoints: [],
    elevationCache: {},
    mode: CFG.mode || 'create',
    routeId: CFG.routeId || 0,
};

    var layers = {
        route: null,
        markers: [],
        tile: null,
    };

    // ============================================================
    // КАРТА
    // ============================================================
    var map = L.map("route-map", {
        zoomControl: false,
        scrollWheelZoom: true,
        attributionControl: true,
    }).setView(CFG.defaultCenter || [55.751244, 37.618423], 11);

    var TILES = {
        osm: {
            url: "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
            maxZoom: 19,
            attr: "&copy; OpenStreetMap",
        },
        carto: {
            url: "https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png",
            maxZoom: 20,
            attr: "&copy; OpenStreetMap &copy; CARTO",
        },
        humanitarian: {
            url: "https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png",
            maxZoom: 19,
            attr: "OSM contributors, HOT",
        },
        topo: {
            url: "https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png",
            maxZoom: 17,
            attr: "OpenTopoMap (CC-BY-SA)",
        },
        satellite: {
            url: "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
            maxZoom: 19,
            attr: "Tiles &copy; Esri",
        },
    };

    function setTileLayer(key) {
        if (layers.tile) map.removeLayer(layers.tile);
        var t = TILES[key] || TILES.osm;
        layers.tile = L.tileLayer(t.url, {
            maxZoom: t.maxZoom,
            attribution: t.attr,
        }).addTo(map);
    }
    setTileLayer("osm");

    // ============================================================
    // УТИЛИТЫ
    // ============================================================
    function haversine(a, b) {
        var R = 6371000;
        var dLat = (b.lat - a.lat) * Math.PI / 180;
        var dLng = (b.lng - a.lng) * Math.PI / 180;
        var lat1 = a.lat * Math.PI / 180;
        var lat2 = b.lat * Math.PI / 180;
        var h = Math.sin(dLat / 2) ** 2
              + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
        return 2 * R * Math.asin(Math.sqrt(h));
    }

    function totalLength(pts) {
        var d = 0;
        for (var i = 1; i < pts.length; i++) d += haversine(pts[i - 1], pts[i]);
        return d;
    }

    function fmtMeters(m) {
        if (m < 1000) return Math.round(m) + " м";
        return (m / 1000).toFixed(2) + " км";
    }

    function esc(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function bind(id, fn) {
        var el = document.getElementById(id);
        if (el) el.addEventListener("click", fn);
    }

    // ============================================================
    // OSRM
    // ============================================================
    function currentOsrmProfile() {
        var typeSel = document.getElementById("route-type");
        var type = typeSel ? typeSel.value : "run";
        if (type === "ride") return "bike";
        if (type === "run" || type === "walk" || type === "hike") return "foot";
        return "bike";
    }

    function fetchRoute() {
        if (state.points.length < 2) {
            if (layers.route) {
                map.removeLayer(layers.route);
                layers.route = null;
            }
            state.snappedTrack = state.points.slice();
            updateInfo();
            updateDownloadButton();
            updateElevationDebounced();
            return;
        }

        setStatus("Прокладываем маршрут…");

        var coords = state.points.map(function (p) {
            return p.lng + "," + p.lat;
        }).join(";");

        var profile = currentOsrmProfile();
        var url = CFG.osrmBase + "/route/v1/" + profile + "/" + coords +
                  "?overview=full&geometries=geojson&steps=false";

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.routes || !data.routes.length) {
                    throw new Error("Маршрут не найден");
                }
                var route = data.routes[0];
                state.snappedTrack = route.geometry.coordinates.map(function (c) {
                    return { lat: +c[1].toFixed(6), lng: +c[0].toFixed(6) };
                });

                if (layers.route) map.removeLayer(layers.route);
                layers.route = L.polyline(
                    state.snappedTrack.map(function (p) { return [p.lat, p.lng]; }),
                    { color: "#0070c0", weight: 6, opacity: 0.9, lineCap: "round" }
                ).addTo(map);

                layers.route.on("click", function (e) {
                    L.DomEvent.stopPropagation(e);
                    var idx = findClosestSegmentIndex(e.latlng);
                    state.points.splice(idx, 0, {
                        lat: +e.latlng.lat.toFixed(6),
                        lng: +e.latlng.lng.toFixed(6),
                    });
                    snapshot();
                    redrawMarkers();
                    fetchRoute();
                });

                updateInfo();
                updateDownloadButton();
                updateElevationDebounced();
                setStatus("Маршрут готов");
            })
            .catch(function (err) {
                console.error("OSRM:", err);
                setStatus("Ошибка маршрута: " + err.message);
            });
    }

    function findClosestSegmentIndex(latlng) {
        if (state.points.length < 2) return state.points.length;
        var best = 1, min = Infinity;
        for (var i = 0; i < state.points.length - 1; i++) {
            var d = haversine({ lat: latlng.lat, lng: latlng.lng }, state.points[i]);
            if (d < min) { min = d; best = i + 1; }
        }
        return best;
    }

    // ============================================================
    // МАРКЕРЫ ТОЧЕК
    // ============================================================
    function makeMarkerIcon(idx, total, hasNote) {
        var isStart = idx === 0;
        var isEnd = idx === total - 1 && total > 1;
        var cls = isStart ? "is-start" : isEnd ? "is-end" : "is-via";
        if (hasNote) cls += " has-note";
        var letter = isStart ? "A" : isEnd ? "B" : String(idx);

        return L.divIcon({
            className: "route-marker-icon " + cls,
            html: "<span>" + letter + "</span>",
            iconSize: [30, 38],
            iconAnchor: [15, 38],
        });
    }

    function redrawMarkers() {
        layers.markers.forEach(function (m) { map.removeLayer(m); });
        layers.markers = [];
        var total = state.points.length;

        state.points.forEach(function (pt, idx) {
            var hasNote = !!(pt.name || pt.note);
            var marker = L.marker([pt.lat, pt.lng], {
                icon: makeMarkerIcon(idx, total, hasNote),
                draggable: true,
                zIndexOffset: (idx === 0 || idx === total - 1) ? 1000 : 500,
            }).addTo(map);

            marker.on("drag", function (e) {
                if (!layers.route || state.points.length < 2) return;
                var preview = state.points.slice();
                var ll = e.target.getLatLng();
                preview[idx] = Object.assign({}, state.points[idx], { lat: ll.lat, lng: ll.lng });
                layers.route.setLatLngs(preview.map(function (p) { return [p.lat, p.lng]; }));
            });

            marker.on("dragend", function (e) {
                var ll = e.target.getLatLng();
                state.points[idx].lat = +ll.lat.toFixed(6);
                state.points[idx].lng = +ll.lng.toFixed(6);
                snapshot();
                fetchRoute();
            });

            marker.on("click", function (e) {
                L.DomEvent.stopPropagation(e);
                openPointMenu(idx);
            });

            layers.markers.push(marker);
        });

        renderWaypointsList();
        updateUndoButtons();
    }

    // ============================================================
    // СПИСОК ТОЧЕК
    // ============================================================
    function renderWaypointsList() {
        var el = document.getElementById("route-waypoints");
        if (!el) return;

        if (!state.points.length) {
            el.innerHTML = '<div class="route-waypoints__empty">Нажмите на карту, чтобы начать</div>';
            return;
        }

        var html = "";
        var total = state.points.length;

        state.points.forEach(function (p, idx) {
            var isStart = idx === 0;
            var isEnd = idx === total - 1 && total > 1;
            var badge = isStart ? "A" : isEnd ? "B" : String(idx);
            var badgeCls = isStart ? "is-start" : isEnd ? "is-end" : "is-via";
            var name = p.name || (isStart ? "Старт" : isEnd ? "Финиш" : "Точка " + idx);
            if (p.note) name += " · " + p.note.slice(0, 40);

            html += '<div class="route-waypoint" data-idx="' + idx + '">';
            html += '<span class="route-waypoint__badge ' + badgeCls + '">' + badge + '</span>';
            html += '<span class="route-waypoint__name">' + esc(name) + '</span>';
            html += '<button type="button" class="route-waypoint__edit" data-edit="' + idx + '" title="Редактировать">✏️</button>';
            html += '<button type="button" class="route-waypoint__remove" data-remove="' + idx + '" title="Удалить">×</button>';
            html += '</div>';
        });

        el.innerHTML = html;

        el.querySelectorAll("[data-edit]").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                openPointEdit(parseInt(btn.dataset.edit, 10));
            });
        });

        el.querySelectorAll("[data-remove]").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                removePoint(parseInt(btn.dataset.remove, 10));
            });
        });

        el.querySelectorAll(".route-waypoint").forEach(function (row) {
            row.addEventListener("click", function (e) {
                if (e.target.closest("button")) return;
                var idx = parseInt(row.dataset.idx, 10);
                var p = state.points[idx];
                if (p) map.setView([p.lat, p.lng], Math.max(map.getZoom(), 14));
            });
        });
    }

    function removePoint(idx) {
        if (idx < 0 || idx >= state.points.length) return;
        state.points.splice(idx, 1);
        snapshot();
        redrawMarkers();
        fetchRoute();
    }

    // ============================================================
    // КЛИК ПО КАРТЕ — ДОБАВИТЬ ТОЧКУ
    // ============================================================
    map.on("click", function (e) {
        state.points.push({
            lat: +e.latlng.lat.toFixed(6),
            lng: +e.latlng.lng.toFixed(6),
        });
        snapshot();
        redrawMarkers();
        fetchRoute();
    });

    // ============================================================
    // МЕНЮ ТОЧКИ
    // ============================================================
    var pointMenuModal = document.getElementById("point-menu-modal");
    var pointMenuTitle = document.getElementById("point-menu-title");
    var pointMenuCoords = document.getElementById("point-menu-coords");

    function openPointMenu(idx) {
        state.activePointIndex = idx;
        var p = state.points[idx];
        if (!p) return;

        if (pointMenuTitle) {
            var isStart = idx === 0;
            var isEnd = idx === state.points.length - 1;
            pointMenuTitle.textContent = p.name || (isStart ? "Старт" : isEnd ? "Финиш" : "Точка " + idx);
        }
        if (pointMenuCoords) {
            pointMenuCoords.textContent = p.lat.toFixed(6) + ", " + p.lng.toFixed(6);
        }
        if (pointMenuModal) pointMenuModal.hidden = false;
    }

    function closePointMenu() {
        if (pointMenuModal) pointMenuModal.hidden = true;
        state.activePointIndex = null;
    }

    bind("point-close", closePointMenu);

    bind("point-edit", function () {
        var idx = state.activePointIndex;
        closePointMenu();
        if (idx !== null) openPointEdit(idx);
    });

    bind("point-delete", function () {
        var idx = state.activePointIndex;
        closePointMenu();
        if (idx === null) return;
        if (!confirm("Удалить эту точку?")) return;
        removePoint(idx);
    });

    // ============================================================
    // РЕДАКТИРОВАНИЕ ТОЧКИ
    // ============================================================
    var pointEditModal = document.getElementById("point-edit-modal");
    var pointNameEl = document.getElementById("point-name");
    var pointNoteEl = document.getElementById("point-note");
    var pointEditTitle = document.getElementById("point-edit-title");

    function openPointEdit(idx) {
        state.editingIndex = idx;
        var p = state.points[idx];
        if (!p) return;

        if (pointNameEl) pointNameEl.value = p.name || "";
        if (pointNoteEl) pointNoteEl.value = p.note || "";
        if (pointEditTitle) {
            var isStart = idx === 0;
            var isEnd = idx === state.points.length - 1;
            pointEditTitle.textContent = isStart ? "Старт" : isEnd ? "Финиш" : "Точка " + idx;
        }
        if (pointEditModal) pointEditModal.hidden = false;
        if (pointNameEl) pointNameEl.focus();
    }

    bind("point-edit-cancel", function () {
        if (pointEditModal) pointEditModal.hidden = true;
        state.editingIndex = null;
    });

    bind("point-edit-save", function () {
        var idx = state.editingIndex;
        if (idx === null) return;
        state.points[idx].name = (pointNameEl.value || "").trim();
        state.points[idx].note = (pointNoteEl.value || "").trim();
        if (pointEditModal) pointEditModal.hidden = true;
        state.editingIndex = null;
        redrawMarkers();
    });

    // ============================================================
    // ПРОИЗВОЛЬНАЯ ТОЧКА
    // ============================================================
    var customModal = document.getElementById("custom-point-modal");
    var cpLat = document.getElementById("cp-lat");
    var cpLng = document.getElementById("cp-lng");
    var cpName = document.getElementById("cp-name");
    var cpNote = document.getElementById("cp-note");

    bind("btn-add-custom", function () {
        var c = map.getCenter();
        if (cpLat) cpLat.value = c.lat.toFixed(6);
        if (cpLng) cpLng.value = c.lng.toFixed(6);
        if (cpName) cpName.value = "";
        if (cpNote) cpNote.value = "";
        if (customModal) customModal.hidden = false;
        if (cpName) cpName.focus();
    });

    bind("cp-use-center", function () {
        var c = map.getCenter();
        if (cpLat) cpLat.value = c.lat.toFixed(6);
        if (cpLng) cpLng.value = c.lng.toFixed(6);
    });

    bind("cp-cancel", function () {
        if (customModal) customModal.hidden = true;
    });

    bind("cp-save", function () {
        var lat = parseFloat(cpLat.value);
        var lng = parseFloat(cpLng.value);
        if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            alert("Введите корректные координаты");
            return;
        }
        state.points.push({
            lat: +lat.toFixed(6),
            lng: +lng.toFixed(6),
            name: (cpName.value || "").trim(),
            note: (cpNote.value || "").trim(),
            isCustom: true,
        });
        snapshot();
        redrawMarkers();
        fetchRoute();
        if (customModal) customModal.hidden = true;
    });

    // ============================================================
    // ИНФО
    // ============================================================
    function updateInfo() {
        var pts = state.snappedTrack.length ? state.snappedTrack : state.points;
        var ptsEl = document.getElementById("info-points");
        var lenEl = document.getElementById("info-length");

        if (ptsEl) ptsEl.textContent = state.points.length;
        if (lenEl) lenEl.textContent = pts.length >= 2 ? fmtMeters(totalLength(pts)) : "0 м";

        var saveBtn = document.getElementById("btn-save");
        if (saveBtn) saveBtn.disabled = pts.length < 2;

        updateInfoElevation();
    }

    function updateInfoElevation() {
        var el = document.getElementById("info-elev");
        if (!el) return;

        if (!state.lastElevations || state.lastElevations.length < 2) {
            el.textContent = "—";
            return;
        }

        var gain = 0;
        for (var i = 1; i < state.lastElevations.length; i++) {
            var diff = state.lastElevations[i] - state.lastElevations[i - 1];
            if (diff > 0) gain += diff;
        }
        el.textContent = Math.round(gain) + " м";
    }

    function updateDownloadButton() {
        var btn = document.getElementById("btn-download-gpx");
        if (btn) btn.disabled = state.snappedTrack.length < 2;
    }

    function setStatus(text) {
        var el = document.getElementById("info-status");
        if (el) el.textContent = text;
    }

    // ============================================================
    // ИСТОРИЯ
    // ============================================================
    function snapshot() {
        state.history = state.history.slice(0, state.historyIndex + 1);
        state.history.push(JSON.parse(JSON.stringify(state.points)));
        state.historyIndex = state.history.length - 1;
        updateUndoButtons();
        autosave();
    }

    function undo() {
        if (state.historyIndex <= 0) return;
        state.historyIndex--;
        state.points = JSON.parse(JSON.stringify(state.history[state.historyIndex]));
        redrawMarkers();
        fetchRoute();
    }

    function redo() {
        if (state.historyIndex >= state.history.length - 1) return;
        state.historyIndex++;
        state.points = JSON.parse(JSON.stringify(state.history[state.historyIndex]));
        redrawMarkers();
        fetchRoute();
    }

    function updateUndoButtons() {
        var u = document.getElementById("btn-undo");
        var r = document.getElementById("btn-redo");
        if (u) u.disabled = state.historyIndex <= 0;
        if (r) r.disabled = state.historyIndex >= state.history.length - 1;
    }

    // ============================================================
    // АВТОСОХРАНЕНИЕ
    // ============================================================
    function autosave() {
    if (state.mode === 'edit') return; // в редакторе не пересекаемся с новым маршрутом
    var pref = document.getElementById("pref-autosave");
    if (pref && !pref.checked) return;
    try {
        localStorage.setItem("pulse_route_draft", JSON.stringify({
            points: state.points,
            at: Date.now(),
        }));
    } catch (e) {}
}

    function loadDraft() {
        try {
            var raw = localStorage.getItem("pulse_route_draft");
            if (!raw) return false;
            var draft = JSON.parse(raw);
            if (!draft || !Array.isArray(draft.points) || !draft.points.length) return false;
            if (Date.now() - (draft.at || 0) > 7 * 24 * 3600 * 1000) return false;
            if (!confirm("Найден несохранённый маршрут. Восстановить?")) return false;
            state.points = draft.points;
            return true;
        } catch (e) { return false; }
    }

        // ============================================================
    // ПРОФИЛЬ ВЫСОТ — через собственный API (api/elevation.php)
    // ============================================================
    var elevationTimer = null;
    var elevationReqId = 0;

    // URL вашего API. Можно задать в CFG, но подставим дефолт.
    var ELEVATION_API_URL = CFG.elevationApi || (CFG.baseUrl ? CFG.baseUrl + '/api/elevation.php' : '/api/elevation.php');

    function updateElevationDebounced() {
        if (elevationTimer) clearTimeout(elevationTimer);
        elevationTimer = setTimeout(function () {
            updateElevation();
        }, 800);
    }

    /**
     * Запрашивает высоты через наш серверный прокси.
     * Сервер сам разобьёт точки на чанки по 100 и обратится к Open-Elevation.
     * @param {Array<{lat:number,lng:number}>} points
     * @returns {Promise<Array<{lat:number,lng:number,ele:number}>>}
     */
    async function fetchElevations(points) {
        if (!points || points.length < 2) return points;

        // Отправляем только lat/lng
        var payload = {
            points: points.map(function (p) {
                return { lat: p.lat, lng: p.lng };
            })
        };

        var response = await fetch(ELEVATION_API_URL, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-CSRF-Token": CFG.csrf || "",
            },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            throw new Error("HTTP " + response.status);
        }

        var data = await response.json();

        // Ваш API возвращает { ok: true, data: { points: [...] } }
        // или { ok: true, points: [...] } — уточните под свой формат json_ok()
        var resultPoints = null;
        if (data && data.ok) {
            if (data.data && Array.isArray(data.data.points)) {
                resultPoints = data.data.points;
            } else if (Array.isArray(data.points)) {
                resultPoints = data.points;
            }
        }

        if (!resultPoints || !resultPoints.length) {
            throw new Error((data && data.error) || "Пустой ответ от сервиса высот");
        }

        return resultPoints;
    }

    function updateElevation() {
        var pref = document.getElementById("pref-show-elev");
        var panel = document.getElementById("elevation-panel");

        if (pref && !pref.checked) {
            if (panel) panel.hidden = true;
            return;
        }

        var pts = state.snappedTrack.length >= 2 ? state.snappedTrack : state.points;
        if (pts.length < 2) {
            if (panel) panel.hidden = true;
            return;
        }

        // Если у всех точек уже есть высота — просто рисуем
        var hasAllEle = pts.every(function (p) {
            return typeof p.ele === "number" && !isNaN(p.ele) && p.ele !== 0;
        });
        if (hasAllEle) {
            renderElevationChart(pts);
            return;
        }

        // Прореживаем до 100 точек (лимит Open-Elevation за один запрос)
        var step = Math.max(1, Math.ceil(pts.length / 100));
        var sampled = [];
        for (var i = 0; i < pts.length; i += step) {
            sampled.push(pts[i]);
        }
        if (sampled[sampled.length - 1] !== pts[pts.length - 1]) {
            sampled.push(pts[pts.length - 1]);
        }
        if (sampled.length > 100) {
            sampled = sampled.slice(0, 100);
        }

        if (panel) panel.hidden = false;
        var statsEl = document.getElementById("elev-stats");
        if (statsEl) statsEl.innerHTML = "<span>Загружаем высоты…</span>";

        var myReqId = ++elevationReqId;

        fetchElevations(sampled)
            .then(function (withEle) {
                if (myReqId !== elevationReqId) return; // Устаревший запрос

                var nonZero = withEle.filter(function (p) {
                    return typeof p.ele === "number" && p.ele !== 0;
                }).length;
                if (nonZero < 2) {
                    throw new Error("Сервис высот вернул нулевые значения");
                }

                // Записываем высоты обратно в targetArray
                var targetArray = state.snappedTrack.length >= 2 ? state.snappedTrack : state.points;
                var sampleIdx = 0;
                for (var i = 0; i < targetArray.length && sampleIdx < sampled.length; i++) {
                    if (targetArray[i] === sampled[sampleIdx]) {
                        if (withEle[sampleIdx] && typeof withEle[sampleIdx].ele === "number") {
                            targetArray[i].ele = withEle[sampleIdx].ele;
                        }
                        sampleIdx++;
                    }
                }

                renderElevationChart(withEle);
                setStatus("Маршрут готов");
            })
            .catch(function (e) {
                if (myReqId !== elevationReqId) return;
                console.error("Elevation:", e);
                if (statsEl) {
                    statsEl.innerHTML = "<span style='color:#b3261e'>Не удалось загрузить высоты: " + esc(e.message) + "</span>";
                }
                setStatus("Высоты недоступны");
            });
    }

    function renderElevationChart(pts) {
        var panel = document.getElementById("elevation-panel");
        if (panel) panel.hidden = false;

        var canvas = document.getElementById("elev-chart");
        if (!canvas) return;

        if (typeof Chart === "undefined") {
            console.error("Chart.js не загружен");
            var statsEl = document.getElementById("elev-stats");
            if (statsEl) statsEl.innerHTML = "<span style='color:#b3261e'>Chart.js не загрузился</span>";
            return;
        }

        var dist = 0;
        var labels = [];
        var elevations = [];
        var trackPoints = [];
        var minE = Infinity, maxE = -Infinity;

        for (var i = 0; i < pts.length; i++) {
            if (i > 0) dist += haversine(pts[i - 1], pts[i]);
            if (typeof pts[i].ele === "number") {
                labels.push(+(dist / 1000).toFixed(2));
                elevations.push(pts[i].ele);
                trackPoints.push({ lat: pts[i].lat, lng: pts[i].lng, km: dist / 1000 });
                if (pts[i].ele < minE) minE = pts[i].ele;
                if (pts[i].ele > maxE) maxE = pts[i].ele;
            }
        }

        if (elevations.length < 2) {
            if (panel) panel.hidden = true;
            return;
        }

        var gain = 0;
        for (var j = 1; j < elevations.length; j++) {
            var diff = elevations[j] - elevations[j - 1];
            if (diff > 0) gain += diff;
        }

        var statsEl = document.getElementById("elev-stats");
        if (statsEl) {
            statsEl.innerHTML =
                "<span>мин <strong>" + Math.round(minE) + " м</strong></span>" +
                "<span>макс <strong>" + Math.round(maxE) + " м</strong></span>" +
                "<span>набор <strong>" + Math.round(gain) + " м</strong></span>";
        }

        state.lastElevations = elevations;
        state.lastLabels = labels;
        state.lastTrackPoints = trackPoints;
        updateInfoElevation();

        if (state.elevChart) {
            try { state.elevChart.destroy(); } catch (e) {}
            state.elevChart = null;
        }

        var ctx = canvas.getContext("2d");
        var grad = ctx.createLinearGradient(0, 0, 0, 200);
        grad.addColorStop(0, "rgba(0, 112, 192, .40)");
        grad.addColorStop(1, "rgba(0, 112, 192, .02)");

        state.elevChart = new Chart(ctx, {
            type: "line",
            data: {
                labels: labels,
                datasets: [{
                    label: "Высота",
                    data: elevations,
                    borderColor: "#0070c0",
                    backgroundColor: grad,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 0,
                    fill: true,
                    tension: 0.25,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            maxTicksLimit: 6,
                            callback: function (v) {
                                return this.getLabelForValue(v) + " км";
                            },
                        },
                    },
                    y: {
                        grid: { color: "rgba(15,20,32,.06)" },
                        ticks: {
                            maxTicksLimit: 5,
                            callback: function (v) { return v + " м"; },
                        },
                    },
                },
            },
        });

        attachElevationCursor(canvas);
    }

    // ============================================================
    // КУРСОР НА ПРОФИЛЕ — БЕГАЮЩИЙ МАРКЕР ПО КАРТЕ
    // ============================================================
    function attachElevationCursor(canvas) {
        if (!state.cursorMarker) {
            state.cursorMarker = L.marker([0, 0], {
                icon: L.divIcon({
                    className: "route-cursor-marker",
                    iconSize: [16, 16],
                    iconAnchor: [8, 8],
                }),
                interactive: false,
                zIndexOffset: 2000,
            });
        }

        function handleMove(e) {
            if (!state.elevChart || !state.lastTrackPoints || !state.lastTrackPoints.length) return;

            var rect = canvas.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var chartArea = state.elevChart.chartArea;
            if (!chartArea) return;

            var ratio = Math.max(0, Math.min(1, (x - chartArea.left) / (chartArea.right - chartArea.left)));
            var idx = Math.round(ratio * (state.lastTrackPoints.length - 1));
            var pt = state.lastTrackPoints[idx];
            if (!pt) return;

            state.cursorMarker.setLatLng([pt.lat, pt.lng]);
            if (!map.hasLayer(state.cursorMarker)) {
                state.cursorMarker.addTo(map);
            }
        }

        function handleLeave() {
            if (state.cursorMarker && map.hasLayer(state.cursorMarker)) {
                map.removeLayer(state.cursorMarker);
            }
        }

        canvas.addEventListener("mousemove", handleMove);
        canvas.addEventListener("mouseleave", handleLeave);
    }

    // ============================================================
    // ОЧИСТКА КАРТЫ
    // ============================================================
    bind("btn-clear-all", function () {
        if (!state.points.length && !state.snappedTrack.length) {
            setStatus("Нечего очищать");
            return;
        }
        if (!confirm("Удалить все точки и маршрут?")) return;

        state.points = [];
        state.snappedTrack = [];
        state.elevation = [];
        state.lastElevations = [];
        state.lastLabels = [];
        state.lastTrackPoints = [];

        if (layers.route) {
            map.removeLayer(layers.route);
            layers.route = null;
        }
        layers.markers.forEach(function (m) { map.removeLayer(m); });
        layers.markers = [];

        if (state.cursorMarker && map.hasLayer(state.cursorMarker)) {
            map.removeLayer(state.cursorMarker);
        }

        if (state.elevChart) {
            try { state.elevChart.destroy(); } catch (e) {}
            state.elevChart = null;
        }
        var panel = document.getElementById("elevation-panel");
        if (panel) panel.hidden = true;

        localStorage.removeItem("pulse_route_draft");

        snapshot();
        updateInfo();
        updateDownloadButton();
        renderWaypointsList();
        setStatus("Карта очищена");
    });

    // ============================================================
    // ТУЛБАР
    // ============================================================
    bind("btn-undo", undo);
    bind("btn-redo", redo);

    bind("btn-reverse", function () {
        if (state.points.length < 2) return;
        state.points.reverse();
        snapshot();
        redrawMarkers();
        fetchRoute();
    });

    bind("btn-fit", function () {
        if (!state.points.length) return;
        var bounds = L.latLngBounds(state.points.map(function (p) { return [p.lat, p.lng]; }));
        map.fitBounds(bounds, { padding: [80, 80] });
    });

    bind("btn-map-content", function () {
        var m = document.getElementById("layers-menu");
        if (m) m.hidden = !m.hidden;
    });

    document.querySelectorAll('input[name="tile-layer"]').forEach(function (radio) {
        radio.addEventListener("change", function () {
            if (radio.checked) setTileLayer(radio.value);
        });
    });

    bind("btn-preferences", function () {
        var m = document.getElementById("prefs-menu");
        if (m) m.hidden = !m.hidden;
    });

    var prefElev = document.getElementById("pref-show-elev");
    if (prefElev) {
        prefElev.addEventListener("change", function () {
            updateElevationDebounced();
        });
    }

    var typeSel = document.getElementById("route-type");
    if (typeSel) {
        typeSel.addEventListener("change", function () {
            if (state.points.length >= 2) fetchRoute();
        });
    }

    // ============================================================
    // 3D / ZOOM / COMPASS
    // ============================================================
    bind("btn-3d", function () {
        document.body.classList.toggle("route-3d");
        this.classList.toggle("is-active", document.body.classList.contains("route-3d"));
    });

    bind("btn-zoom-in", function () { map.zoomIn(); });
    bind("btn-zoom-out", function () { map.zoomOut(); });
    bind("btn-compass", function () {
        map.setView(CFG.defaultCenter || [55.751244, 37.618423], map.getZoom());
    });

    // ============================================================
    // ГОРЯЧИЕ КЛАВИШИ
    // ============================================================
    document.addEventListener("keydown", function (e) {
        if (e.target.tagName === "INPUT" || e.target.tagName === "TEXTAREA") return;

        if ((e.ctrlKey || e.metaKey) && e.key === "z" && !e.shiftKey) {
            e.preventDefault();
            undo();
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === "y" || (e.key === "z" && e.shiftKey))) {
            e.preventDefault();
            redo();
        }

        if (e.key === "Escape") {
            ["save-modal", "saved-places-modal", "point-menu-modal", "point-edit-modal", "custom-point-modal"].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.hidden = true;
            });
            ["layers-menu", "prefs-menu"].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.hidden = true;
            });
        }
    });

    // ============================================================
    // ПОИСК NOMINATIM
    // ============================================================
    var searchInput = document.getElementById("search-input");
    var searchResults = document.getElementById("search-results");
    var searchTimer = null;

    if (searchInput) {
        searchInput.addEventListener("input", function () {
            var q = searchInput.value.trim();
            if (searchTimer) clearTimeout(searchTimer);
            if (q.length < 3) {
                if (searchResults) searchResults.hidden = true;
                return;
            }
            searchTimer = setTimeout(function () { doSearch(q); }, 400);
        });
    }

    function doSearch(q) {
        var url = CFG.nominatim + "/search?format=json&limit=6&q=" + encodeURIComponent(q);
        fetch(url, { headers: { "Accept": "application/json" } })
            .then(function (r) { return r.json(); })
            .then(function (list) {
                if (!Array.isArray(list) || !list.length) {
                    if (searchResults) searchResults.hidden = true;
                    return;
                }
                var html = "";
                list.forEach(function (item) {
                    html += '<button type="button" class="route-search-result" data-lat="' + item.lat + '" data-lng="' + item.lon + '">' + esc(item.display_name) + '</button>';
                });
                searchResults.innerHTML = html;
                searchResults.hidden = false;

                searchResults.querySelectorAll(".route-search-result").forEach(function (btn) {
                    btn.addEventListener("click", function () {
                        var lat = parseFloat(btn.dataset.lat);
                        var lng = parseFloat(btn.dataset.lng);
                        if (!isNaN(lat) && !isNaN(lng)) map.setView([lat, lng], 15);
                        searchResults.hidden = true;
                        searchInput.value = "";
                    });
                });
            })
            .catch(function (e) { console.error("Nominatim:", e); });
    }

    // ============================================================
    // БЫСТРЫЕ ДЕЙСТВИЯ
    // ============================================================
    bind("btn-suggestion-location", function () {
        if (!navigator.geolocation) {
            alert("Геолокация недоступна");
            return;
        }
        setStatus("Определяем местоположение…");
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                map.setView([pos.coords.latitude, pos.coords.longitude], 15);
                setStatus("Готово");
            },
            function () {
                setStatus("Не удалось определить местоположение");
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });

    // ============================================================
    // СОХРАНЁННЫЕ МЕСТА
    // ============================================================
    var savedModal = document.getElementById("saved-places-modal");
    var savedList = document.getElementById("saved-places-list");
    var savedCount = document.getElementById("saved-count");
    var savedForm = document.getElementById("saved-place-form");
    var spName = document.getElementById("sp-name");
    var spLat = document.getElementById("sp-lat");
    var spLng = document.getElementById("sp-lng");

    function loadSavedPlaces() {
        if (!CFG.savedPlacesApi) return;
        fetch(CFG.savedPlacesApi, { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) return;
                state.savedPlaces = res.data.places || [];
                if (savedCount) savedCount.textContent = state.savedPlaces.length;
                renderSavedPlaces();
            })
            .catch(function (e) { console.error("saved places:", e); });
    }

    function renderSavedPlaces() {
        if (!savedList) return;
        if (!state.savedPlaces.length) {
            savedList.innerHTML = '<div class="saved-places-empty">Пока нет сохранённых мест</div>';
            return;
        }
        var html = "";
        state.savedPlaces.forEach(function (p) {
            html += '<div class="saved-place" data-id="' + p.id + '">';
            html += '<span class="saved-place__icon">🔖</span>';
            html += '<span class="saved-place__name">' + esc(p.name) + '</span>';
            html += '<span class="saved-place__coords">' + p.lat.toFixed(4) + ', ' + p.lng.toFixed(4) + '</span>';
            html += '<button type="button" class="saved-place__use" data-use="' + p.id + '">Открыть</button>';
            html += '<button type="button" class="saved-place__del" data-del="' + p.id + '" title="Удалить">×</button>';
            html += '</div>';
        });
        savedList.innerHTML = html;

        savedList.querySelectorAll("[data-use]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var id = parseInt(btn.dataset.use, 10);
                var p = state.savedPlaces.find(function (x) { return x.id === id; });
                if (p) {
                    map.setView([p.lat, p.lng], 15);
                    if (savedModal) savedModal.hidden = true;
                }
            });
        });

        savedList.querySelectorAll("[data-del]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var id = parseInt(btn.dataset.del, 10);
                if (!confirm("Удалить это место?")) return;
                fetch(CFG.savedPlacesApi, {
                    method: "DELETE",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": CFG.csrf,
                    },
                    body: JSON.stringify({ id: id, csrf: CFG.csrf }),
                })
                .then(function (r) { return r.json(); })
                .then(function () { loadSavedPlaces(); })
                .catch(function () {});
            });
        });
    }

    bind("btn-suggestion-saved", function () {
        if (savedModal) savedModal.hidden = false;
        loadSavedPlaces();
    });

    bind("sp-cancel", function () {
        if (savedModal) savedModal.hidden = true;
    });

    bind("sp-use-current", function () {
        var c = map.getCenter();
        if (spLat) spLat.value = c.lat.toFixed(6);
        if (spLng) spLng.value = c.lng.toFixed(6);
        if (spName) spName.focus();
    });

    if (savedForm) {
        savedForm.addEventListener("submit", function (e) {
            e.preventDefault();
            var c = map.getCenter();
            var lat = spLat && spLat.value ? parseFloat(spLat.value) : c.lat;
            var lng = spLng && spLng.value ? parseFloat(spLng.value) : c.lng;
            var name = spName ? spName.value.trim() : "";
            if (!name) {
                alert("Введите название");
                return;
            }

            fetch(CFG.savedPlacesApi, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": CFG.csrf,
                },
                body: JSON.stringify({ name: name, lat: lat, lng: lng, csrf: CFG.csrf }),
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) {
                    alert((res && res.error) || "Ошибка");
                    return;
                }
                spName.value = "";
                spLat.value = "";
                spLng.value = "";
                loadSavedPlaces();
            })
            .catch(function () { alert("Не удалось сохранить"); });
        });
    }

    // ============================================================
    // ЭКСПОРТ GPX
    // ============================================================
    bind("btn-download-gpx", function () {
        if (state.snappedTrack.length < 2) {
            alert("Нет трека для скачивания");
            return;
        }

        var name = (document.getElementById("route-name") && document.getElementById("route-name").value) || "Маршрут";

        var gpx = '<?xml version="1.0" encoding="UTF-8"?>' + "\n";
        gpx += '<gpx version="1.1" creator="Pulse" xmlns="http://www.topografix.com/GPX/1/1">' + "\n";
        gpx += '  <metadata><name>' + escapeXml(name) + '</name><time>' + new Date().toISOString() + '</time></metadata>' + "\n";
        gpx += '  <trk><name>' + escapeXml(name) + '</name><trkseg>' + "\n";

        state.snappedTrack.forEach(function (p) {
            gpx += '    <trkpt lat="' + p.lat + '" lon="' + p.lng + '">';
            if (typeof p.ele === "number") gpx += '<ele>' + p.ele.toFixed(1) + '</ele>';
            gpx += '</trkpt>' + "\n";
        });

        gpx += '  </trkseg></trk>' + "\n</gpx>";

        var blob = new Blob([gpx], { type: "application/gpx+xml" });
        var url = URL.createObjectURL(blob);
        var a = document.createElement("a");
        a.href = url;
        a.download = slugify(name) + ".gpx";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        setStatus("GPX скачан");
    });

    function escapeXml(s) {
        return String(s).replace(/[<>&'"]/g, function (c) {
            return { "<": "&lt;", ">": "&gt;", "&": "&amp;", "'": "&apos;", '"': "&quot;" }[c];
        });
    }

    function slugify(s) {
        return String(s).toLowerCase()
            .replace(/[^a-z0-9а-яё]+/gi, "-")
            .replace(/^-+|-+$/g, "")
            .slice(0, 60) || "route";
    }

    // ============================================================
    // ИМПОРТ GPX
    // ============================================================
    var importInput = document.getElementById("gpx-import-input");

    bind("btn-import-gpx", function () {
        if (importInput) importInput.click();
    });

    if (importInput) {
        importInput.addEventListener("change", function () {
            var file = importInput.files && importInput.files[0];
            if (!file) return;

            var reader = new FileReader();
            reader.onload = function (ev) {
                try {
                    var text = ev.target.result;
                    var parser = new DOMParser();
                    var xml = parser.parseFromString(text, "application/xml");
                    if (xml.querySelector("parsererror")) throw new Error("Некорректный GPX");

                    var nodes = xml.querySelectorAll("trkpt, rtept");
                    if (!nodes.length) throw new Error("В файле нет точек трека");

                    var all = [];
                    nodes.forEach(function (n) {
                        var lat = parseFloat(n.getAttribute("lat"));
                        var lng = parseFloat(n.getAttribute("lon"));
                        if (isNaN(lat) || isNaN(lng)) return;
                        var eleNode = n.querySelector("ele");
                        var ele = eleNode ? parseFloat(eleNode.textContent) : null;
                        var row = { lat: +lat.toFixed(6), lng: +lng.toFixed(6) };
                        if (ele !== null && !isNaN(ele)) row.ele = +ele.toFixed(1);
                        all.push(row);
                    });

                    if (all.length < 2) throw new Error("Слишком мало точек");

                    var maxPts = 200;
                    var step = all.length > maxPts ? Math.ceil(all.length / maxPts) : 1;
                    var picked = [];
                    for (var i = 0; i < all.length; i += step) picked.push(all[i]);
                    if (picked[picked.length - 1].lat !== all[all.length - 1].lat) {
                        picked.push(all[all.length - 1]);
                    }

                    if (state.points.length && !confirm("Заменить текущий маршрут импортированным?")) {
                        importInput.value = "";
                        return;
                    }

                    state.points = picked;
                    snapshot();
                    redrawMarkers();

                    state.snappedTrack = state.points.slice();
                    if (layers.route) map.removeLayer(layers.route);
                    layers.route = L.polyline(
                        state.snappedTrack.map(function (p) { return [p.lat, p.lng]; }),
                        { color: "#0070c0", weight: 6, opacity: 0.9, lineCap: "round" }
                    ).addTo(map);

                    var bounds = L.latLngBounds(state.points.map(function (p) { return [p.lat, p.lng]; }));
                    map.fitBounds(bounds, { padding: [80, 80] });

                    updateInfo();
                    updateDownloadButton();
                    updateElevationDebounced();
                    setStatus("Импортировано точек: " + picked.length);
                    importInput.value = "";
                } catch (err) {
                    alert("Не удалось прочитать GPX: " + err.message);
                    importInput.value = "";
                }
            };
            reader.readAsText(file);
        });
    }

    // ============================================================
    // WAYPOINTS JSON
    // ============================================================
    function serializeWaypoints() {
        var el = document.getElementById("waypoints_json");
        if (!el) return;
        var arr = state.points
            .filter(function (p) { return p.name || p.note; })
            .map(function (p) {
                return {
                    lat: p.lat,
                    lng: p.lng,
                    name: p.name || "",
                    note: p.note || "",
                };
            });
        el.value = JSON.stringify(arr);
    }

    // ============================================================
    // СОХРАНЕНИЕ
    // ============================================================
    var saveModal = document.getElementById("save-modal");

    bind("btn-save", function () {
        if (state.snappedTrack.length < 2) {
            alert("Проложите маршрут");
            return;
        }
        var trackEl = document.getElementById("track_json");
        if (trackEl) trackEl.value = JSON.stringify(state.snappedTrack);
        serializeWaypoints();
        if (saveModal) saveModal.hidden = false;
        var nameEl = document.getElementById("route-name");
        if (nameEl) nameEl.focus();
    });

    bind("save-cancel", function () {
        if (saveModal) saveModal.hidden = true;
    });

    document.querySelectorAll(".route-modal").forEach(function (modal) {
        modal.addEventListener("click", function (e) {
            if (e.target === modal) modal.hidden = true;
        });
    });

        // ============================================================
    // ЗАГРУЗКА СУЩЕСТВУЮЩЕГО МАРШРУТА (режим редактирования)
    // ============================================================
    function loadInitialRoute() {
        var data = CFG.initialData;
        if (!data || !Array.isArray(data.points) || data.points.length < 2) {
            return false;
        }

        // 1. Точки для редактора (старт/финиш + waypoints)
        state.points = data.points.map(function (p) {
            return {
                lat: parseFloat(p.lat),
                lng: parseFloat(p.lng),
                name: p.name || '',
                note: p.note || '',
            };
        });

        // 2. Готовый трек от OSRM не нужен — рисуем оригинальный
        if (Array.isArray(data.track) && data.track.length >= 2) {
            state.snappedTrack = data.track.map(function (p) {
                var row = { lat: parseFloat(p.lat), lng: parseFloat(p.lng) };
                if (typeof p.ele === 'number') row.ele = p.ele;
                return row;
            });
        }

        // 3. Рисуем полилинию
        if (state.snappedTrack.length >= 2) {
            if (layers.route) map.removeLayer(layers.route);
            layers.route = L.polyline(
                state.snappedTrack.map(function (p) { return [p.lat, p.lng]; }),
                { color: "#0070c0", weight: 6, opacity: 0.9, lineCap: "round" }
            ).addTo(map);

            // Клик по линии — добавить точку в ближайший сегмент
            layers.route.on("click", function (e) {
                L.DomEvent.stopPropagation(e);
                var idx = findClosestSegmentIndex(e.latlng);
                state.points.splice(idx, 0, {
                    lat: +e.latlng.lat.toFixed(6),
                    lng: +e.latlng.lng.toFixed(6),
                });
                snapshot();
                redrawMarkers();
                fetchRoute();
            });
        }

        // 4. Маркеры точек
        redrawMarkers();

        // 5. Подгоняем зум под весь маршрут
        if (state.points.length) {
            var bounds = L.latLngBounds(
                state.points.map(function (p) { return [p.lat, p.lng]; })
            );
            map.fitBounds(bounds, { padding: [80, 80] });
        }

        // 6. Обновляем инфо
        updateInfo();
        updateDownloadButton();
        updateElevationDebounced();
        setStatus("Маршрут загружен для редактирования");

        return true;
    }

    // ============================================================
    // СТАРТ
    // ============================================================

    if (state.mode === 'edit' && CFG.initialData) {
        // Режим редактирования: загружаем существующий маршрут,
        // черновик из localStorage игнорируем
        loadInitialRoute();
    } else {
        // Режим создания: пробуем восстановить черновик
        if (loadDraft()) {
            redrawMarkers();
            fetchRoute();
        } else {
            updateInfo();
        }
    }

    updateUndoButtons();
    loadSavedPlaces();
    snapshot();
})();
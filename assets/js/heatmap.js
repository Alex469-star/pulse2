(function () {
    "use strict";

    var API_URL       = window.__HEATMAP_API__;
    var SEG_API_URL   = window.__HEATMAP_SEG_API__;
    var MODE          = window.__HEATMAP_MODE__ || "global";
    var TYPE          = window.__HEATMAP_TYPE__ || "";
    var MY_ID         = window.__HEATMAP_ME__ || 0;
    var USER_COORDS   = window.__HEATMAP_USER_COORDS__ || null;

    if (!API_URL) return;

    var mapEl = document.getElementById("heatmap-map");
    if (!mapEl) return;

    if (typeof L === "undefined") {
        console.error("Leaflet не загружен");
        return;
    }
    if (typeof L.heatLayer !== "function") {
        console.error("leaflet.heat не загружен");
        return;
    }

    // ============================================================
    // КАРТА
    // ============================================================

    // Центр по умолчанию — Москва
    var startCenter = [55.7558, 37.6173];
    var startZoom   = 11;

    // Если известен город пользователя — центрируем на нём
    if (USER_COORDS && typeof USER_COORDS.lat === "number") {
        startCenter = [USER_COORDS.lat, USER_COORDS.lng];
        startZoom   = 12;
    }

    var map = L.map("heatmap-map", {
        scrollWheelZoom: true,
        zoomControl: true
    }).setView(startCenter, startZoom);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    // ============================================================
    // СЛОЙ ТЕПЛА
    // ============================================================

    var heatLayer = L.heatLayer([], {
        radius: 22,
        blur: 18,
        maxZoom: 17,
        max: 1.0,
        minOpacity: 0.05,
        gradient: {
            0.2: "#1e3a8a",
            0.4: "#0ea5e9",
            0.6: "#22c55e",
            0.8: "#eab308",
            1.0: "#ef4444"
        }
    }).addTo(map);

    // ============================================================
    // СЛОЙ СЕГМЕНТОВ
    // ============================================================

    var segmentsLayer = L.layerGroup().addTo(map);
    var segmentsEnabled = true;
    var lastSegmentsKey = "";
    var segmentsLoading = false;

    function segmentColor(type) {
        return {
            run:  "#e94f2e",
            ride: "#2e7de9",
            swim: "#2ec4e9",
            ski:  "#5e9ee9",
            walk: "#68b96b",
            hike: "#8a6a3a",
            other: "#8a8a8f"
        }[type] || "#8a8a8f";
    }

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function formatDistance(m) {
        m = Number(m) || 0;
        if (m < 1000) return Math.round(m) + " м";
        return (m / 1000).toFixed(2).replace(".", ",") + " км";
    }

    function loadSegments() {
        if (!segmentsEnabled) return;
        if (segmentsLoading) return;
        if (!SEG_API_URL) return;

        var b = map.getBounds();

        // Не грузим, если bbox слишком большой (совпадает с ограничением API)
        if ((b.getNorth() - b.getSouth()) > 3.0 || (b.getEast() - b.getWest()) > 3.0) {
            segmentsLayer.clearLayers();
            return;
        }

        var params = new URLSearchParams({
            min_lat: b.getSouth().toFixed(6),
            min_lng: b.getWest().toFixed(6),
            max_lat: b.getNorth().toFixed(6),
            max_lng: b.getEast().toFixed(6),
        });

        if (TYPE) params.set("type", TYPE);

        var key = params.toString();
        if (key === lastSegmentsKey) return;
        lastSegmentsKey = key;

        segmentsLoading = true;

        fetch(SEG_API_URL + "?" + params.toString(), {
            credentials: "same-origin",
            headers: { "Accept": "application/json" }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.ok) return;

            segmentsLayer.clearLayers();

            (res.data.segments || []).forEach(function (seg) {
                if (!seg.track || seg.track.length < 2) return;

                var color = segmentColor(seg.type);

                var line = L.polyline(seg.track, {
                    color: color,
                    weight: 4,
                    opacity: 0.75,
                    lineJoin: "round"
                });

                line.on("mouseover", function () {
                    this.setStyle({ weight: 6, opacity: 1 });
                });
                line.on("mouseout", function () {
                    this.setStyle({ weight: 4, opacity: 0.75 });
                });
                line.on("click", function () {
                    window.location.href = seg.url;
                });

                var tooltipHtml =
                    "<strong>" + escapeHtml(seg.name) + "</strong><br>" +
                    formatDistance(seg.distance_m) +
                    (seg.elevation ? " · ↑" + Math.round(seg.elevation) + " м" : "");

                line.bindTooltip(tooltipHtml, { sticky: true });

                line.addTo(segmentsLayer);
            });
        })
        .catch(function (e) {
            console.error("segments load:", e);
        })
        .finally(function () {
            segmentsLoading = false;
        });
    }

    // ============================================================
    // ЗАГРУЗКА HEATMAP
    // ============================================================

    var currentBbox = null;
    var timer = null;
    var loading = false;

    function sameBox(a, b) {
        return a[0] === b[0] && a[1] === b[1] && a[2] === b[2] && a[3] === b[3];
    }

    function loadHeat() {
        if (loading) return;

        var b = map.getBounds();
        var bbox = [
            b.getSouth().toFixed(6),
            b.getWest().toFixed(6),
            b.getNorth().toFixed(6),
            b.getEast().toFixed(6)
        ];

        if (currentBbox && sameBox(currentBbox, bbox)) return;
        currentBbox = bbox;

        if ((b.getNorth() - b.getSouth()) > 2.0) {
            heatLayer.setLatLngs([]);
            return;
        }

        loading = true;
        var url = API_URL +
            "?min_lat=" + bbox[0] +
            "&min_lng=" + bbox[1] +
            "&max_lat=" + bbox[2] +
            "&max_lng=" + bbox[3] +
            "&mode=" + encodeURIComponent(MODE) +
            "&type=" + encodeURIComponent(TYPE);

        fetch(url, { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) return;
                heatLayer.setLatLngs(res.data.points || []);
            })
            .catch(function (e) { console.error("heatmap load:", e); })
            .finally(function () { loading = false; });
    }

    function schedule() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () {
            loadHeat();
            loadSegments();
        }, 400);
    }

    map.on("moveend", schedule);
    map.on("zoomend", schedule);

    // Первичная загрузка
    setTimeout(function () {
        loadHeat();
        loadSegments();
    }, 300);

    // ============================================================
    // ПЕРЕКЛЮЧЕНИЕ РЕЖИМА (глобальная / личная)
    // ============================================================

    document.querySelectorAll(".js-heat-mode").forEach(function (btn) {
        btn.addEventListener("click", function (e) {
            e.preventDefault();
            var mode = btn.dataset.mode;

            document.querySelectorAll(".js-heat-mode").forEach(function (b) {
                b.classList.toggle("is-active", b.dataset.mode === mode);
            });

            MODE = mode;
            currentBbox = null;
            loadHeat();
        });
    });

    // ============================================================
    // ПЕРЕКЛЮЧЕНИЕ ТИПА АКТИВНОСТИ
    // ============================================================

    document.querySelectorAll(".js-heat-type").forEach(function (btn) {
        btn.addEventListener("click", function (e) {
            e.preventDefault();
            var type = btn.dataset.type || "";

            document.querySelectorAll(".js-heat-type").forEach(function (b) {
                b.classList.toggle("is-active", (b.dataset.type || "") === type);
            });

            TYPE = type;
            window.__HEATMAP_TYPE__ = type;

            currentBbox = null;
            lastSegmentsKey = "";

            loadHeat();
            loadSegments();
        });
    });

    // ============================================================
    // ПЕРЕКЛЮЧАТЕЛЬ СЕГМЕНТОВ
    // ============================================================

    var segBtn = document.querySelector(".js-segments-toggle");
    if (segBtn) {
        segBtn.addEventListener("click", function (e) {
            e.preventDefault();
            segmentsEnabled = !segmentsEnabled;
            segBtn.classList.toggle("is-active", segmentsEnabled);
            segBtn.textContent = segmentsEnabled ? "Показать" : "Скрыть";

            if (segmentsEnabled) {
                lastSegmentsKey = "";
                loadSegments();
            } else {
                segmentsLayer.clearLayers();
            }
        });
    }

    // ============================================================
    // КНОПКА «МОЁ МЕСТОПОЛОЖЕНИЕ»
    // ============================================================

    var locateBtn = document.querySelector(".js-heat-locate");
    var myMarker = null;

    if (locateBtn) {
        locateBtn.addEventListener("click", function (e) {
            e.preventDefault();

            if (!navigator.geolocation) {
                alert("Геолокация не поддерживается вашим браузером");
                return;
            }

            locateBtn.disabled = true;
            locateBtn.textContent = "📍 Определяю…";

            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    var lat = pos.coords.latitude;
                    var lng = pos.coords.longitude;

                    map.setView([lat, lng], 13);

                    if (myMarker) {
                        myMarker.setLatLng([lat, lng]);
                    } else {
                        myMarker = L.circleMarker([lat, lng], {
                            radius: 8,
                            color: "#e94f2e",
                            fillColor: "#e94f2e",
                            fillOpacity: 1,
                            weight: 2
                        }).addTo(map);
                    }

                    myMarker.bindPopup("Вы здесь").openPopup();

                    locateBtn.disabled = false;
                    locateBtn.textContent = "📍 Моё местоположение";
                },
                function (err) {
                    var msg = "Не удалось определить местоположение";
                    if (err.code === 1) msg = "Разрешение на геолокацию отклонено";
                    else if (err.code === 2) msg = "Информация о местоположении недоступна";
                    else if (err.code === 3) msg = "Время ожидания истекло";

                    alert(msg);
                    locateBtn.disabled = false;
                    locateBtn.textContent = "📍 Моё местоположение";
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
            );
        });
    }

    // ============================================================
    // АВТООБНОВЛЕНИЕ ЛИЧНОЙ КАРТЫ
    // ============================================================

    if (MODE === "personal") {
        setInterval(function () {
            currentBbox = null;
            loadHeat();
        }, 60000);
    }
})();
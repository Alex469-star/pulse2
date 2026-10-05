(function () {
    "use strict";

    var API_URL = window.__HEATMAP_API__;
    var MODE = window.__HEATMAP_MODE__ || "global";
    var TYPE = window.__HEATMAP_TYPE__ || "";
    var MY_ID = window.__HEATMAP_ME__ || 0;

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

    // --- Карта ---
    var map = L.map("heatmap-map", {
        scrollWheelZoom: true,
        zoomControl: true
    }).setView([55.7558, 37.6173], 11);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

    // --- Слой тепла ---
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

    // --- Загрузка по bbox ---
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
        timer = setTimeout(loadHeat, 400);
    }

    map.on("moveend", schedule);
    map.on("zoomend", schedule);
    setTimeout(loadHeat, 300);

    // --- Переключение режима (глобальная / личная) ---
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

    // --- Переключение типа активности ---
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
            loadHeat();
        });
    });

    // Автообновление личной карты раз в минуту
    if (MODE === "personal") {
        setInterval(function () {
            currentBbox = null;
            loadHeat();
        }, 60000);
    }
})();
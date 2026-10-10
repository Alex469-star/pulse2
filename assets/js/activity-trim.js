(function () {
    "use strict";

    var modal = document.getElementById("trim-modal");
    if (!modal) return;

    var CFG = window.__ACTIVITY_TRIM__;
    if (!CFG) return;

    var track = (window.__ACTIVITY_TRACK__ || []).slice();
    if (track.length < 2) return;

    var total = track.length - 1;

    // ---- DOM ----
    var startInput = document.getElementById("trim-start");
    var endInput   = document.getElementById("trim-end");
    var fillEl     = document.getElementById("trim-fill");
    var distEl     = document.getElementById("trim-dist");
    var timeEl     = document.getElementById("trim-time");
    var speedEl    = document.getElementById("trim-speed");
    var elevEl     = document.getElementById("trim-elev");
    var startLabel = document.getElementById("trim-start-label");
    var endLabel   = document.getElementById("trim-end-label");
    var saveBtn    = document.getElementById("trim-save");

    // ---- Карта ----
    var map = L.map("trim-map", { scrollWheelZoom: true, attributionControl: false })
        .setView([track[0].lat, track[0].lng], 13);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
    }).addTo(map);

    var allLatLngs = track.map(function (p) { return [p.lat, p.lng]; });

    var fullLine = L.polyline(allLatLngs, {
        color: "#c9cdd6", weight: 4, opacity: 0.7
    }).addTo(map);

    var activeLine = L.polyline([], {
        color: "#ff5a1f", weight: 6, opacity: 0.95, lineCap: "round"
    }).addTo(map);

    var startMarker = L.circleMarker(allLatLngs[0], {
        radius: 8, color: "#0a7a3a", fillColor: "#0a7a3a", fillOpacity: 1, weight: 2
    }).addTo(map);

    var endMarker = L.circleMarker(allLatLngs[total], {
        radius: 8, color: "#b3261e", fillColor: "#b3261e", fillOpacity: 1, weight: 2
    }).addTo(map);

    // ---- График высоты ----
    var chart = null;
    var hasElev = track.some(function (p) { return typeof p.ele === "number"; });

    function buildChart() {
        if (typeof Chart === "undefined") return;
        var canvas = document.getElementById("trim-chart");
        if (!canvas) return;

        var dist = 0;
        var labels = [];
        var values = [];
        var prev = null;

        for (var i = 0; i < track.length; i++) {
            var p = track[i];
            if (prev) dist += haversine(prev, p);
            labels.push(+(dist / 1000).toFixed(3));
            values.push(hasElev && typeof p.ele === "number" ? p.ele : 0);
            prev = p;
        }

        var ctx = canvas.getContext("2d");
        var grad = ctx.createLinearGradient(0, 0, 0, 120);
        grad.addColorStop(0, "rgba(255,90,31,.35)");
        grad.addColorStop(1, "rgba(255,90,31,.02)");

        chart = new Chart(ctx, {
            type: "line",
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    borderColor: "#ff5a1f",
                    backgroundColor: grad,
                    borderWidth: 1.5,
                    pointRadius: 0,
                    fill: true,
                    tension: 0.25,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: {
                    x: { display: false },
                    y: { display: false }
                }
            }
        });
    }

    // ---- Утилиты ----
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

    function pad2(n) { return n < 10 ? "0" + n : String(n); }

    function formatDistance(m) {
        if (m < 1000) return Math.round(m) + " м";
        return (m / 1000).toFixed(2).replace(".", ",") + " км";
    }

    function formatDuration(sec) {
        sec = Math.max(0, Math.floor(sec));
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        return h > 0 ? h + ":" + pad2(m) + ":" + pad2(s) : m + ":" + pad2(s);
    }

    function formatSpeed(kmh) {
        return kmh > 0 ? kmh.toFixed(1) + " км/ч" : "—";
    }

    // ---- Обновление ----
    function update() {
        var s = parseInt(startInput.value, 10);
        var e = parseInt(endInput.value, 10);

        if (s >= e) {
            e = s + 1;
            endInput.value = e;
        }

        var slice = allLatLngs.slice(s, e + 1);
        activeLine.setLatLngs(slice);

        startMarker.setLatLng(allLatLngs[s]);
        endMarker.setLatLng(allLatLngs[e]);

        var p1 = total > 0 ? (s / total) * 100 : 0;
        var p2 = total > 0 ? (e / total) * 100 : 100;
        fillEl.style.left = p1 + "%";
        fillEl.style.width = (p2 - p1) + "%";

        // Статистика
        var dist = 0;
        var elevGain = 0;
        for (var i = s + 1; i <= e; i++) {
            dist += haversine(track[i - 1], track[i]);
            if (typeof track[i].ele === "number" && typeof track[i - 1].ele === "number") {
                var d = track[i].ele - track[i - 1].ele;
                if (d > 0) elevGain += d;
            }
        }

        var dur = 0;
        var t0 = track[s] && track[s].t ? track[s].t : 0;
        var t1 = track[e] && track[e].t ? track[e].t : 0;
        if (t0 > 0 && t1 > t0) dur = t1 - t0;

        var avgSpeed = (dur > 0 && dist > 0) ? (dist / dur) * 3.6 : 0;

        distEl.textContent  = formatDistance(dist);
        timeEl.textContent  = formatDuration(dur);
        speedEl.textContent = formatSpeed(avgSpeed);
        elevEl.textContent  = elevGain > 0 ? Math.round(elevGain) + " м" : "—";

        startLabel.textContent = "точка " + (s + 1) + " из " + track.length;
        endLabel.textContent   = "точка " + (e + 1) + " из " + track.length;

        // Подсветка графика
        if (chart) {
            chart.data.datasets[0].segment = {
                borderColor: function (ctx) {
                    var idx = ctx.p0DataIndex;
                    if (idx >= s && idx < e) return "#ff5a1f";
                    return "rgba(201, 205, 214, 0.4)";
                }
            };
            chart.update("none");
        }
    }

    // ---- Открытие / закрытие ----
    function open() {
        modal.hidden = false;
        document.body.style.overflow = "hidden";

        setTimeout(function () {
            map.invalidateSize();
            if (activeLine.getBounds().isValid()) {
                map.fitBounds(activeLine.getBounds(), { padding: [40, 40] });
            }
            update();
        }, 50);
    }

    function close() {
        modal.hidden = true;
        document.body.style.overflow = "";
    }

    // ---- Сохранение ----
    function save() {
        var s = parseInt(startInput.value, 10);
        var e = parseInt(endInput.value, 10);

        if (e - s < 1) {
            alert("Слишком узкий диапазон");
            return;
        }

        if (!confirm("Обрезать трек? Это необратимо.")) return;

        saveBtn.disabled = true;
        saveBtn.textContent = "Сохранение…";

        fetch(CFG.apiUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": CFG.csrf,
                "Accept": "application/json",
            },
            body: JSON.stringify({
                activity_id: CFG.activityId,
                start_index: s,
                end_index: e,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.ok) {
                alert((res && res.error) || "Ошибка сохранения");
                saveBtn.disabled = false;
                saveBtn.textContent = "💾 Сохранить";
                return;
            }
            window.location.reload();
        })
        .catch(function () {
            alert("Ошибка сети");
            saveBtn.disabled = false;
            saveBtn.textContent = "💾 Сохранить";
        });
    }

    // ---- Обработчики ----
    document.addEventListener("click", function (e) {
        if (e.target.closest(".js-trim-open")) { e.preventDefault(); open(); return; }
        if (e.target.closest("[data-trim-close]")) { e.preventDefault(); close(); return; }
    });

    document.addEventListener("keydown", function (e) {
        if (!modal.hidden && e.key === "Escape") close();
    });

    if (startInput) startInput.addEventListener("input", update);
    if (endInput)   endInput.addEventListener("input", update);
    if (saveBtn)    saveBtn.addEventListener("click", save);

    buildChart();
    update();
})();
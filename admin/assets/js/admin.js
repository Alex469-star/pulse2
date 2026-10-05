(function () {
    "use strict";

    // Вставка сохранённого SQL в textarea
    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-insert-sql");
        if (!btn) return;
        e.preventDefault();
        var ta = document.querySelector('textarea[name="sql"]');
        if (!ta) return;
        ta.value = btn.dataset.sql || "";
        ta.focus();
    });

    // Графики на дашборде
    function buildCharts() {
        if (typeof Chart === "undefined") return;
        var data = window.__ADMIN_CHARTS__ || {};

        function lineChart(id, points, label, color) {
            var canvas = document.getElementById(id);
            if (!canvas || !points || !points.length) return;
            var labels = points.map(function (p) { return p.d; });
            var values = points.map(function (p) { return p.cnt; });
            new Chart(canvas, {
                type: "line",
                data: {
                    labels: labels,
                    datasets: [{
                        label: label,
                        data: values,
                        borderColor: color,
                        backgroundColor: color.replace(")", ",.15)").replace("rgb", "rgba"),
                        borderWidth: 2,
                        pointRadius: 0,
                        fill: true,
                        tension: .3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                        y: { beginAtZero: true, grid: { color: "rgba(0,0,0,.05)" } },
                    }
                }
            });
        }

        lineChart("chart-activities", data.activities, "Активности", "rgb(233,79,46)");
        lineChart("chart-users", data.users, "Новые пользователи", "rgb(31,95,196)");
    }

    // Статистика: подгружаем и строим
    function buildStats() {
        var url = window.__ADMIN_STATS__;
        if (!url || typeof Chart === "undefined") return;

        fetch(url + "?type=all", { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) return;
                var d = res.data;

                drawLine("stat-reg", (d.registrations || []).map(x => x.d), (d.registrations || []).map(x => x.cnt), "rgb(31,95,196)");
                drawLine("stat-act", (d.activities || []).map(x => x.d), (d.activities || []).map(x => x.cnt), "rgb(233,79,46)");

                drawDoughnut("stat-types",
                    (d.types || []).map(x => x.type),
                    (d.types || []).map(x => x.cnt));

                drawBar("stat-dow",
                    ["Вс","Пн","Вт","Ср","Чт","Пт","Сб"],
                    (d.dow || []).map(x => x.cnt));

                drawBar("stat-hour",
                    (d.hour || []).map(x => x.h + "ч"),
                    (d.hour || []).map(x => x.cnt));

                drawHBar("stat-top",
                    (d.top || []).map(x => x.username),
                    (d.top || []).map(x => Math.round(x.dist / 1000)));

                drawBar("stat-weekly",
                    (d.weekly || []).map(x => "W" + x.w),
                    (d.weekly || []).map(x => Math.round(x.dist / 1000)));

                drawHBar("stat-cities",
                    (d.cities || []).map(x => x.city),
                    (d.cities || []).map(x => x.cnt));
            });
    }

    function drawLine(id, labels, data, color) {
        var c = document.getElementById(id);
        if (!c || !data.length) return;
        new Chart(c, {
            type: "line",
            data: { labels: labels, datasets: [{ data: data, borderColor: color, backgroundColor: color.replace("rgb", "rgba").replace(")", ",.15)"), fill: true, tension: .3, pointRadius: 0, borderWidth: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true } } }
        });
    }
    function drawBar(id, labels, data) {
        var c = document.getElementById(id);
        if (!c || !data.length) return;
        new Chart(c, {
            type: "bar",
            data: { labels: labels, datasets: [{ data: data, backgroundColor: "rgb(233,79,46)", borderRadius: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true } } }
        });
    }
    function drawHBar(id, labels, data) {
        var c = document.getElementById(id);
        if (!c || !data.length) return;
        new Chart(c, {
            type: "bar",
            data: { labels: labels, datasets: [{ data: data, backgroundColor: "rgb(31,95,196)", borderRadius: 6 }] },
            options: { indexAxis: "y", responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true }, y: { grid: { display: false } } } }
        });
    }
    function drawDoughnut(id, labels, data) {
        var c = document.getElementById(id);
        if (!c || !data.length) return;
        new Chart(c, {
            type: "doughnut",
            data: { labels: labels, datasets: [{ data: data, backgroundColor: ["#e94f2e","#1f5fc4","#ffb020","#0a7a3a","#7c3aed","#e11d48","#14b8a6"] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "right" } } }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () { buildCharts(); buildStats(); });
    } else {
        buildCharts(); buildStats();
    }
})();
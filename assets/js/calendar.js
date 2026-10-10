(function () {
    "use strict";

    var CFG = window.__CALENDAR__;
    if (!CFG) return;

    var state = {
        year:  CFG.year,
        month: CFG.month,
        loading: false,
        cache: {}
    };

    var MONTHS_RU = ['Январь','Февраль','Март','Апрель','Май','Июнь',
                     'Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    var WEEKDAYS_RU = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

    var scrollLockY = 0;

    function esc(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/\x27/g, "&#039;");
    }

    function pad2(n) { return n < 10 ? "0" + n : String(n); }

    function formatDistance(m) {
        var km = Number(m) / 1000;
        if (km < 1) return Math.round(Number(m)) + " м";
        if (km < 10) return km.toFixed(2).replace(".", ",") + " км";
        return km.toFixed(1).replace(".", ",") + " км";
    }

    function formatDuration(sec) {
        sec = Number(sec) || 0;
        if (sec <= 0) return "—";
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        if (h > 0) return h + " ч " + m + " мин";
        return m + " мин";
    }

    function formatPace(distanceM, durationSec) {
        distanceM = Number(distanceM) || 0;
        durationSec = Number(durationSec) || 0;
        if (distanceM <= 0 || durationSec <= 0) return "—";
        var paceSec = durationSec / (distanceM / 1000);
        var m = Math.floor(paceSec / 60);
        var s = Math.floor(paceSec % 60);
        return m + ":" + pad2(s) + " /км";
    }

    function formatSpeed(mps) {
        if (!mps) return "—";
        return (Number(mps) * 3.6).toFixed(1) + " км/ч";
    }

    function typeIcon(type) {
        var map = {
            run: "🏃", ride: "🚴", swim: "🏊", ski: "⛷️",
            walk: "🚶", hike: "🥾", other: "📦"
        };
        return map[type] || "📦";
    }

    function typeLabel(type) {
        var map = {
            run: "Бег", ride: "Велосипед", swim: "Плавание", ski: "Лыжи",
            walk: "Ходьба", hike: "Хайкинг", other: "Другое"
        };
        return map[type] || "Другое";
    }

    function typeColor(type) {
        var map = {
            run: "#e94f2e", ride: "#2e7de9", swim: "#2ec4e9", ski: "#5e9ee9",
            walk: "#68b96b", hike: "#8a6a3a", other: "#8a8a8f"
        };
        return map[type] || "#8a8a8f";
    }

    /* =========================================================
       Портал модалки в <body>
       ========================================================= */
    function portalModal() {
        var modal = document.getElementById("cal-modal");
        if (!modal) return;
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
    }

    /* =========================================================
       Блокировка скролла body (iOS-safe)
       ========================================================= */
    function lockBodyScroll() {
        scrollLockY = window.scrollY || window.pageYOffset || 0;
        document.body.style.position = "fixed";
        document.body.style.top = (-scrollLockY) + "px";
        document.body.style.left = "0";
        document.body.style.right = "0";
        document.body.style.width = "100%";
        document.body.style.overflow = "hidden";
    }

    function unlockBodyScroll() {
        document.body.style.position = "";
        document.body.style.top = "";
        document.body.style.left = "";
        document.body.style.right = "";
        document.body.style.width = "";
        document.body.style.overflow = "";
        window.scrollTo(0, scrollLockY);
    }

    /* =========================================================
       AJAX-загрузка месяца
       ========================================================= */
    function loadMonth(year, month) {
        var key = year + "-" + pad2(month);
        if (state.cache[key]) {
            return Promise.resolve(state.cache[key]);
        }
        return fetch(CFG.apiUrl + "?year=" + year + "&month=" + month, {
            credentials: "same-origin",
            headers: { "Accept": "application/json" }
        })
        .then(function (r) { return r.json(); })
        .then(function (body) {
            if (!body || !body.ok) throw new Error((body && body.error) || "Ошибка загрузки");
            state.cache[key] = body.data;
            return body.data;
        });
    }

    /* =========================================================
       Рендер сетки
       ========================================================= */
    function renderGrid(data) {
        var grid = document.getElementById("cal-grid");
        var title = document.getElementById("cal-title");
        if (!grid || !title) return;

        title.textContent = MONTHS_RU[data.month - 1] + " " + data.year;

        var today = new Date();
        var todayKey = today.getFullYear() + "-" + pad2(today.getMonth() + 1) + "-" + pad2(today.getDate());

        var html = "";

        // Заголовки дней недели
        html += '<div class="cal-grid__head">';
        WEEKDAYS_RU.forEach(function (wd) {
            html += '<div class="cal-grid__wd">' + wd + '</div>';
        });
        html += '</div>';

        html += '<div class="cal-grid__body">';

        // Пустые ячейки до первого дня
        var offset = data.start_weekday - 1;
        for (var i = 0; i < offset; i++) {
            html += '<div class="cal-day cal-day--empty"></div>';
        }

        // Дни месяца
        for (var d = 1; d <= data.days_in_month; d++) {
            var dateKey = data.year + "-" + pad2(data.month) + "-" + pad2(d);
            var dayData = data.days[dateKey];
            var isToday = dateKey === todayKey;

            var classes = "cal-day";
            if (isToday) classes += " cal-day--today";
            if (dayData) classes += " cal-day--has";
            else classes += " cal-day--empty-day";

            html += '<div class="' + classes + '" data-date="' + dateKey + '">';
            html +=   '<div class="cal-day__num">' + d + '</div>';

            if (dayData) {
                html += '<div class="cal-day__dots">';
                dayData.types.slice(0, 4).forEach(function (t) {
                    html += '<span class="cal-day__dot" style="background:' + typeColor(t) + '"></span>';
                });
                html += '</div>';

                html += '<div class="cal-day__dist">' + formatDistance(dayData.distance_m) + '</div>';

                if (dayData.count > 1) {
                    html += '<div class="cal-day__count">' + dayData.count + ' активн.</div>';
                }
            }

            html += '</div>';
        }

        // Пустые ячейки в конце
        var totalCells = offset + data.days_in_month;
        var remainder = totalCells % 7;
        if (remainder !== 0) {
            var tail = 7 - remainder;
            for (var j = 0; j < tail; j++) {
                html += '<div class="cal-day cal-day--empty"></div>';
            }
        }

        html += '</div>';

        grid.innerHTML = html;
    }

    /* =========================================================
       Рендер блока статистики месяца
       ========================================================= */
    function renderMonthStats(stats, monthLabel) {
        var el = document.getElementById("cal-month-stats");
        if (!el) return;

        // Заголовок с названием месяца
        var monthEl = el.querySelector(".cal-month-stats__month");
        if (monthEl) {
            monthEl.textContent = monthLabel || (MONTHS_RU[state.month - 1] + " " + state.year);
        }

        // Убираем старое тело блока (grid/footer/empty)
        var oldGrid   = el.querySelector(".cal-month-stats__grid");
        var oldFooter = el.querySelector(".cal-month-stats__footer");
        var oldEmpty  = el.querySelector(".cal-month-stats__empty");
        if (oldGrid)   oldGrid.remove();
        if (oldFooter) oldFooter.remove();
        if (oldEmpty)  oldEmpty.remove();

        // Пустое состояние
        if (!stats || Number(stats.count) <= 0) {
            var empty = document.createElement("div");
            empty.className = "cal-month-stats__empty";
            empty.innerHTML =
                '<span class="cal-month-stats__empty-icon">📭</span>' +
                '<div>' +
                    '<strong>В этом месяце тренировок не было</strong>' +
                    '<p class="muted">Загрузите первую активность — статистика появится здесь</p>' +
                '</div>';

            var headEl = el.querySelector(".cal-month-stats__head");
            if (headEl) {
                headEl.insertAdjacentHTML("afterend", empty.outerHTML);
            } else {
                el.appendChild(empty);
            }
            return;
        }

        // Сетка метрик
        var html = '<div class="cal-month-stats__grid">';

        html += '<div class="cal-month-stat">' +
                    '<div class="cal-month-stat__icon">🛣</div>' +
                    '<div class="cal-month-stat__value">' + esc(formatDistance(stats.distance_m)) + '</div>' +
                    '<div class="cal-month-stat__label">Дистанция</div>' +
                '</div>';

        html += '<div class="cal-month-stat">' +
                    '<div class="cal-month-stat__icon">🏃</div>' +
                    '<div class="cal-month-stat__value">' + Number(stats.count) + '</div>' +
                    '<div class="cal-month-stat__label">Тренировок</div>' +
                '</div>';

        html += '<div class="cal-month-stat">' +
                    '<div class="cal-month-stat__icon">⏱</div>' +
                    '<div class="cal-month-stat__value">' + esc(formatDuration(stats.duration_sec)) + '</div>' +
                    '<div class="cal-month-stat__label">Время</div>' +
                '</div>';

        html += '<div class="cal-month-stat">' +
                    '<div class="cal-month-stat__icon">⛰</div>' +
                    '<div class="cal-month-stat__value">' + Math.round(Number(stats.elevation_m) || 0) + ' м</div>' +
                    '<div class="cal-month-stat__label">Набор высоты</div>' +
                '</div>';

        html += '<div class="cal-month-stat">' +
                    '<div class="cal-month-stat__icon">📅</div>' +
                    '<div class="cal-month-stat__value">' + Number(stats.active_days) + '</div>' +
                    '<div class="cal-month-stat__label">Активных дней</div>' +
                '</div>';

        html += '<div class="cal-month-stat">' +
                    '<div class="cal-month-stat__icon">⚡</div>' +
                    '<div class="cal-month-stat__value">' +
                        esc(formatPace(stats.distance_m, stats.duration_sec)) +
                    '</div>' +
                    '<div class="cal-month-stat__label">Средний темп</div>' +
                '</div>';

        if (stats.avg_hr !== null && Number(stats.avg_hr) > 0) {
            html += '<div class="cal-month-stat">' +
                        '<div class="cal-month-stat__icon">❤️</div>' +
                        '<div class="cal-month-stat__value">' + Number(stats.avg_hr) + '<small> уд/мин</small></div>' +
                        '<div class="cal-month-stat__label">Средний пульс</div>' +
                    '</div>';
        }

        html += '</div>';

        // Футер: в среднем за активность
        var cnt = Math.max(1, Number(stats.count) || 1);
        var avgDist = (Number(stats.distance_m) || 0) / cnt;
        var avgDur  = (Number(stats.duration_sec) || 0) / cnt;

        html += '<div class="cal-month-stats__footer">' +
                    '<span class="muted">В среднем за активность: ' +
                        '<strong>' + esc(formatDistance(avgDist)) + '</strong>' +
                        ' · ' +
                        '<strong>' + esc(formatDuration(Math.round(avgDur))) + '</strong>' +
                    '</span>' +
                '</div>';

        // Вставляем после head
        var head = el.querySelector(".cal-month-stats__head");
        if (head) {
            head.insertAdjacentHTML("afterend", html);
        } else {
            el.insertAdjacentHTML("beforeend", html);
        }
    }

    /* =========================================================
       Модалка с активностями дня
       ========================================================= */
    function openDayModal(dateKey, dayData) {
        var modal = document.getElementById("cal-modal");
        if (!modal) return;

        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }

        var d = new Date(dateKey + "T00:00:00");
        var dateLabel = d.getDate() + " " + MONTHS_RU[d.getMonth()].toLowerCase() + " " + d.getFullYear();

        modal.querySelector(".cal-modal__title").textContent = dateLabel;
        modal.querySelector(".cal-modal__subtitle").textContent =
            dayData.count + " активн. · " + formatDistance(dayData.distance_m)
            + " · " + formatDuration(dayData.duration_sec);

        var list = modal.querySelector(".cal-modal__list");
        var html = "";
        dayData.activities.forEach(function (a) {
            var time = "";
            var ts = new Date(String(a.started_at).replace(" ", "T"));
            if (!isNaN(ts.getTime())) {
                time = pad2(ts.getHours()) + ":" + pad2(ts.getMinutes());
            }

            html += '<div class="cal-modal__item">';
            html +=   '<div class="cal-modal__item-icon" style="background:' + typeColor(a.type) + '1f;color:' + typeColor(a.type) + '">'
                   +    typeIcon(a.type)
                   +  '</div>';
            html +=   '<div class="cal-modal__item-body">';
            html +=     '<div class="cal-modal__item-title">' + esc(a.title) + '</div>';
            html +=     '<div class="cal-modal__item-meta">';
            html +=       typeLabel(a.type);
            if (time) html += ' · ' + time;
            html +=       ' · ' + formatDistance(a.distance_m);
            html +=       ' · ' + formatDuration(a.duration_sec);
            if (a.type === "ride" && a.avg_speed_mps) {
                html += ' · ' + formatSpeed(a.avg_speed_mps);
            } else if (a.distance_m > 0 && a.duration_sec > 0) {
                html += ' · ' + formatPace(a.distance_m, a.duration_sec);
            }
            if (a.avg_hr) html += ' · ❤️ ' + a.avg_hr;
            html +=     '</div>';
            html +=   '</div>';
            html +=   '<a class="cal-modal__item-link" href="' + esc(CFG.activityUrl + a.id) + '">Открыть →</a>';
            html += '</div>';
        });
        list.innerHTML = html;

        list.scrollTop = 0;

        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        document.body.classList.add("cal-modal-open");
        lockBodyScroll();
    }

    function closeModal() {
        var modal = document.getElementById("cal-modal");
        if (!modal) return;
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("cal-modal-open");
        unlockBodyScroll();
    }

    /* =========================================================
       Переключение месяца
       ========================================================= */
    function setLoading(on) {
        var grid = document.getElementById("cal-grid");
        if (grid) grid.classList.toggle("is-loading", !!on);

        var statsEl = document.getElementById("cal-month-stats");
        if (statsEl) statsEl.classList.toggle("is-loading", !!on);
    }

    function applyMonth(data) {
        // Обновляем состояние
        state.year  = data.year;
        state.month = data.month;

        // Рендерим сетку и статистику
        renderGrid(data);

        var monthLabel = MONTHS_RU[data.month - 1] + " " + data.year;
        renderMonthStats(data.stats, monthLabel);

        // URL
        var url = new URL(location.href);
        url.searchParams.set("y", data.year);
        url.searchParams.set("m", data.month);
        history.replaceState(null, "", url.toString());
    }

    function changeMonth(delta) {
        if (state.loading) return;

        var newMonth = state.month + delta;
        var newYear = state.year;
        if (newMonth < 1) { newMonth = 12; newYear--; }
        if (newMonth > 12) { newMonth = 1; newYear++; }

        state.loading = true;
        setLoading(true);

        loadMonth(newYear, newMonth)
            .then(function (data) {
                applyMonth(data);
            })
            .catch(function (err) {
                console.error(err);
                alert("Не удалось загрузить месяц: " + err.message);
            })
            .finally(function () {
                state.loading = false;
                setLoading(false);
            });
    }

    function goToday() {
        var now = new Date();
        var y = now.getFullYear();
        var m = now.getMonth() + 1;
        if (y === state.year && m === state.month) return;

        state.loading = true;
        setLoading(true);

        loadMonth(y, m)
            .then(function (data) {
                applyMonth(data);
            })
            .catch(function (err) {
                console.error(err);
            })
            .finally(function () {
                state.loading = false;
                setLoading(false);
            });
    }

    /* =========================================================
       Обработчики
       ========================================================= */
    function bind() {
        document.addEventListener("click", function (e) {
            if (e.target.closest(".cal-modal__item-link")) return;

            var prev     = e.target.closest("[data-cal-prev]");
            var next     = e.target.closest("[data-cal-next]");
            var todayBtn = e.target.closest("[data-cal-today]");
            var closeBtn = e.target.closest("[data-cal-close]");
            var dayCell  = e.target.closest(".cal-day--has");

            if (prev)     { e.preventDefault(); changeMonth(-1); return; }
            if (next)     { e.preventDefault(); changeMonth(1);  return; }
            if (todayBtn) { e.preventDefault(); goToday();       return; }
            if (closeBtn) { e.preventDefault(); closeModal();    return; }

            if (dayCell) {
                e.preventDefault();
                var dateKey = dayCell.dataset.date;
                var key = state.year + "-" + pad2(state.month);
                var monthData = state.cache[key];
                if (!monthData || !monthData.days[dateKey]) return;
                openDayModal(dateKey, monthData.days[dateKey]);
            }
        });

        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape") {
                var modal = document.getElementById("cal-modal");
                if (modal && modal.classList.contains("is-open")) {
                    closeModal();
                }
            }
        });

        document.addEventListener("click", function (e) {
            if (e.target.classList && e.target.classList.contains("cal-modal__backdrop")) {
                closeModal();
            }
        });
    }

    function boot() {
        portalModal();
        bind();

        // Предзаполняем кэш данными, которые уже отрисованы сервером.
        if (CFG.initialData && CFG.initialData.days) {
            var key = CFG.initialData.year + "-" + pad2(CFG.initialData.month);
            state.cache[key] = CFG.initialData;

            // На случай, если серверный рендер не вставил статистику
            if (CFG.initialData.stats && !document.querySelector("#cal-month-stats .cal-month-stats__grid")) {
                var monthLabel = MONTHS_RU[CFG.initialData.month - 1] + " " + CFG.initialData.year;
                renderMonthStats(CFG.initialData.stats, monthLabel);
            }
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
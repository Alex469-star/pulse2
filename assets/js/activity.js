/* ============================================================
   ACTIVITY PAGE — карта, графики, лайки, комментарии
   ============================================================ */
(function () {
    "use strict";

    /* ============================================================
       КАРТА LEAFLET
       ============================================================ */
    (function () {
        if (typeof L === "undefined") return;
        var pts = window.__ACTIVITY_TRACK__ || [];
        var mapEl = document.getElementById("activity-map");
        if (!mapEl) return;
        if (pts.length < 2) {
            mapEl.innerHTML = '<div style="padding:40px;text-align:center;color:#8a93a3">Нет GPS-трека</div>';
            return;
        }
        var map = L.map("activity-map", { scrollWheelZoom: false }).setView([pts[0].lat, pts[0].lng], 14);
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: "&copy; OpenStreetMap"
        }).addTo(map);
        var latlngs = pts.map(function (p) { return [p.lat, p.lng]; });
        var line = L.polyline(latlngs, { color: "#ff5a1f", weight: 5, opacity: 0.95 }).addTo(map);
        L.circleMarker(latlngs[0], { radius: 7, color: "#0a7a3a", fillColor: "#0a7a3a", fillOpacity: 1, weight: 2 }).bindPopup("Старт").addTo(map);
        L.circleMarker(latlngs[latlngs.length - 1], { radius: 7, color: "#b3261e", fillColor: "#b3261e", fillOpacity: 1, weight: 2 }).bindPopup("Финиш").addTo(map);
        map.fitBounds(line.getBounds(), { padding: [30, 30] });
        window.__ACTIVITY_MAP__ = map;
    })();

    /* ============================================================
       КОПИРОВАНИЕ ССЫЛКИ
       ============================================================ */
    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-copy-link");
        if (!btn) return;
        e.preventDefault();
        var url = window.__ACTIVITY_URL__;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                var old = btn.querySelector(".activity-sidebar__label");
                if (old) {
                    var text = old.textContent;
                    old.textContent = "Скопировано";
                    btn.classList.add("is-copied");
                    setTimeout(function () {
                        old.textContent = text;
                        btn.classList.remove("is-copied");
                    }, 1500);
                }
            });
        } else {
            window.prompt("Скопируйте ссылку:", url);
        }
    });

    /* ============================================================
       МОДАЛКА ЛАЙКНУВШИХ
       ============================================================ */
    (function () {
        var apiUrl = window.__LIKERS_API__;
        var cache = {};
        function el(id) { return document.getElementById(id); }

        function ensureModal() {
            if (el("likers-modal")) return;
            var modal = document.createElement("div");
            modal.className = "likers-modal";
            modal.id = "likers-modal";
            modal.hidden = true;
            modal.innerHTML =
                '<div class="likers-modal__backdrop" data-close></div>' +
                '<div class="likers-modal__panel" role="dialog" aria-modal="true">' +
                    '<div class="likers-modal__head">' +
                        '<h3 class="likers-modal__title">Лайкнули</h3>' +
                        '<button type="button" class="likers-modal__close" data-close aria-label="Закрыть">×</button>' +
                    '</div>' +
                    '<div class="likers-modal__body" id="likers-modal-body"></div>' +
                '</div>';
            document.body.appendChild(modal);
            modal.addEventListener("click", function (e) {
                if (e.target.closest("[data-close]")) close();
            });
            document.addEventListener("keydown", function (e) {
                if (!modal.hidden && e.key === "Escape") close();
            });
        }

        function open(activityId) {
            ensureModal();
            var modal = el("likers-modal");
            var body = el("likers-modal-body");
            modal.hidden = false;
            document.body.style.overflow = "hidden";
            if (cache[activityId]) { body.innerHTML = cache[activityId]; return; }
            body.innerHTML = '<div class="likers-modal__loading">Загрузка…</div>';
            fetch(apiUrl + "?activity_id=" + encodeURIComponent(activityId), {
                credentials: "same-origin",
                headers: { "Accept": "application/json" }
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) throw new Error((res && res.error) || "Ошибка");
                var likers = (res.data && res.data.likers) || [];
                var html = renderList(likers);
                cache[activityId] = html;
                body.innerHTML = html;
            })
            .catch(function () {
                body.innerHTML = '<div class="likers-modal__empty">Не удалось загрузить список</div>';
            });
        }

        function renderList(likers) {
            if (!likers.length) return '<div class="likers-modal__empty">Пока никто не лайкнул</div>';
            var html = '<ul class="likers-list">';
            likers.forEach(function (u) {
                var avatar = u.avatar_url
                    ? '<img src="' + escapeAttr(u.avatar_url) + '" alt="">'
                    : escapeHtml(u.initial || "?");
                html += '<li class="likers-list__item"><a class="likers-list__link" href="' + escapeAttr(u.profile_url) + '">' +
                    '<span class="avatar avatar--sm">' + avatar + '</span>' +
                    '<span class="likers-list__info"><span class="likers-list__name">' + escapeHtml(u.display_name) + '</span>' +
                    '<span class="likers-list__username">@' + escapeHtml(u.username) + '</span></span></a></li>';
            });
            return html + "</ul>";
        }

        function close() {
            var modal = el("likers-modal");
            if (!modal) return;
            modal.hidden = true;
            document.body.style.overflow = "";
        }

        function escapeHtml(s) {
            return String(s == null ? "" : s)
                .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
        function escapeAttr(s) { return escapeHtml(s); }

        document.addEventListener("click", function (e) {
            var btn = e.target.closest(".js-likers-btn");
            if (!btn) return;
            e.preventDefault();
            var aid = parseInt(btn.dataset.activityId, 10);
            if (!aid) return;
            open(aid);
        });
    })();

    /* ============================================================
       ГРАФИКИ
       ============================================================ */
    (function () {
        if (typeof Chart === "undefined") return;

        var elevData  = window.__ACTIVITY_ELEV__  || [];
        var speedData = window.__ACTIVITY_SPEED__ || [];
        var hrData    = window.__ACTIVITY_HR__    || [];
        var pwrData   = window.__ACTIVITY_PWR__   || [];
        var cadData   = window.__ACTIVITY_CAD__   || [];
        var totalD    = window.__ACTIVITY_TOTAL_D__ || 0;

        Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
        Chart.defaults.font.size   = 11;
        Chart.defaults.color       = "#5b6473";

        var distanceTick = function (v) {
            return v >= 1000 ? (v / 1000).toFixed(1) + " км" : Math.round(v) + " м";
        };
        var tooltipTitleDistance = function (items) {
            var d = items[0].parsed.x;
            return d >= 1000 ? (d / 1000).toFixed(2) + " км" : Math.round(d) + " м";
        };

        function buildDistanceChart(canvasId, data, opts) {
            var canvas = document.getElementById(canvasId);
            if (!canvas || !data.length) return;
            var ctx = canvas.getContext("2d");
            var grad = ctx.createLinearGradient(0, 0, 0, 220);
            grad.addColorStop(0, opts.fillTop);
            grad.addColorStop(1, opts.fillBottom);
            return new Chart(ctx, {
                type: "line",
                data: {
                    labels: data.map(function (p) { return p.d; }),
                    datasets: [{
                        label: opts.label,
                        data: data.map(function (p) { return p[opts.key]; }),
                        borderColor: opts.color,
                        backgroundColor: grad,
                        borderWidth: 1.5,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        pointHoverBackgroundColor: opts.color,
                        pointHoverBorderColor: "#fff",
                        pointHoverBorderWidth: 2,
                        fill: true,
                        tension: 0.25
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: "index", intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: "rgba(15,20,32,.92)",
                            titleColor: "#fff", bodyColor: "#fff",
                            padding: 10, cornerRadius: 8, displayColors: false,
                            callbacks: {
                                title: tooltipTitleDistance,
                                label: function (item) {
                                    return opts.label + ": " + item.parsed.y.toFixed(opts.decimals) + " " + opts.unit;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { type: "linear", grid: { display: false }, ticks: { maxTicksLimit: 6, callback: distanceTick } },
                        y: { beginAtZero: opts.beginAtZero === true, grid: { color: "rgba(15,20,32,.06)" }, ticks: { maxTicksLimit: 5, callback: function (v) { return v + " " + opts.unit; } } }
                    }
                }
            });
        }

        function computeStats(data, key) {
            var min = Infinity, max = -Infinity, sum = 0;
            data.forEach(function (p) {
                var v = p[key];
                if (v < min) min = v;
                if (v > max) max = v;
                sum += v;
            });
            return { min: min, max: max, avg: sum / data.length };
        }

        if (elevData.length) {
            var s = computeStats(elevData, "ele");
            var stats = document.getElementById("elev-stats");
            if (stats) stats.innerHTML =
                "<span>мин <strong>" + Math.round(s.min) + " м</strong></span>" +
                "<span>макс <strong>" + Math.round(s.max) + " м</strong></span>" +
                "<span>сред <strong>" + Math.round(s.avg) + " м</strong></span>";
            buildDistanceChart("elev-chart", elevData, {
                key: "ele", label: "Высота", unit: "м", decimals: 0,
                color: "#ff5a1f", fillTop: "rgba(255,90,31,.35)", fillBottom: "rgba(255,90,31,.02)"
            });
        }

        if (speedData.length) {
            var s2 = computeStats(speedData, "v");
            var stats2 = document.getElementById("speed-stats");
            if (stats2) stats2.innerHTML =
                "<span>сред <strong>" + s2.avg.toFixed(1) + " км/ч</strong></span>" +
                "<span>макс <strong>" + s2.max.toFixed(1) + " км/ч</strong></span>";
            buildDistanceChart("speed-chart", speedData, {
                key: "v", label: "Скорость", unit: "км/ч", decimals: 1,
                color: "#1f5fc4", fillTop: "rgba(31,95,196,.30)", fillBottom: "rgba(31,95,196,.02)",
                beginAtZero: true
            });
        }

        if (hrData.length) {
            var s3 = computeStats(hrData, "hr");
            var stats3 = document.getElementById("hr-stats");
            if (stats3) stats3.innerHTML =
                "<span>сред <strong>" + Math.round(s3.avg) + " уд/мин</strong></span>" +
                "<span>макс <strong>" + Math.round(s3.max) + " уд/мин</strong></span>";
            buildDistanceChart("hr-chart", hrData, {
                key: "hr", label: "Пульс", unit: "уд/мин", decimals: 0,
                color: "#e11d48", fillTop: "rgba(225,29,72,.30)", fillBottom: "rgba(225,29,72,.02)"
            });
        }

        if (pwrData.length) {
            var s4 = computeStats(pwrData, "pwr");
            var stats4 = document.getElementById("pwr-stats");
            if (stats4) stats4.innerHTML =
                "<span>сред <strong>" + Math.round(s4.avg) + " Вт</strong></span>" +
                "<span>макс <strong>" + Math.round(s4.max) + " Вт</strong></span>";
            buildDistanceChart("pwr-chart", pwrData, {
                key: "pwr", label: "Мощность", unit: "Вт", decimals: 0,
                color: "#7c3aed", fillTop: "rgba(124,58,237,.30)", fillBottom: "rgba(124,58,237,.02)",
                beginAtZero: true
            });
        }

        if (cadData.length) {
            var s5 = computeStats(cadData, "cad");
            var stats5 = document.getElementById("cad-stats");
            if (stats5) stats5.innerHTML =
                "<span>сред <strong>" + Math.round(s5.avg) + "</strong></span>" +
                "<span>макс <strong>" + Math.round(s5.max) + "</strong></span>";
            buildDistanceChart("cad-chart", cadData, {
                key: "cad", label: "Каденс", unit: "об/мин", decimals: 0,
                color: "#0a7a3a", fillTop: "rgba(10,122,58,.28)", fillBottom: "rgba(10,122,58,.02)",
                beginAtZero: true
            });
        }

        /* Курсор по графику → бегающий маркер по карте */
        (function () {
            var map = window.__ACTIVITY_MAP__;
            var track = window.__ACTIVITY_TRACK__ || [];
            if (!map || !track.length) return;
            var marker = L.circleMarker([track[0].lat, track[0].lng], {
                radius: 7, color: "#1f5fc4", fillColor: "#1f5fc4",
                fillOpacity: 1, weight: 2, opacity: 0
            }).addTo(map);
            var totalTrackIdx = track.length - 1;

            function showAtDistance(dist) {
                if (totalD <= 0) return;
                var ratio = Math.max(0, Math.min(1, dist / totalD));
                var idx = Math.round(ratio * totalTrackIdx);
                var p = track[idx];
                if (!p) return;
                marker.setLatLng([p.lat, p.lng]);
                marker.setStyle({ opacity: 1 });
            }
            function hide() { marker.setStyle({ opacity: 0 }); }
            function attach(canvasId) {
                var canvas = document.getElementById(canvasId);
                if (!canvas) return;
                canvas.addEventListener("mousemove", function (e) {
                    var chart = Chart.getChart(canvas);
                    if (!chart) return;
                    var rect = canvas.getBoundingClientRect();
                    var x = e.clientX - rect.left;
                    var meta = chart.getDatasetMeta(0);
                    var elements = meta.data;
                    if (!elements.length) return;
                    var closest = 0, minDist = Infinity;
                    for (var i = 0; i < elements.length; i++) {
                        var dx = Math.abs(elements[i].x - x);
                        if (dx < minDist) { minDist = dx; closest = i; }
                    }
                    var label = chart.data.labels[closest];
                    if (typeof label === "number") showAtDistance(label);
                });
                canvas.addEventListener("mouseleave", hide);
                canvas.addEventListener("touchend", hide);
            }
            ["elev-chart", "speed-chart", "hr-chart", "pwr-chart", "cad-chart"].forEach(attach);
        })();
    })();

    /* ============================================================
       AJAX-ЛАЙК
       ============================================================ */
    (function () {
        var csrf = window.__CSRF__;
        var likeApi = window.__LIKE_API__;

        document.querySelectorAll(".js-like-btn").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var activityId = parseInt(btn.dataset.activityId, 10);
                if (!activityId) return;
                btn.disabled = true;
                fetch(likeApi, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                    body: JSON.stringify({ activity_id: activityId })
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) throw new Error((res && res.error) || "Ошибка");
                    var data = res.data || {};
                    btn.classList.toggle("is-active", !!data.liked);
                    var counter = btn.querySelector(".js-like-count");
                    if (counter) counter.textContent = parseInt(data.count, 10) || 0;
                    var likersBtn = document.querySelector('.js-likers-btn[data-activity-id="' + activityId + '"]');
                    if (likersBtn) {
                        likersBtn.style.display = (parseInt(data.count, 10) || 0) > 0 ? "" : "none";
                    }
                })
                .catch(function (err) { console.error("Like error:", err); })
                .finally(function () { btn.disabled = false; });
            });
        });
    })();

    /* ============================================================
       КОММЕНТАРИИ: ответы, редактирование, удаление
       ============================================================ */
    (function () {
        var commentApi = window.__COMMENT_API__;
        var csrf = window.__CSRF__;
        var activityId = window.__ACTIVITY_ID__;
        var meId = window.__ME_ID__;
        var ownerId = window.__ACTIVITY_OWNER_ID__;

        function post(action, payload) {
            return fetch(commentApi, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": csrf
                },
                body: JSON.stringify(Object.assign({ action: action, activity_id: activityId }, payload))
            }).then(function (r) { return r.json(); });
        }

        function escapeHtml(s) {
            return String(s == null ? "" : s)
                .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        function updateCount(n) {
            var el = document.getElementById("comments-count");
            if (el) el.textContent = n;
            var el2 = document.getElementById("comments-action-count");
            if (el2) el2.textContent = n;
        }

        function closeAllMenus(except) {
            document.querySelectorAll(".comment__menu").forEach(function (m) {
                if (m !== except) m.hidden = true;
            });
        }

        /* -------- ДЕЛЕГИРОВАННЫЕ ОБРАБОТЧИКИ (один раз на document) -------- */

        document.addEventListener("click", function (e) {
            // 1) Открытие/закрытие меню
            var menuBtn = e.target.closest(".comment__menu-btn");
            if (menuBtn) {
                e.preventDefault();
                var menu = menuBtn.parentElement.querySelector(".comment__menu");
                if (!menu) return;
                var willOpen = menu.hidden;
                closeAllMenus();
                menu.hidden = !willOpen;
                return;
            }

            // 2) Кнопка «Ответить» в меню
            var replyBtn = e.target.closest(".js-reply-btn");
            if (replyBtn) {
                e.preventDefault();
                closeAllMenus();
                var cid = replyBtn.dataset.commentId;
                var comment = document.getElementById("comment-" + cid);
                if (!comment) return;
                var form = comment.querySelector(".js-reply-form");
                if (!form) return;
                form.hidden = false;
                form.querySelector("textarea").focus();
                return;
            }

            // 3) Отмена ответа
            var replyCancel = e.target.closest(".js-reply-cancel");
            if (replyCancel) {
                e.preventDefault();
                var rf = replyCancel.closest(".js-reply-form");
                if (rf) {
                    rf.hidden = true;
                    rf.querySelector("textarea").value = "";
                }
                return;
            }

            // 4) Кнопка «Редактировать» в меню
            var editBtn = e.target.closest(".js-edit-btn");
            if (editBtn) {
                e.preventDefault();
                closeAllMenus();
                var ecid = editBtn.dataset.commentId;
                var ecomment = document.getElementById("comment-" + ecid);
                if (!ecomment) return;
                var eform = ecomment.querySelector(".js-edit-form");
                var etext = ecomment.querySelector(".js-comment-text");
                if (eform) eform.hidden = false;
                if (etext) etext.hidden = true;
                if (eform) eform.querySelector("textarea").focus();
                return;
            }

            // 5) Отмена редактирования
            var editCancel = e.target.closest(".js-edit-cancel");
            if (editCancel) {
                e.preventDefault();
                var ecomment2 = editCancel.closest(".comment");
                var eform2 = ecomment2.querySelector(".js-edit-form");
                var etext2 = ecomment2.querySelector(".js-comment-text");
                if (eform2) eform2.hidden = true;
                if (etext2) etext2.hidden = false;
                return;
            }

            // 6) Удалить
            var delBtn = e.target.closest(".js-delete-btn");
            if (delBtn) {
                e.preventDefault();
                closeAllMenus();
                var dcid = delBtn.dataset.commentId;
                if (!confirm("Удалить комментарий?")) return;
                post("delete", { comment_id: dcid })
                    .then(function (res) {
                        if (!res.ok) throw new Error(res.error || "Ошибка");
                        var dcomment = document.getElementById("comment-" + dcid);
                        if (dcomment) dcomment.remove();
                        updateCount(res.data.count);
                    })
                    .catch(function (err) { alert(err.message); });
                return;
            }

            // 7) Клик вне меню — закрыть все
            if (!e.target.closest(".comment__menu")) {
                closeAllMenus();
            }
        });

        /* -------- Отправка форм (делегирование) -------- */

        document.addEventListener("submit", function (e) {
            // Редактирование комментария
            var editForm = e.target.closest(".js-edit-form form");
            if (editForm) {
                e.preventDefault();
                var ecomment = editForm.closest(".comment");
                var cid = ecomment.dataset.commentId;
                var body = editForm.querySelector("textarea").value.trim();
                if (!body) return;

                post("update", { comment_id: cid, body: body })
                    .then(function (res) {
                        if (!res.ok) throw new Error(res.error || "Ошибка");
                        var text = ecomment.querySelector(".js-comment-text");
                        text.innerHTML = escapeHtml(body).replace(/\n/g, "<br>");
                        text.hidden = false;
                        editForm.hidden = true;

                        var head = ecomment.querySelector(".comment__head");
                        if (!head.querySelector(".comment__edited")) {
                            var span = document.createElement("span");
                            span.className = "comment__edited";
                            span.textContent = "(изменено)";
                            var timeEl = head.querySelector(".comment__time");
                            if (timeEl) timeEl.insertAdjacentElement("afterend", span);
                        }
                    })
                    .catch(function (err) { alert(err.message); });
                return;
            }

            // Ответ на комментарий
            var replyForm = e.target.closest(".js-reply-form form");
            if (replyForm) {
                e.preventDefault();
                var rcomment = replyForm.closest(".comment");
                var parentId = parseInt(rcomment.dataset.commentId, 10);
                var rbody = replyForm.querySelector("textarea").value.trim();
                if (!rbody) return;

                post("add", { body: rbody, parent_id: parentId })
                    .then(function (res) {
                        if (!res.ok) throw new Error(res.error || "Ошибка");
                        var c = res.data.comment;
                        var html = renderCommentHtml(c, true);
                        var replies = rcomment.querySelector(".comment__replies");
                        if (!replies) {
                            replies = document.createElement("div");
                            replies.className = "comment__replies";
                            rcomment.querySelector(".comment__body").appendChild(replies);
                        }
                        replies.insertAdjacentHTML("beforeend", html);
                        replyForm.reset();
                        replyForm.hidden = true;
                        updateCount(res.data.count);
                    })
                    .catch(function (err) { alert(err.message); });
                return;
            }
        });

        /* -------- Корневая форма нового комментария -------- */

        var rootForm = document.getElementById("comment-root-form");
        if (rootForm) {
            rootForm.addEventListener("submit", function (e) {
                e.preventDefault();
                var body = rootForm.querySelector("textarea").value.trim();
                if (!body) return;
                post("add", { body: body })
                    .then(function (res) {
                        if (!res.ok) throw new Error(res.error || "Ошибка");
                        var list = document.getElementById("comments-list");
                        var empty = list.querySelector(".js-comments-empty");
                        if (empty) empty.parentElement.remove();

                        var c = res.data.comment;
                        var html = renderCommentHtml(c, false);
                        list.insertAdjacentHTML("beforeend", html);
                        rootForm.reset();
                        updateCount(res.data.count);
                    })
                    .catch(function (err) { alert(err.message); });
            });
        }

        /* -------- Генерация HTML нового комментария -------- */

        function renderCommentHtml(c, isReply) {
            var canEdit = (c.user_id === meId);
            var canDelete = (c.user_id === meId) || (ownerId === meId);
            var avatar = c.avatar_url
                ? '<img src="' + escapeHtml(c.avatar_url) + '" alt="">'
                : escapeHtml((c.display_name || "?").charAt(0).toUpperCase());

            var menuItems = '';
            menuItems += '<button type="button" class="comment__menu-item js-reply-btn" data-comment-id="' + c.id + '" data-author-name="' + escapeHtml(c.display_name) + '">💬 Ответить</button>';
            if (canEdit) {
                menuItems += '<button type="button" class="comment__menu-item js-edit-btn" data-comment-id="' + c.id + '">✏️ Редактировать</button>';
            }
            if (canDelete) {
                menuItems += '<button type="button" class="comment__menu-item comment__menu-item--danger js-delete-btn" data-comment-id="' + c.id + '">🗑 Удалить</button>';
            }

            return '' +
                '<div class="comment ' + (isReply ? 'comment--reply' : '') + '" ' +
                     'id="comment-' + c.id + '" ' +
                     'data-comment-id="' + c.id + '" ' +
                     'data-author-name="' + escapeHtml(c.display_name) + '" ' +
                     'data-author-id="' + c.user_id + '" ' +
                     'data-can-edit="' + (canEdit ? '1' : '0') + '" ' +
                     'data-can-delete="' + (canDelete ? '1' : '0') + '">' +
                    '<span class="avatar avatar--sm">' + avatar + '</span>' +
                    '<div class="comment__body">' +
                        '<div class="comment__head">' +
                            '<a href="' + escapeHtml(c.profile_url || "#") + '"><strong>' + escapeHtml(c.display_name) + '</strong></a>' +
                            '<span class="comment__time muted">' + escapeHtml(c.time_ago || "только что") + '</span>' +
                            '<button type="button" class="comment__menu-btn" data-menu-toggle aria-label="Действия">⋯</button>' +
                            '<div class="comment__menu" data-menu hidden>' + menuItems + '</div>' +
                        '</div>' +
                        '<div class="comment__text js-comment-text">' + escapeHtml(c.body).replace(/\n/g, "<br>") + '</div>' +
                        '<div class="comment__reply-form js-reply-form" hidden>' +
                            '<form class="comment-form comment-form--inline">' +
                                '<textarea name="body" rows="2" placeholder="Ответить…" maxlength="1000" required></textarea>' +
                                '<div class="comment-form__actions">' +
                                    '<button type="button" class="btn btn--ghost btn--sm js-reply-cancel">Отмена</button>' +
                                    '<button type="submit" class="btn btn--primary btn--sm">Ответить</button>' +
                                '</div>' +
                            '</form>' +
                        '</div>' +
                    '</div>' +
                '</div>';
        }

        /* -------- Скролл к комментарию из хэша -------- */

        if (location.hash && /^#comment-\d+$/.test(location.hash)) {
            var target = document.querySelector(location.hash);
            if (target) {
                setTimeout(function () {
                    target.scrollIntoView({ behavior: "smooth", block: "center" });
                    target.classList.add("comment--highlight");
                    setTimeout(function () { target.classList.remove("comment--highlight"); }, 2000);
                }, 200);
            }
        }
    })();
})();
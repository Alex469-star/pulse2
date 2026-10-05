(function () {
    "use strict";

    var CFG = window.__CLUB__;
    if (!CFG) return;

    function esc(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/\x27/g, "&#039;");
    }

    function api(url, data) {
        return fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": CFG.csrf,
                "Accept": "application/json"
            },
            body: JSON.stringify(data)
        }).then(function (r) {
            return r.json()
                .then(function (b) { return { ok: r.ok, body: b }; })
                .catch(function () { return { ok: false, body: { error: 'Bad JSON' } }; });
        });
    }

    /* =========================================================
       1. AJAX-ВКЛАДКИ
       ========================================================= */
    var mainEl = document.getElementById("club-main");
    var tabsEl = document.getElementById("club-tabs");

    if (tabsEl && mainEl) {
        tabsEl.addEventListener("click", function (e) {
            var tab = e.target.closest("[data-tab]");
            if (!tab) return;
            e.preventDefault();

            var tabName = tab.dataset.tab;
            var url = new URL(location.href);
            url.searchParams.set("tab", tabName);
            history.pushState({ tab: tabName }, "", url.toString());

            tabsEl.querySelectorAll(".feed-tab").forEach(function (el) {
                el.classList.toggle("is-active", el.dataset.tab === tabName);
            });

            mainEl.classList.add("is-loading");
            fetch(location.pathname + "?" + url.searchParams.toString() + "&ajax=1", {
                credentials: "same-origin",
                headers: { "X-Requested-With": "XMLHttpRequest" }
            })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, "text/html");
                var newMain = doc.getElementById("club-main");
                if (newMain) {
                    mainEl.innerHTML = newMain.innerHTML;
                    initWall();
                    initMaps();
                }
                mainEl.classList.remove("is-loading");
            })
            .catch(function (err) {
                console.error(err);
                mainEl.classList.remove("is-loading");
            });
        });
    }

    window.addEventListener("popstate", function (e) {
        if (e.state && e.state.tab && tabsEl) {
            var t = tabsEl.querySelector('[data-tab="' + e.state.tab + '"]');
            if (t) t.click();
        }
    });

    /* =========================================================
       2. РЕНДЕР
       ========================================================= */
    function renderPostHtml(p) {
        var avatar = p.avatar_url
            ? '<img src="' + esc(p.avatar_url) + '" alt="">'
            : esc((p.display_name || '?').substring(0, 1));

        var canEdit = !!p.can_edit;
        var canDelete = !!p.can_delete;
        var menuBtn = (canEdit || canDelete)
            ? '<button type="button" class="club-wall-post__menu-btn" data-menu-toggle>⋯</button>'
            : '';

        return '<div class="club-wall-post" data-post-id="' + p.id + '"'
            + ' data-root-id="' + p.id + '"'
            + ' data-author-id="' + (p.user_id || 0) + '"'
            + ' data-author-name="' + esc(p.display_name || '') + '"'
            + ' data-can-edit="' + (canEdit ? '1' : '0') + '"'
            + ' data-can-delete="' + (canDelete ? '1' : '0') + '">'
            + '<span class="avatar avatar--sm">' + avatar + '</span>'
            + '<div class="club-wall-post__body">'
            +   '<div class="club-wall-post__head">'
            +     '<a href="/pulse/profile.php?u=' + encodeURIComponent(p.username) + '"><strong>' + esc(p.display_name) + '</strong></a>'
            +     '<span class="muted"> · только что</span>'
            +     menuBtn
            +   '</div>'
            +   '<div class="js-post-body">' + esc(p.body).replace(/\n/g, "<br>") + '</div>'
            +   '<button type="button" class="club-wall-post__reply-btn js-reply-btn"'
            +     ' data-post-id="' + p.id + '"'
            +     ' data-reply-to="' + (p.user_id || 0) + '"'
            +     ' data-reply-to-name="' + esc(p.display_name || '') + '">💬 Ответить</button>'
            +   '<div class="club-wall-replies js-replies" data-root-id="' + p.id + '"></div>'
            + '</div>'
            + '</div>';
    }

    function renderReplyHtml(p) {
        var avatar = p.avatar_url
            ? '<img src="' + esc(p.avatar_url) + '" alt="">'
            : esc((p.display_name || '?').substring(0, 1));

        var canEdit = !!p.can_edit;
        var canDelete = !!p.can_delete;
        var menuBtn = (canEdit || canDelete)
            ? '<button type="button" class="club-wall-post__menu-btn" data-menu-toggle>⋯</button>'
            : '';

        var toBlock = (p.reply_to_user_id && p.reply_to_name)
            ? '<span class="club-wall-reply__arrow">→</span>'
              + '<a class="club-wall-reply__to" href="/pulse/profile.php?u=' + encodeURIComponent(p.reply_to_username || '') + '">'
              +   '<strong>' + esc(p.reply_to_name) + '</strong>'
              + '</a>'
            : '';

        return '<div class="club-wall-reply" data-post-id="' + p.id + '"'
            + ' data-root-id="' + (p.parent_id || 0) + '"'
            + ' data-author-id="' + (p.user_id || 0) + '"'
            + ' data-author-name="' + esc(p.display_name || '') + '"'
            + ' data-can-edit="' + (canEdit ? '1' : '0') + '"'
            + ' data-can-delete="' + (canDelete ? '1' : '0') + '">'
            + '<span class="avatar avatar--sm">' + avatar + '</span>'
            + '<div class="club-wall-reply__body">'
            +   '<div class="club-wall-reply__head">'
            +     '<a href="/pulse/profile.php?u=' + encodeURIComponent(p.username) + '"><strong>' + esc(p.display_name) + '</strong></a>'
            +     toBlock
            +     '<span class="muted"> · только что</span>'
            +     menuBtn
            +   '</div>'
            +   '<div class="js-post-body">' + esc(p.body).replace(/\n/g, "<br>") + '</div>'
            +   '<button type="button" class="club-wall-post__reply-btn js-reply-btn"'
            +     ' data-post-id="' + (p.parent_id || 0) + '"'
            +     ' data-reply-to="' + (p.user_id || 0) + '"'
            +     ' data-reply-to-name="' + esc(p.display_name || '') + '">💬 Ответить</button>'
            + '</div>'
            + '</div>';
    }

    /* =========================================================
       3. СТЕНА
       ========================================================= */
    function initWall() {
        var list = document.getElementById("club-wall-list");
        if (!list) return;

        // Форма нового поста
        var form = document.querySelector(".js-wall-form");
        if (form && !form.dataset.bound) {
            form.dataset.bound = "1";
            form.addEventListener("submit", function (e) {
                e.preventDefault();
                var ta = form.querySelector("textarea");
                var body = ta.value.trim();
                if (!body) return;

                var btn = form.querySelector("button");
                btn.disabled = true;

                api(CFG.urls.post, { club_id: CFG.id, body: body })
                    .then(function (res) {
                        if (!res.ok || !res.body.ok) {
                            alert((res.body && res.body.error) || "Ошибка");
                            return;
                        }
                        var d = res.body.data;
                        d.can_edit = true;
                        d.can_delete = true;
                        ta.value = "";
                        var empty = list.querySelector(".js-wall-empty");
                        if (empty) empty.remove();
                        list.insertAdjacentHTML("afterbegin", renderPostHtml(d));
                    })
                    .finally(function () { btn.disabled = false; });
            });
        }

        if (!list.dataset.bound) {
            list.dataset.bound = "1";
            list.addEventListener("click", handleWallClick);
        }
    }

    function handleWallClick(e) {
        // Меню «три точки»
        var menuBtn = e.target.closest("[data-menu-toggle]");
        if (menuBtn) {
            e.preventDefault();
            e.stopPropagation();
            toggleMenu(menuBtn);
            return;
        }

        // Действия в меню
        var actionEl = e.target.closest("[data-action]");
        if (actionEl) {
            e.preventDefault();
            var action = actionEl.dataset.action;
            var container = actionEl.closest("[data-post-id]");
            var postId = parseInt(container.dataset.postId, 10);
            var rootId = parseInt(container.dataset.rootId || container.dataset.postId, 10);
            var authorId = parseInt(container.dataset.authorId || 0, 10) || null;
            var authorName = container.dataset.authorName || "";

            closeAllMenus();

            if (action === "edit")   return openEditForm(container, postId);
            if (action === "delete") return deletePost(container, postId);
            if (action === "reply")  return openReplyForm(rootId, authorId, authorName);
            return;
        }

        // Кнопка «Ответить» под постом или ответом
        var replyBtn = e.target.closest(".js-reply-btn");
        if (replyBtn) {
            e.preventDefault();
            e.stopPropagation();
            var rootPostId = parseInt(replyBtn.dataset.postId, 10);
            var replyTo = parseInt(replyBtn.dataset.replyTo || 0, 10) || null;
            var replyToName = replyBtn.dataset.replyToName || "";
            openReplyForm(rootPostId, replyTo, replyToName);
            return;
        }

        if (!e.target.closest(".club-wall-post__menu")) closeAllMenus();
    }

    function toggleMenu(btn) {
        var container = btn.closest("[data-post-id]");
        var existing = container.querySelector(".club-wall-post__menu");
        closeAllMenus();
        if (existing) return;

        var canEdit = container.dataset.canEdit === "1";
        var canDelete = container.dataset.canDelete === "1";
        var isReply = container.classList.contains("club-wall-reply");

        var items = "";
        if (canEdit) items += '<button data-action="edit"><span>✏️</span>Редактировать</button>';
        items += '<button data-action="reply"><span>💬</span>Ответить</button>';
        if (canDelete) items += '<button data-action="delete" class="is-danger"><span>🗑</span>Удалить</button>';

        var menu = document.createElement("div");
        menu.className = "club-wall-post__menu";
        menu.innerHTML = items;

        var body = container.querySelector(".club-wall-post__body, .club-wall-reply__body");
        body.style.position = "relative";
        body.appendChild(menu);
    }

    function closeAllMenus() {
        document.querySelectorAll(".club-wall-post__menu").forEach(function (m) { m.remove(); });
    }

    function openEditForm(container, postId) {
        var bodyEl = container.querySelector(".js-post-body");
        if (!bodyEl) return;
        var oldHtml = bodyEl.innerHTML;
        var oldText = bodyEl.textContent;

        bodyEl.innerHTML = ''
            + '<form class="club-wall-post__edit-form">'
            + '  <textarea maxlength="1000">' + esc(oldText) + '</textarea>'
            + '  <div>'
            + '    <button type="submit">Сохранить</button>'
            + '    <button type="button" class="btn-cancel">Отмена</button>'
            + '  </div>'
            + '</form>';

        var f = bodyEl.querySelector("form");
        f.querySelector(".btn-cancel").addEventListener("click", function () {
            bodyEl.innerHTML = oldHtml;
        });
        f.addEventListener("submit", function (e) {
            e.preventDefault();
            var newBody = f.querySelector("textarea").value.trim();
            if (!newBody) return;

            api(CFG.urls.edit, { post_id: postId, body: newBody })
                .then(function (res) {
                    if (!res.ok || !res.body.ok) {
                        alert((res.body && res.body.error) || "Ошибка");
                        return;
                    }
                    bodyEl.innerHTML = esc(newBody).replace(/\n/g, "<br>");
                    var head = container.querySelector(".club-wall-post__head, .club-wall-reply__head");
                    if (head && !head.querySelector(".club-wall-post__edited")) {
                        var mark = document.createElement("span");
                        mark.className = "club-wall-post__edited";
                        mark.textContent = "(изменено)";
                        head.appendChild(mark);
                    }
                });
        });
    }

    function deletePost(container, postId) {
        if (!confirm("Удалить сообщение?")) return;
        api(CFG.urls.del, { post_id: postId })
            .then(function (res) {
                if (!res.ok || !res.body.ok) {
                    alert((res.body && res.body.error) || "Ошибка");
                    return;
                }
                container.style.transition = "opacity .2s, transform .2s";
                container.style.opacity = "0";
                container.style.transform = "translateY(-4px)";
                setTimeout(function () { container.remove(); }, 200);
            });
    }

    /**
     * Открыть форму ответа.
     * @param {number} rootPostId — ID корневого поста, куда вставляется ответ
     * @param {number|null} replyToUserId — ID пользователя, которому адресован ответ
     * @param {string} replyToName — имя для placeholder
     */
    function openReplyForm(rootPostId, replyToUserId, replyToName) {
        var rootEl = document.querySelector('.club-wall-post[data-post-id="' + rootPostId + '"]');
        if (!rootEl) return;

        var replies = rootEl.querySelector(".js-replies");
        if (!replies) {
            replies = document.createElement("div");
            replies.className = "club-wall-replies js-replies";
            replies.dataset.rootId = rootPostId;
            rootEl.querySelector(".club-wall-post__body").appendChild(replies);
        }

        // Уже открыта — закрываем
        var existing = rootEl.querySelector(".club-wall-reply-form");
        if (existing) { existing.remove(); return; }

        var placeholder = replyToName ? ("Ответ " + replyToName + "...") : "Ответ...";

        var form = document.createElement("form");
        form.className = "club-wall-reply-form";
        form.dataset.root = rootPostId;
        form.dataset.replyTo = replyToUserId || "";
        form.innerHTML = ''
            + '<textarea placeholder="' + esc(placeholder) + '" maxlength="1000" required></textarea>'
            + '<div>'
            + '  <button type="submit">Ответить</button>'
            + '  <button type="button" class="btn-cancel">Отмена</button>'
            + '</div>';

        replies.parentNode.insertBefore(form, replies);

        form.querySelector(".btn-cancel").addEventListener("click", function () { form.remove(); });

        form.addEventListener("submit", function (e) {
            e.preventDefault();
            var textarea = form.querySelector("textarea");
            var body = textarea.value.trim();
            if (!body) return;

            var btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;

            api(CFG.urls.post, {
                club_id: CFG.id,
                body: body,
                parent_id: rootPostId,
                reply_to_user_id: replyToUserId || 0
            })
            .then(function (res) {
                if (!res.ok || !res.body.ok) {
                    alert((res.body && res.body.error) || "Ошибка");
                    return;
                }
                var d = res.body.data;
                d.can_edit = true;
                d.can_delete = true;
                replies.insertAdjacentHTML("beforeend", renderReplyHtml(d));
                textarea.value = "";
                form.remove();
            })
            .finally(function () { btn.disabled = false; });
        });

        form.querySelector("textarea").focus();
    }

    /* =========================================================
       4. КОПИРОВАНИЕ ИНВАЙТА
       ========================================================= */
    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-copy-invite");
        if (!btn) return;
        e.preventDefault();

        var wrap = btn.closest(".js-invite-wrap");
        if (!wrap) return;
        var input = wrap.querySelector(".js-invite-input");
        if (!input) return;

        function done() {
            btn.textContent = "✓ Скопировано";
            setTimeout(function () { btn.textContent = "📋 Скопировать ссылку"; }, 2000);
            showToast("Ссылка скопирована");
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done).catch(function () {
                input.select();
                document.execCommand("copy");
                done();
            });
        } else {
            input.select();
            document.execCommand("copy");
            done();
        }
    });

    document.addEventListener("DOMContentLoaded", function () {
        var input = document.querySelector(".js-invite-input");
        if (input && input.value && !sessionStorage.getItem("invite_copied_" + CFG.id)) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(function () {
                    showToast("Ссылка-приглашение скопирована");
                    sessionStorage.setItem("invite_copied_" + CFG.id, "1");
                }).catch(function () {});
            }
        }
    });

    function showToast(text) {
        var old = document.querySelector(".club-invite-toast");
        if (old) old.remove();
        var el = document.createElement("div");
        el.className = "club-invite-toast";
        el.textContent = text;
        document.body.appendChild(el);
        setTimeout(function () { el.remove(); }, 2500);
    }

    /* =========================================================
       5. МИНИ-КАРТЫ АКТИВНОСТЕЙ
       ========================================================= */
    function initMaps() {
        if (typeof L === "undefined") return;

        document.querySelectorAll(".club-activity-map[data-initialized='0']").forEach(function (el) {
            var raw = el.dataset.points;
            if (!raw) return;

            var pts;
            try { pts = JSON.parse(raw); } catch (err) { return; }
            if (!pts || pts.length < 2) return;

            el.dataset.initialized = "1";

            var map = L.map(el, {
                zoomControl: false, attributionControl: false,
                scrollWheelZoom: false, doubleClickZoom: false,
                dragging: false, touchZoom: false, boxZoom: false, keyboard: false
            });

            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                maxZoom: 19
            }).addTo(map);

            var ll = pts.map(function (p) { return [p.lat, p.lng]; });
            var line = L.polyline(ll, { color: "#e94f2e", weight: 4, opacity: 0.95 }).addTo(map);

            L.circleMarker(ll[0], { radius: 4, color: "#0a7a3a", fillColor: "#0a7a3a", fillOpacity: 1, weight: 1 }).addTo(map);
            L.circleMarker(ll[ll.length - 1], { radius: 4, color: "#b3261e", fillColor: "#b3261e", fillOpacity: 1, weight: 1 }).addTo(map);

            map.fitBounds(line.getBounds(), { padding: [8, 8] });
        });
    }

    /* =========================================================
       ЗАПУСК
       ========================================================= */
    function boot() {
        initWall();
        initMaps();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }

    document.addEventListener("click", function (e) {
        if (!e.target.closest("[data-menu-toggle]") && !e.target.closest(".club-wall-post__menu")) {
            closeAllMenus();
        }
    });
})();

    /* =========================================================
       6. СКРОЛЛ К ПОСТУ ПО ХЕШУ
       ========================================================= */
    (function () {
        var hash = location.hash;
        if (!hash || hash.indexOf("#post-") !== 0) return;

        var tryScroll = function () {
            var el = document.getElementById(hash.slice(1));
            if (!el) return false;
            el.scrollIntoView({ behavior: "smooth", block: "center" });
            el.style.transition = "background .6s";
            var prevBg = el.style.background;
            el.style.background = "#fff8e6";
            setTimeout(function () { el.style.background = prevBg || ""; }, 1600);
            return true;
        };

        // Пробуем сразу, потом через 300ms (если AJAX-вкладка ещё грузится)
        if (!tryScroll()) {
            setTimeout(tryScroll, 300);
        }
    })();
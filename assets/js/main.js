// Плавный скролл к якорям
document.querySelectorAll('a[href^="#"]').forEach((link) => {
    link.addEventListener('click', (e) => {
        const id = link.getAttribute('href');
        if (id.length < 2) return;
        const target = document.querySelector(id);
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

// Анимация появления карточек
const observer = new IntersectionObserver(
    (entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            }
        });
    },
    { threshold: 0.12 }
);

document.querySelectorAll('.feature, .cta, .activity-card').forEach((el) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(24px)';
    el.style.transition = 'opacity .6s ease, transform .6s ease';
    observer.observe(el);
});

/* ============================================================
   HEADER: mobile menu + user dropdown
   ============================================================ */
(function () {
    'use strict';

    // ---- Бургер и мобильное меню ----
    var burger = document.getElementById('burger');
    var mobileMenu = document.getElementById('mobile-menu');
    var mobileBackdrop = document.getElementById('mobile-menu-backdrop');

    function openMobileMenu() {
        if (!mobileMenu || !burger) return;
        mobileMenu.hidden = false;
        burger.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }

    function closeMobileMenu() {
        if (!mobileMenu || !burger) return;
        mobileMenu.hidden = true;
        burger.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    if (burger) {
        burger.addEventListener('click', function () {
            if (burger.getAttribute('aria-expanded') === 'true') {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        });
    }

    if (mobileBackdrop) {
        mobileBackdrop.addEventListener('click', closeMobileMenu);
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMobileMenu();
    });

    // ---- User dropdown ----
    var userToggle = document.getElementById('user-menu-toggle');
    var userDropdown = document.getElementById('user-menu-dropdown');

    function closeUserMenu() {
        if (!userDropdown || !userToggle) return;
        userDropdown.hidden = true;
        userToggle.setAttribute('aria-expanded', 'false');
    }

    if (userToggle && userDropdown) {
        userToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = !userDropdown.hidden;
            if (isOpen) {
                closeUserMenu();
            } else {
                userDropdown.hidden = false;
                userToggle.setAttribute('aria-expanded', 'true');
            }
        });

        // Клик вне меню — закрыть
        document.addEventListener('click', function (e) {
            if (!userDropdown.hidden && !userDropdown.contains(e.target) && e.target !== userToggle) {
                closeUserMenu();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeUserMenu();
        });
    }
})();

/* ============================================================
   POST CAROUSEL + LIGHTBOX
   ============================================================ */
(function () {
    'use strict';

    // ---------- Лайтбокс (универсальный) ----------
    var lb = null;
    var lbImg = null;
    var lbCounter = null;
    var lbDots = [];
    var currentGroup = [];
    var currentIndex = 0;

    function buildLightbox() {
        var el = document.createElement('div');
        el.className = 'lightbox';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.innerHTML =
            '<div class="lightbox__top">' +
                '<span class="lightbox__counter"></span>' +
                '<button type="button" class="lightbox__close" aria-label="Закрыть">×</button>' +
            '</div>' +
            '<div class="lightbox__stage">' +
                '<button type="button" class="lightbox__nav lightbox__nav--prev" aria-label="Предыдущее">‹</button>' +
                '<img class="lightbox__img" alt="">' +
                '<button type="button" class="lightbox__nav lightbox__nav--next" aria-label="Следующее">›</button>' +
            '</div>' +
            '<div class="lightbox__dots"></div>';
        document.body.appendChild(el);

        lb = el;
        lbImg = el.querySelector('.lightbox__img');
        lbCounter = el.querySelector('.lightbox__counter');

        el.querySelector('.lightbox__close').addEventListener('click', close);
        el.querySelector('.lightbox__nav--prev').addEventListener('click', function (e) { e.stopPropagation(); prev(); });
        el.querySelector('.lightbox__nav--next').addEventListener('click', function (e) { e.stopPropagation(); next(); });
        // клик по фону (не по картинке, не по кнопкам) — закрыть
        el.addEventListener('click', function (e) {
            if (e.target === el || e.target.classList.contains('lightbox__stage')) close();
        });
    }

    function renderDots() {
        var dotsWrap = lb.querySelector('.lightbox__dots');
        dotsWrap.innerHTML = '';
        lbDots = [];
        if (currentGroup.length <= 1) return;
        currentGroup.forEach(function (_, i) {
            var d = document.createElement('button');
            d.type = 'button';
            d.className = 'lightbox__dot' + (i === currentIndex ? ' is-active' : '');
            d.addEventListener('click', function (e) {
                e.stopPropagation();
                show(i);
            });
            dotsWrap.appendChild(d);
            lbDots.push(d);
        });
    }

    function show(i) {
        if (!currentGroup.length) return;
        currentIndex = (i + currentGroup.length) % currentGroup.length;
        var src = currentGroup[currentIndex];
        lbImg.style.opacity = '0';
        var pre = new Image();
        pre.onload = function () {
            lbImg.src = src;
            lbImg.style.opacity = '1';
        };
        pre.src = src;
        lbCounter.textContent = currentGroup.length > 1
            ? (currentIndex + 1) + ' / ' + currentGroup.length
            : '';
        lbDots.forEach(function (d, idx) {
            d.classList.toggle('is-active', idx === currentIndex);
        });
    }

    function open(group, index) {
        if (!lb) buildLightbox();
        currentGroup = group;
        currentIndex = index;
        show(index);
        renderDots();
        lb.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        if (!lb) return;
        lb.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    function prev() { show(currentIndex - 1); }
    function next() { show(currentIndex + 1); }

    // Escape и стрелки
    document.addEventListener('keydown', function (e) {
        if (!lb || !lb.classList.contains('is-open')) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowLeft') prev();
        if (e.key === 'ArrowRight') next();
    });

    // Свайпы в лайтбоксе
    (function () {
        var startX = null;
        document.addEventListener('touchstart', function (e) {
            if (!lb || !lb.classList.contains('is-open')) return;
            startX = e.touches[0].clientX;
        }, { passive: true });
        document.addEventListener('touchend', function (e) {
            if (startX === null || !lb || !lb.classList.contains('is-open')) return;
            var dx = e.changedTouches[0].clientX - startX;
            if (Math.abs(dx) > 50) dx < 0 ? next() : prev();
            startX = null;
        }, { passive: true });
    })();

    // Обработчик клика по любому [data-lightbox]
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-lightbox]');
        if (!trigger) return;
        e.preventDefault();

        var groupName = trigger.getAttribute('data-lightbox');
        var all = Array.prototype.slice.call(
            document.querySelectorAll('[data-lightbox="' + groupName + '"]')
        );
        var urls = all.map(function (el) {
            return el.getAttribute('data-src') || (el.querySelector('img') && el.querySelector('img').src) || '';
        });
        var startIdx = all.indexOf(trigger);
        open(urls, startIdx);
    });

    // ---------- Карусель поста ----------
    var track = document.getElementById('post-carousel-track');
    if (!track) return;

    var slides = track.querySelectorAll('.post-carousel__slide');
    var prevBtn = document.querySelector('.post-carousel__nav--prev');
    var nextBtn = document.querySelector('.post-carousel__nav--next');
    var dots = Array.prototype.slice.call(document.querySelectorAll('.post-carousel__dot'));

    function slideWidth() { return track.clientWidth; }

    function goTo(i) {
        var max = slides.length - 1;
        i = Math.max(0, Math.min(max, i));
        track.scrollTo({ left: i * slideWidth(), behavior: 'smooth' });
    }

    function currentIndexFromScroll() {
        if (!slideWidth()) return 0;
        return Math.round(track.scrollLeft / slideWidth());
    }

    function syncUI() {
        var idx = currentIndexFromScroll();
        dots.forEach(function (d, i) { d.classList.toggle('is-active', i === idx); });
        if (prevBtn) prevBtn.disabled = idx === 0;
        if (nextBtn) nextBtn.disabled = idx === slides.length - 1;
    }

    if (prevBtn) prevBtn.addEventListener('click', function () { goTo(currentIndexFromScroll() - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { goTo(currentIndexFromScroll() + 1); });
    dots.forEach(function (d, i) { d.addEventListener('click', function () { goTo(i); }); });

    var scrollTimer = null;
    track.addEventListener('scroll', function () {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(syncUI, 80);
    }, { passive: true });

    window.addEventListener('resize', function () { goTo(currentIndexFromScroll()); });

    syncUI();
})();

/* ============================================================
   УВЕДОМЛЕНИЯ В ХИДЕРЕ БЕЗ ПЕРЕЗАГРУЗКИ
   ============================================================ */
(function () {
    "use strict";

    var bell = document.getElementById("notif-bell");
    var badge = document.getElementById("notif-badge");
    if (!bell || !badge) return;

    var apiUrl = bell.dataset.notifApi;
    if (!apiUrl) return;

    var POLL_INTERVAL = 20000; // 20 сек
    var latestId = 0;
    var firstLoad = true;
    var timerId = null;
    var lastUnread = parseInt(badge.textContent, 10) || 0;

    // --- Контейнер для тостов ---
    var toastWrap = document.getElementById("notif-toasts");
    if (!toastWrap) {
        toastWrap = document.createElement("div");
        toastWrap.id = "notif-toasts";
        toastWrap.className = "notif-toasts";
        document.body.appendChild(toastWrap);
    }

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/\x27/g, "&#039;");
    }

    function updateBadge(count) {
        count = parseInt(count, 10) || 0;
        if (count > 0) {
            badge.hidden = false;
            badge.textContent = count > 99 ? "99+" : String(count);
            if (count > lastUnread) {
                badge.classList.remove("badge--pulse");
                void badge.offsetWidth;
                badge.classList.add("badge--pulse");
                setTimeout(function () { badge.classList.remove("badge--pulse"); }, 1500);
            }
        } else {
            badge.hidden = true;
            badge.textContent = "0";
        }
        lastUnread = count;
    }

    function toastText(item) {
        var actor = item.actor_name ? "<strong>" + escapeHtml(item.actor_name) + "</strong>" : "";
        switch (item.type) {
            case "like":               return actor + " оценил вашу активность";
            case "comment":            return actor + " оставил комментарий";
            case "comment_reply":      return actor + " ответил на ваш комментарий";
            case "follow":             return actor + " подписался на вас";
            case "mention":            return actor + " упомянул вас";
            case "post_like":          return actor + " оценил вашу запись";
            case "post_comment":       return actor + " оставил комментарий к записи";
            case "post_comment_reply": return actor + " ответил на ваш комментарий к записи";
            case "segment_new_lead":   return "Вы вышли на <strong>1-е место</strong> на сегменте";
            case "segment_lost_lead":  return actor + " обошёл вас на сегменте";
            case "club_join":          return escapeHtml(item.message || (actor + " вступил в клуб"));
            case "club_post":          return escapeHtml(item.message || (actor + " написал на стене клуба"));
            case "club_role":          return escapeHtml(item.message || "Ваша роль в клубе изменена");
            case "club_event":         return escapeHtml(item.message || "Новое событие в клубе");
            case "system":             return escapeHtml(item.message || "Системное уведомление");
            default:                   return escapeHtml(item.message || "Уведомление");
        }
    }

    function showToast(item) {
        var toast = document.createElement("a");
        toast.className = "notif-toast";
        toast.href = item.url || "#";
        toast.innerHTML =
            '<span class="notif-toast__icon">🔔</span>' +
            '<span class="notif-toast__body">' +
                '<span class="notif-toast__text">' + toastText(item) + '</span>' +
                '<span class="notif-toast__time">' + escapeHtml(item.time_ago || "только что") + '</span>' +
            '</span>';

        toast.addEventListener("click", function () {
            markRead(item.id);
        });

        toastWrap.appendChild(toast);
        requestAnimationFrame(function () {
            toast.classList.add("is-visible");
        });
        setTimeout(function () {
            toast.classList.remove("is-visible");
            setTimeout(function () { toast.remove(); }, 300);
        }, 6000);
    }

    function markRead(id) {
        fetch(apiUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.__CSRF__ || "",
                "Accept": "application/json"
            },
            body: JSON.stringify({ action: "mark_read", id: id })
        }).catch(function () {});
    }

    function poll() {
        var url = apiUrl + "?since_id=" + encodeURIComponent(latestId) + "&limit=10";
        fetch(url, {
            credentials: "same-origin",
            headers: { "Accept": "application/json" }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.ok) return;
            var data = res.data || {};
            updateBadge(data.unread || 0);

            if (data.latest_id) latestId = data.latest_id;

            if (firstLoad) { firstLoad = false; return; }

            var items = data.items || [];
            items.slice(0, 3).forEach(function (it, idx) {
                setTimeout(function () { showToast(it); }, idx * 250);
            });
        })
        .catch(function () {});
    }

    poll();
    timerId = setInterval(poll, POLL_INTERVAL);

    document.addEventListener("visibilitychange", function () {
        if (document.hidden) {
            if (timerId) { clearInterval(timerId); timerId = null; }
        } else {
            poll();
            if (!timerId) timerId = setInterval(poll, POLL_INTERVAL);
        }
    });
})();
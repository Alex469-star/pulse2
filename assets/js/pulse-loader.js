/**
 * PulseLoader — глобальный индикатор загрузки в виде пульсирующего сердца
 * с пробегающей ЭКГ-линией.
 *
 * Публичный API:
 *   PulseLoader.show(label, hint)   — показать оверлей
 *   PulseLoader.hide()              — скрыть
 *   PulseLoader.progress(0..1)      — обновить прогресс-бар
 *   PulseLoader.setLabel(text)      — сменить текст
 *   PulseLoader.setHint(text)       — сменить подсказку
 *   PulseLoader.pageLoading()       — тонкая полоска сверху (загрузка страницы)
 *   PulseLoader.pageLoaded()        — убрать полоску
 *
 * Автоматически:
 *   - показывает тонкую полоску сверху, если страница грузится > 1200 мс;
 *   - показывает оверлей с сердцем, если fetch/XHR длится > 700 мс.
 *
 * Не требует зависимостей. Не ломает существующий код.
 */
(function (window, document) {
    "use strict";

    if (window.PulseLoader) return; // защита от двойной инициализации

    /* ---------- Путь к CSS берём из data-css на теге скрипта ---------- */
    var CSS_HREF = null;
    var scriptEl = document.currentScript;
    if (scriptEl) {
        CSS_HREF = scriptEl.getAttribute("data-css");
    }

    /* ---------- Состояние ---------- */
    var state = {
        overlayEl: null,
        topbarEl: null,
        labelEl: null,
        hintEl: null,
        progressEl: null,
        progressBarEl: null,
        activeRequests: 0,
        overlayTimer: null,
        topbarTimer: null,
        topbarTick: null,
        pageStart: performance.now(),
        pageDone: false,
        manual: false,
        manualProgress: null,
        lastAutoLabel: "Загрузка…"
    };

    /* ---------- Инжект CSS ---------- */
    function injectCss() {
        if (!CSS_HREF) return;
        if (document.querySelector('link[data-pulse-loader]')) return;
        var link = document.createElement("link");
        link.rel = "stylesheet";
        link.href = CSS_HREF;
        link.setAttribute("data-pulse-loader", "1");
        document.head.appendChild(link);
    }

    /* ---------- Создание DOM ---------- */
    function ensureTopbar() {
        if (state.topbarEl) return state.topbarEl;
        var el = document.createElement("div");
        el.className = "pulse-topbar";
        el.id = "pulse-topbar";
        el.innerHTML = '<div class="pulse-topbar__bar"></div>';
        document.body.appendChild(el);
        state.topbarEl = el;
        return el;
    }

    function ensureOverlay() {
        if (state.overlayEl) return state.overlayEl;

        var el = document.createElement("div");
        el.className = "pulse-overlay";
        el.id = "pulse-overlay";
        el.hidden = true;
        el.setAttribute("role", "status");
        el.setAttribute("aria-live", "polite");

        el.innerHTML =
            '<div class="pulse-overlay__inner">' +
                '<div class="pulse-heart">' +
                    '<svg class="pulse-heart__icon" viewBox="0 0 100 100" aria-hidden="true">' +
                        '<path d="M50 84 C 22 66, 6 48, 6 32 C 6 18, 16 8, 28 8 C 37 8, 44 13, 50 22 C 56 13, 63 8, 72 8 C 84 8, 94 18, 94 32 C 94 48, 78 66, 50 84 Z"/>' +
                    '</svg>' +
                    '<svg class="pulse-heart__ekg" viewBox="0 0 400 60" preserveAspectRatio="none" aria-hidden="true">' +
                        '<path class="pulse-heart__ekg-path" d="M0 30 L120 30 L135 30 L145 12 L155 48 L165 22 L175 30 L260 30 L272 30 L282 18 L292 42 L302 26 L312 30 L400 30"/>' +
                    '</svg>' +
                '</div>' +
                '<div class="pulse-overlay__label" id="pulse-label">Загрузка…</div>' +
                '<div class="pulse-overlay__hint"  id="pulse-hint"></div>' +
                '<div class="pulse-overlay__progress">' +
                    '<div class="pulse-overlay__progress-bar" id="pulse-progress-bar"></div>' +
                '</div>' +
            '</div>';

        document.body.appendChild(el);

        state.overlayEl     = el;
        state.labelEl       = el.querySelector("#pulse-label");
        state.hintEl        = el.querySelector("#pulse-hint");
        state.progressEl    = el.querySelector(".pulse-overlay__progress");
        state.progressBarEl = el.querySelector("#pulse-progress-bar");

        return el;
    }

    /* ---------- Публичные действия ---------- */
    function show(label, hint) {
        state.manual = true;
        var el = ensureOverlay();
        el.hidden = false;
        void el.offsetWidth; // reflow, чтобы transition сработал
        el.classList.add("is-visible");

        if (label !== undefined && label !== null) {
            state.labelEl.textContent = label;
        }
        if (hint !== undefined && hint !== null && hint !== "") {
            state.hintEl.textContent = hint;
            state.hintEl.style.display = "";
        } else {
            state.hintEl.textContent = "";
            state.hintEl.style.display = "none";
        }
    }

    function hide() {
        state.manual = false;
        state.manualProgress = null;
        if (state.overlayEl) {
            state.overlayEl.classList.remove("is-visible");
            setTimeout(function () {
                if (state.overlayEl && !state.overlayEl.classList.contains("is-visible")) {
                    state.overlayEl.hidden = true;
                }
            }, 280);
        }
    }

    function progress(p) {
        state.manualProgress = p;
        if (!state.progressBarEl) return;
        var pct = Math.max(0, Math.min(1, p)) * 100;
        state.progressBarEl.style.width = pct.toFixed(1) + "%";
    }

    function setLabel(text) {
        if (state.labelEl) state.labelEl.textContent = text || "";
    }

    function setHint(text) {
        if (!state.hintEl) return;
        state.hintEl.textContent = text || "";
        state.hintEl.style.display = text ? "" : "none";
    }

    /* ---------- Тонкая полоска сверху ---------- */
    function pageLoading() {
        var el = ensureTopbar();
        el.classList.add("is-visible");
        setTopbarProgress(0.12);

        if (state.topbarTick) clearInterval(state.topbarTick);
        var p = 0.12;
        state.topbarTick = setInterval(function () {
            if (state.pageDone) {
                clearInterval(state.topbarTick);
                state.topbarTick = null;
                return;
            }
            p += (0.9 - p) * 0.06;
            setTopbarProgress(p);
        }, 220);
    }

    function setTopbarProgress(p) {
        if (!state.topbarEl) return;
        var bar = state.topbarEl.querySelector(".pulse-topbar__bar");
        if (!bar) return;
        bar.style.width = (Math.max(0, Math.min(1, p)) * 100).toFixed(1) + "%";
    }

    function pageLoaded() {
        state.pageDone = true;
        if (state.topbarTick) {
            clearInterval(state.topbarTick);
            state.topbarTick = null;
        }
        if (!state.topbarEl) return;
        setTopbarProgress(1);
        setTimeout(function () {
            state.topbarEl.classList.remove("is-visible");
            setTimeout(function () { setTopbarProgress(0); }, 260);
        }, 200);
    }

    /* ---------- Автопоказ при загрузке страницы ---------- */
    if (document.readyState !== "complete") {
        state.topbarTimer = setTimeout(function () {
            state.topbarTimer = null;
            if (!state.pageDone && !state.manual) {
                pageLoading();
            }
        }, 1200);

        window.addEventListener("load", function () {
            if (state.topbarTimer) {
                clearTimeout(state.topbarTimer);
                state.topbarTimer = null;
            }
            pageLoaded();
        });
    } else {
        state.pageDone = true;
    }

    /* ---------- Перехват fetch ---------- */
    var origFetch = window.fetch;
    if (typeof origFetch === "function") {
        window.fetch = function () {
            var args = arguments;
            beginRequest("Загрузка…");
            return origFetch.apply(this, args).finally(function () {
                endRequest();
            });
        };
    }

    /* ---------- Перехват XHR ---------- */
    var origOpen = XMLHttpRequest.prototype.open;
    var origSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this.__pulseUrl = url;
        this.__pulseMethod = method;
        this.__pulseIntercepted = true;
        return origOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
        var xhr = this;
        if (xhr.__pulseIntercepted) {
            xhr.addEventListener("loadstart", function () { beginRequest("Загрузка…"); });
            xhr.addEventListener("loadend",   function () { endRequest(); });
        }
        return origSend.apply(this, arguments);
    };

    /* ---------- Учёт активных запросов ---------- */
    var MIN_SHOW_MS = 700;

    function beginRequest(label) {
        state.activeRequests++;
        if (state.manual) return;
        if (state.overlayTimer) return;

        state.overlayTimer = setTimeout(function () {
            state.overlayTimer = null;
            if (state.activeRequests > 0 && !state.manual) {
                showAuto(label);
            }
        }, MIN_SHOW_MS);
    }

    function endRequest() {
        state.activeRequests = Math.max(0, state.activeRequests - 1);
        if (state.activeRequests === 0 && !state.manual) {
            if (state.overlayTimer) {
                clearTimeout(state.overlayTimer);
                state.overlayTimer = null;
            }
            hideAuto();
        }
    }

    function showAuto(label) {
        var el = ensureOverlay();
        el.hidden = false;
        void el.offsetWidth;
        el.classList.add("is-visible");
        setLabel(label || state.lastAutoLabel);
        setHint("");
        if (state.progressBarEl) {
            state.progressBarEl.style.width = "30%";
        }
    }

    function hideAuto() {
        if (state.overlayEl) {
            state.overlayEl.classList.remove("is-visible");
            setTimeout(function () {
                if (state.overlayEl
                    && !state.overlayEl.classList.contains("is-visible")
                    && !state.manual) {
                    state.overlayEl.hidden = true;
                }
            }, 280);
        }
    }

    /* ---------- Экспорт ---------- */
    window.PulseLoader = {
        show:        show,
        hide:        hide,
        progress:    progress,
        setLabel:    setLabel,
        setHint:     setHint,
        pageLoading: pageLoading,
        pageLoaded:  pageLoaded
    };

    injectCss();
})(window, document);
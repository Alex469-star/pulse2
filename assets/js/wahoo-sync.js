(function () {
    "use strict";

    var btn = document.getElementById("wahoo-sync-btn");
    if (!btn) return;

    var API = btn.dataset.api;
    var CSRF = btn.dataset.csrf;
    var progressBox = document.getElementById("wahoo-sync-progress");
    var progressBar = document.getElementById("wahoo-sync-progress-bar");
    var progressText = document.getElementById("wahoo-sync-progress-text");
    var resultsBox = document.getElementById("wahoo-sync-results");

    function setProgress(pct, text) {
        if (progressBox) progressBox.hidden = false;
        if (progressBar) progressBar.style.width = Math.max(0, Math.min(100, pct)) + "%";
        if (progressText) progressText.textContent = text || "";
    }

    function showResults(data) {
        if (!resultsBox) return;
        resultsBox.hidden = false;

        var msg = "Создано активностей: <strong>" + data.created + "</strong>. ";
        if (data.skipped) msg += "Пропущено (уже есть): " + data.skipped + ". ";
        if (data.errors)  msg += "Ошибок: " + data.errors + ". ";

        resultsBox.innerHTML = '<div class="alert alert--success">' + msg + "</div>";
    }

    function showError(msg) {
        if (!resultsBox) { alert(msg); return; }
        resultsBox.hidden = false;
        resultsBox.innerHTML = '<div class="alert alert--error">' + msg + "</div>";
    }

    function syncPage(page, totalCreated) {
        setProgress(10 + Math.min(80, page * 10), "Страница " + page + "…");

        var fd = new FormData();
        fd.append("page", String(page));

        fetch(API, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "X-CSRF-Token": CSRF,
                "Accept": "application/json"
            },
            body: fd
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
        .then(function (res) {
            if (!res.ok || !res.body.ok) {
                throw new Error((res.body && res.body.error) || "Ошибка синхронизации");
            }

            var d = res.body.data;
            totalCreated.created += d.created;
            totalCreated.skipped += d.skipped;
            totalCreated.errors  += d.errors;

            if (d.has_more && page < 10) {
                syncPage(page + 1, totalCreated);
            } else {
                setProgress(100, "Готово");
                showResults(totalCreated);
                btn.disabled = false;
                btn.textContent = btn.dataset.originalText || "Синхронизировать тренировки";
            }
        })
        .catch(function (err) {
            setProgress(0, "");
            showError("Ошибка: " + err.message);
            btn.disabled = false;
            btn.textContent = btn.dataset.originalText || "Синхронизировать тренировки";
        });
    }

    btn.addEventListener("click", function () {
        if (btn.disabled) return;
        btn.dataset.originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = "Синхронизация…";

        if (resultsBox) { resultsBox.hidden = true; resultsBox.innerHTML = ""; }

        syncPage(1, { created: 0, skipped: 0, errors: 0 });
    });
})();
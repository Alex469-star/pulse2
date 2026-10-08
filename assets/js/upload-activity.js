/**
 * AJAX-загрузка активностей с прогресс-баром.
 *
 * Алгоритм:
 *   1. Собираем файлы активностей и фото из формы.
 *   2. Последовательно отправляем файлы активностей на api/upload-activity.php.
 *   3. Для каждого фото — отдельный запрос на api/upload-activity-photo.php.
 *   4. Если API отвечает 409 — файл помечается как «пропущен» (дубликат).
 *   5. Обновляем прогресс-бар и статус.
 */
(function () {
    "use strict";

    var form = document.getElementById("upload-form");
    if (!form) return;

    var filesInput      = document.getElementById("files");
    var photosInput     = document.getElementById("photos");
    var titleInput      = document.getElementById("title");
    var typeInput       = document.getElementById("type");
    var visibilityInput = document.getElementById("visibility");
    var gearInput       = document.getElementById("gear_id");

    var submitBtn    = document.getElementById("upload-submit");
    var resultsBox   = document.getElementById("upload-results");
    var progressBox  = document.getElementById("upload-progress");
    var progressBar  = document.getElementById("upload-progress-bar");
    var progressText = document.getElementById("upload-progress-text");
    var progressHint = document.getElementById("upload-progress-hint");

    if (!filesInput || !submitBtn) return;

    var API_ACTIVITY = form.dataset.apiActivity;
    var API_PHOTO    = form.dataset.apiPhoto;
    var CSRF         = form.dataset.csrf;
    var ACTIVITY_URL = form.dataset.activityUrl;
    var FEED_URL     = form.dataset.feedUrl;

    var heicConverterAvailable = typeof heic2any !== "undefined";

    /* ---------- Утилиты ---------- */

    function setProgress(pct, text, hint) {
        if (progressBox) progressBox.hidden = false;
        if (progressBar) progressBar.style.width = Math.max(0, Math.min(100, pct)) + "%";
        if (progressText && text !== undefined) progressText.textContent = text;
        if (progressHint && hint !== undefined) progressHint.textContent = hint || "";
    }

    function resetProgress() {
        if (progressBox) progressBox.hidden = true;
        if (progressBar) progressBar.style.width = "0%";
    }

    function setButtonLoading(loading) {
        if (loading) {
            submitBtn.disabled = true;
            if (!submitBtn.dataset.originalText) {
                submitBtn.dataset.originalText = submitBtn.textContent;
            }
            submitBtn.textContent = "Загрузка…";
        } else {
            submitBtn.disabled = false;
            if (submitBtn.dataset.originalText) {
                submitBtn.textContent = submitBtn.dataset.originalText;
            }
        }
    }

    function renderError(msg) {
        if (!resultsBox) return;
        resultsBox.hidden = false;
        resultsBox.innerHTML =
            '<div class="upload-result upload-result--error">' +
                '<div class="upload-result__head">' +
                    '<span class="upload-result__icon">✕</span>' +
                    '<span class="upload-result__name">Ошибка</span>' +
                '</div>' +
                '<div class="upload-result__message">' + escapeHtml(msg) + '</div>' +
            '</div>';
    }

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    /* ---------- Отправка одного файла ---------- */

    /**
     * Возвращает Promise с объектом { ok: bool, status: int, data: {}, error: string }.
     * Не reject-ит на HTTP-ошибки — это позволяет отличить 409 (дубликат) от сетевой ошибки.
     */
    function uploadFile(url, file, fields, onProgress) {
        return new Promise(function (resolve) {
            var xhr = new XMLHttpRequest();
            xhr.open("POST", url, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader("X-CSRF-Token", CSRF);
            xhr.setRequestHeader("Accept", "application/json");

            xhr.upload.onprogress = function (e) {
                if (e.lengthComputable && typeof onProgress === "function") {
                    onProgress(e.loaded, e.total);
                }
            };

            xhr.onload = function () {
                var data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) {}

                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve({ ok: true, status: xhr.status, data: data.data || {}, error: null });
                } else {
                    var msg = (data && data.error) || ("HTTP " + xhr.status);
                    resolve({
                        ok: false,
                        status: xhr.status,
                        data: data || {},
                        error: msg
                    });
                }
            };

            xhr.onerror = function () {
                resolve({ ok: false, status: 0, data: {}, error: "Сетевая ошибка" });
            };
            xhr.onabort = function () {
                resolve({ ok: false, status: 0, data: {}, error: "Отменено" });
            };

            var fd = new FormData();
            Object.keys(fields || {}).forEach(function (k) {
                fd.append(k, fields[k]);
            });
            fd.append("file", file, file.name);

            xhr.send(fd);
        });
    }

    /* ---------- HEIC-конвертация ---------- */

    function isHeic(file) {
        var n = (file.name || "").toLowerCase();
        return n.endsWith(".heic") || n.endsWith(".heif");
    }

    function convertHeic(files) {
        if (!heicConverterAvailable) return Promise.resolve(files);
        if (!files.some(isHeic)) return Promise.resolve(files);

        var out = [];
        var chain = Promise.resolve();

        Array.prototype.forEach.call(files, function (file) {
            chain = chain.then(function () {
                if (!isHeic(file)) {
                    out.push(file);
                    return;
                }
                return heic2any({ blob: file, toType: "image/jpeg", quality: 0.85 })
                    .then(function (result) {
                        var blob = Array.isArray(result) ? result[0] : result;
                        var newName = file.name.replace(/\.(heic|heif)$/i, ".jpg");
                        out.push(new File([blob], newName, { type: "image/jpeg" }));
                    })
                    .catch(function (err) {
                        console.error("HEIC:", err);
                        out.push(file);
                    });
            });
        });

        return chain.then(function () { return out; });
    }

    /* ---------- Основной процесс загрузки ---------- */

    function handleSubmit(e) {
        e.preventDefault();

        var activityFiles = filesInput.files ? Array.from(filesInput.files) : [];
        var photoFiles = photosInput && photosInput.files ? Array.from(photosInput.files) : [];

        if (!activityFiles.length) {
            renderError("Выберите хотя бы один файл активности");
            return;
        }

        if (resultsBox) {
            resultsBox.hidden = true;
            resultsBox.innerHTML = "";
        }

        setButtonLoading(true);
        setProgress(0, "Подготовка…", "");

        var commonFields = {
            title: titleInput ? titleInput.value : "",
            type: typeInput ? typeInput.value : "run",
            visibility: visibilityInput ? visibilityInput.value : "public",
            gear_id: gearInput ? gearInput.value : "",
            is_multi: activityFiles.length > 1 ? "1" : "0"
        };

        var createdActivities = [];
        var skippedActivities = [];
        var errors = [];

        // === 1. Загрузка файлов активностей ===
        var activityChain = Promise.resolve();
        activityFiles.forEach(function (file, idx) {
            activityChain = activityChain.then(function () {
                setProgress(
                    0,
                    "Загрузка активности " + (idx + 1) + " из " + activityFiles.length,
                    file.name
                );

                return uploadFile(API_ACTIVITY, file, commonFields, function (loaded, total) {
                    var overall = ((idx + loaded / Math.max(1, total)) / activityFiles.length) * 60;
                    setProgress(
                        overall,
                        "Загрузка активности " + (idx + 1) + " из " + activityFiles.length,
                        file.name
                    );
                })
                .then(function (res) {
                    if (res.ok) {
                        createdActivities.push({ file: file.name, data: res.data });
                        return;
                    }
                    if (res.status === 409) {
                        // Дубликат
                        skippedActivities.push({
                            file: file.name,
                            message: res.error,
                            duplicateId: res.data && res.data.duplicate_id,
                            duplicateUrl: res.data && res.data.duplicate_url
                        });
                        return;
                    }
                    errors.push({ file: file.name, message: res.error });
                });
            });
        });

        // === 2. Загрузка фото ===
        activityChain.then(function () {
            if (!photoFiles.length || !createdActivities.length) return;

            var targetActivityId = createdActivities[0].data.activity_id;

            setProgress(60, "Конвертация фото…", "");
            return convertHeic(photoFiles).then(function (converted) {
                var photoChain = Promise.resolve();
                converted.forEach(function (file, idx) {
                    photoChain = photoChain.then(function () {
                        var pct = 60 + ((idx + 1) / converted.length) * 40;
                        setProgress(
                            pct,
                            "Загрузка фото " + (idx + 1) + " из " + converted.length,
                            file.name
                        );
                        return uploadFile(API_PHOTO, file, {
                            activity_id: targetActivityId,
                            order: idx
                        }).then(function (res) {
                            if (!res.ok) {
                                errors.push({ file: file.name, message: res.error });
                            }
                        });
                    });
                });
                return photoChain;
            });
        })
        .then(function () {
            setProgress(100, "Готово", "");
            setTimeout(function () {
                setButtonLoading(false);
                renderResults(createdActivities, skippedActivities, errors);
            }, 300);
        })
        .catch(function (err) {
            console.error(err);
            setButtonLoading(false);
            resetProgress();
            renderError("Ошибка: " + err.message);
        });
    }

    /* ---------- Рендер результатов ---------- */

    function renderResults(created, skipped, errors) {
        if (!resultsBox) return;
        resultsBox.hidden = false;

        var html = "";
        var total = created.length + skipped.length + errors.length;
        var okCount = created.length;
        var skipCount = skipped.length;

        html += '<div class="upload-results">';
        html += '<h2 class="upload-results__title">Результат загрузки: ';
        html += '<span class="' + (okCount > 0 ? "text-success" : "text-error") + '">';
        html += okCount + " из " + total + "</span>";
        if (skipCount > 0) {
            html += ' <span class="muted">(' + skipCount + ' пропущено)</span>';
        }
        html += "</h2>";

        // --- Успешно загруженные ---
        created.forEach(function (item) {
            var d = item.data;
            var s = d.summary || {};
            html += '<div class="upload-result upload-result--ok">';
            html += '<div class="upload-result__head"><span class="upload-result__icon">✓</span>';
            html += '<span class="upload-result__name">' + escapeHtml(item.file) + "</span></div>";
            html += '<div class="upload-result__message">Загружено успешно</div>';
            html += '<div class="upload-result__summary">';
            html += '<span>' + escapeHtml(formatDistance(s.distance_m)) + "</span>";
            html += '<span>' + escapeHtml(formatDuration(s.duration_sec)) + "</span>";
            if (s.elevation_gain_m) {
                html += "<span>↑" + Math.round(s.elevation_gain_m) + " м</span>";
            }
            if (s.points) {
                html += "<span>" + s.points + " точек</span>";
            }
            html += "</div>";
            html += '<a class="upload-result__link" href="' + escapeHtml(ACTIVITY_URL + d.activity_id) + '">Открыть активность →</a>';
            html += "</div>";
        });

        // --- Пропущенные (дубликаты) ---
        skipped.forEach(function (item) {
            html += '<div class="upload-result upload-result--skipped">';
            html += '<div class="upload-result__head"><span class="upload-result__icon">⏭</span>';
            html += '<span class="upload-result__name">' + escapeHtml(item.file) + "</span></div>";
            html += '<div class="upload-result__message">' + escapeHtml(item.message) + "</div>";
            if (item.duplicateUrl) {
                html += '<a class="upload-result__link" href="' + escapeHtml(item.duplicateUrl) + '">Открыть существующую →</a>';
            }
            html += "</div>";
        });

        // --- Ошибки ---
        errors.forEach(function (item) {
            html += '<div class="upload-result upload-result--error">';
            html += '<div class="upload-result__head"><span class="upload-result__icon">✕</span>';
            html += '<span class="upload-result__name">' + escapeHtml(item.file) + "</span></div>";
            html += '<div class="upload-result__message">' + escapeHtml(item.message) + "</div>";
            html += "</div>";
        });

        html += '<div class="upload-results__actions">';
        if (created.length) {
            html += '<a class="btn btn--primary" href="' + escapeHtml(FEED_URL) + '">Перейти в ленту</a>';
        }
        html += '<a class="btn btn--ghost" href="' + escapeHtml(window.location.pathname) + '">Загрузить ещё</a>';
        html += "</div></div>";

        resultsBox.innerHTML = html;
        resultsBox.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    /* ---------- Локальные форматеры ---------- */

    function formatDistance(m) {
        m = parseFloat(m) || 0;
        if (m <= 0) return "—";
        if (m < 1000) return Math.round(m) + " м";
        return (m / 1000).toFixed(2).replace(".", ",") + " км";
    }

    function formatDuration(sec) {
        sec = parseInt(sec, 10) || 0;
        if (sec <= 0) return "—";
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        function pad(n) { return n < 10 ? "0" + n : n; }
        return h > 0 ? h + ":" + pad(m) + ":" + pad(s) : m + ":" + pad(s);
    }

    /* ---------- Подписка ---------- */

    form.addEventListener("submit", handleSubmit);

    var heicProgress = document.getElementById("heic-progress");
    if (heicProgress && heicConverterAvailable) {
        heicProgress.hidden = true;
    }
})();
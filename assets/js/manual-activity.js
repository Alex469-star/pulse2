/**
 * Ручное добавление тренировки + загрузка фото с прогрессом.
 */
(function () {
    "use strict";

    var form = document.getElementById("manual-form");
    if (!form) return;

    var submitBtn     = document.getElementById("manual-submit");
    var resultsBox    = document.getElementById("manual-results");
    var photosInput   = document.getElementById("photos");
    var progressBox   = document.getElementById("manual-upload-progress");
    var progressBar   = document.getElementById("manual-upload-progress-bar");
    var progressText  = document.getElementById("manual-upload-progress-text");
    var progressHint  = document.getElementById("manual-upload-progress-hint");

    var API           = form.dataset.api;
    var API_PHOTO     = form.dataset.apiPhoto;
    var CSRF          = form.dataset.csrf;
    var ACTIVITY_URL  = form.dataset.activityUrl;

    var heicAvailable = typeof heic2any !== "undefined";

    /* ---------- Утилиты ---------- */

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    function showError(msg) {
        if (!resultsBox) { alert(msg); return; }
        resultsBox.hidden = false;
        resultsBox.innerHTML = '<div class="alert alert--error">' + escapeHtml(msg) + '</div>';
        resultsBox.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    function setLoading(loading) {
        if (!submitBtn) return;
        if (loading) {
            submitBtn.disabled = true;
            if (!submitBtn.dataset.originalText) {
                submitBtn.dataset.originalText = submitBtn.textContent;
            }
            submitBtn.textContent = "Сохранение…";
        } else {
            submitBtn.disabled = false;
            if (submitBtn.dataset.originalText) {
                submitBtn.textContent = submitBtn.dataset.originalText;
            }
        }
    }

    function setProgress(pct, text, hint) {
        if (progressBox) progressBox.hidden = false;
        if (progressBar) progressBar.style.width = Math.max(0, Math.min(100, pct)) + "%";
        if (progressText && text !== undefined) progressText.textContent = text;
        if (progressHint && hint !== undefined) progressHint.textContent = hint || "";
    }

    /* ---------- Отправка файла с прогрессом ---------- */

    function uploadPhoto(activityId, file, order) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open("POST", API_PHOTO, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader("X-CSRF-Token", CSRF);
            xhr.setRequestHeader("Accept", "application/json");

            xhr.upload.onprogress = function (e) {
                if (e.lengthComputable) {
                    var pct = (e.loaded / e.total) * 100;
                    resolve.onprogress && resolve.onprogress(pct);
                }
            };

            xhr.onload = function () {
                var data = null;
                try { data = JSON.parse(xhr.responseText); } catch (err) {}
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve(data.data || {});
                } else {
                    reject(new Error((data && data.error) || ("HTTP " + xhr.status)));
                }
            };
            xhr.onerror = function () { reject(new Error("Сетевая ошибка")); };

            var fd = new FormData();
            fd.append("activity_id", activityId);
            fd.append("order", order);
            fd.append("file", file, file.name);
            xhr.send(fd);
        });
    }

    /* ---------- HEIC ---------- */

    function isHeic(file) {
        var n = (file.name || "").toLowerCase();
        return n.endsWith(".heic") || n.endsWith(".heif");
    }

    function convertHeic(files) {
        if (!heicAvailable || !files.some(isHeic)) return Promise.resolve(files);

        var out = [];
        var chain = Promise.resolve();

        Array.prototype.forEach.call(files, function (file) {
            chain = chain.then(function () {
                if (!isHeic(file)) { out.push(file); return; }
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

    /* ---------- Сабмит формы ---------- */

    form.addEventListener("submit", function (e) {
        e.preventDefault();

        if (resultsBox) {
            resultsBox.hidden = true;
            resultsBox.innerHTML = "";
        }

        var title = form.querySelector("#title").value.trim();
        var date  = form.querySelector("#started_date").value;

        if (!title) { showError("Введите название"); return; }
        if (!date)  { showError("Укажите дату тренировки"); return; }

        var photoFiles = photosInput && photosInput.files ? Array.from(photosInput.files) : [];

        setLoading(true);

        // Шаг 1 — создаём активность (без фото)
        var fd = new FormData(form);
        // Убираем файлы фото, чтобы не отправлять их в create-manual-activity
        fd.delete("photos[]");
        fd.set("csrf", CSRF);

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
                throw new Error((res.body && res.body.error) || "Ошибка сохранения");
            }

            var d = res.body.data;
            var activityId = d.activity_id;

            // Шаг 2 — если фото нет, сразу редирект
            if (!photoFiles.length) {
                finish(activityId, d.title);
                return;
            }

            // Шаг 3 — конвертация HEIC (если есть)
            setProgress(5, "Конвертация фото…", "");

            convertHeic(photoFiles).then(function (converted) {
                var done = 0;
                var errors = [];
                var chain = Promise.resolve();

                converted.forEach(function (file, idx) {
                    chain = chain.then(function () {
                        var basePct = 5 + (idx / converted.length) * 90;
                        setProgress(
                            basePct,
                            "Загрузка фото " + (idx + 1) + " из " + converted.length,
                            file.name
                        );

                        var uploadPromise = uploadPhoto(activityId, file, idx);

                        // Слушатель прогресса
                        uploadPromise.onprogress = function (pct) {
                            var overall = basePct + (pct / 100) * (90 / converted.length);
                            setProgress(overall, "Загрузка фото " + (idx + 1) + " из " + converted.length, file.name);
                        };

                        return uploadPromise
                            .then(function () { done++; })
                            .catch(function (err) {
                                errors.push({ file: file.name, message: err.message });
                            });
                    });
                });

                chain.then(function () {
                    setProgress(100, "Готово", "");
                    if (errors.length) {
                        // Записываем в лог, но не блокируем переход
                        console.warn("Часть фото не загрузилась:", errors);
                    }
                    finish(activityId, d.title);
                });
            });
        })
        .catch(function (err) {
            showError(err.message);
            setLoading(false);
        });
    });

    function finish(activityId, title) {
        if (resultsBox) {
            resultsBox.hidden = false;
            resultsBox.innerHTML =
                '<div class="alert alert--success">' +
                    'Тренировка «' + escapeHtml(title) + '» сохранена. ' +
                    '<a href="' + escapeHtml(ACTIVITY_URL + activityId) + '" style="font-weight:700">Открыть →</a>' +
                '</div>';
            resultsBox.scrollIntoView({ behavior: "smooth", block: "start" });
        }
        setTimeout(function () {
            window.location.href = ACTIVITY_URL + activityId;
        }, 700);
    }

    /* ---------- Автоподстановка даты ---------- */
    var dateInput = form.querySelector("#started_date");
    if (dateInput && !dateInput.value) {
        var now = new Date();
        var yyyy = now.getFullYear();
        var mm = String(now.getMonth() + 1).padStart(2, "0");
        var dd = String(now.getDate()).padStart(2, "0");
        dateInput.value = yyyy + "-" + mm + "-" + dd;
    }

    /* ---------- Placeholder дистанции по типу ---------- */
    var typeSelect = form.querySelector("#type");
    var distanceInput = form.querySelector("#distance_km");
    if (typeSelect && distanceInput) {
        var placeholders = {
            run: "10.5", ride: "45.0", swim: "1.5",
            ski: "15.0", walk: "5.0", hike: "8.0", other: "10.0"
        };
        typeSelect.addEventListener("change", function () {
            distanceInput.placeholder = placeholders[typeSelect.value] || "10.0";
        });
    }
})();
/**
 * AJAX-сохранение формы редактирования активности + загрузка фото с прогрессом.
 * + удаление существующих фото.
 */
(function () {
    "use strict";

    var form = document.getElementById("activity-edit-form");
    if (!form) return;

    var photosInput = document.getElementById("photos");
    var submitBtn   = form.querySelector('button[type="submit"]');
    var progressBox = document.getElementById("edit-upload-progress");
    var progressBar = document.getElementById("edit-upload-progress-bar");
    var progressText = document.getElementById("edit-upload-progress-text");
    var progressHint = document.getElementById("edit-upload-progress-hint");
    var resultsBox  = document.getElementById("edit-results");
    var heicProgress = document.getElementById("heic-progress");

    var API_UPDATE     = form.dataset.apiUpdate;
    var API_ADD_PHOTO  = form.dataset.apiAddPhoto;
    var API_DEL_PHOTO  = form.dataset.apiDelPhoto;
    var ACTIVITY_ID    = form.dataset.activityId;
    var ACTIVITY_URL   = form.dataset.activityUrl;
    var CSRF           = form.dataset.csrf;

    var heicAvailable = typeof heic2any !== "undefined";

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

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    /* ---------- Отправка одного файла с прогрессом ---------- */

    function uploadFile(url, file, fields, onProgress) {
        return new Promise(function (resolve, reject) {
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
                    resolve(data.data || {});
                } else {
                    reject(new Error((data && data.error) || ("HTTP " + xhr.status)));
                }
            };
            xhr.onerror = function () { reject(new Error("Сетевая ошибка")); };
            xhr.onabort = function () { reject(new Error("Отменено")); };

            var fd = new FormData();
            Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
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

    /* ---------- Удаление существующих фото ---------- */

    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".js-delete-activity-photo");
        if (!btn) return;
        e.preventDefault();
        if (!confirm("Удалить фото?")) return;

        var photoId = btn.dataset.photoId;
        btn.disabled = true;

        fetch(API_DEL_PHOTO, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": CSRF,
                "Accept": "application/json"
            },
            body: JSON.stringify({
                photo_id: photoId,
                activity_id: ACTIVITY_ID
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.ok) {
                var item = btn.closest(".post-photo-item");
                if (item) item.remove();
            } else {
                alert((res && res.error) || "Ошибка удаления");
                btn.disabled = false;
            }
        })
        .catch(function () {
            alert("Ошибка сети");
            btn.disabled = false;
        });
    });

    /* ---------- Основной сабмит формы ---------- */

    function handleSubmit(e) {
        e.preventDefault();

        var photoFiles = photosInput && photosInput.files ? Array.from(photosInput.files) : [];

        if (resultsBox) {
            resultsBox.hidden = true;
            resultsBox.innerHTML = "";
        }

        setButtonLoading(true);
        setProgress(0, "Сохранение изменений…", "");

        var fields = {
            activity_id: ACTIVITY_ID,
            title: form.querySelector("#title") ? form.querySelector("#title").value : "",
            description: form.querySelector("#description") ? form.querySelector("#description").value : "",
            type: form.querySelector("#type") ? form.querySelector("#type").value : "run",
            visibility: form.querySelector("#visibility") ? form.querySelector("#visibility").value : "public",
            gear_id: form.querySelector("#gear_id") ? form.querySelector("#gear_id").value : ""
        };

        // Шаг 1 — сохранение полей
        var xhr = new XMLHttpRequest();
        xhr.open("POST", API_UPDATE, true);
        xhr.withCredentials = true;
        xhr.setRequestHeader("X-CSRF-Token", CSRF);
        xhr.setRequestHeader("Accept", "application/json");

        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });

        xhr.onload = function () {
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) {}

            if (!(xhr.status >= 200 && xhr.status < 300 && data && data.ok)) {
                setButtonLoading(false);
                resetProgress();
                showError((data && data.error) || ("HTTP " + xhr.status));
                return;
            }

            // Шаг 2 — загрузка фото
            if (!photoFiles.length) {
                finish();
                return;
            }

            setProgress(40, "Конвертация фото…", "");
            convertHeic(photoFiles).then(function (converted) {
                var done = 0;
                var errors = [];
                var chain = Promise.resolve();

                converted.forEach(function (file, idx) {
                    chain = chain.then(function () {
                        return uploadFile(API_ADD_PHOTO, file, {
                            activity_id: ACTIVITY_ID,
                            order: idx
                        }, function (loaded, total) {
                            var perFile = total > 0 ? loaded / total : 0;
                            var overall = 40 + ((idx + perFile) / converted.length) * 60;
                            setProgress(overall, "Фото " + (idx + 1) + " из " + converted.length, file.name);
                        })
                        .then(function () { done++; })
                        .catch(function (err) {
                            errors.push({ file: file.name, message: err.message });
                        });
                    });
                });

                chain.then(function () {
                    if (errors.length) {
                        showError("Часть фото не загрузилась: " + errors.map(function (x) { return x.file + ": " + x.message; }).join("; "));
                    } else {
                        finish();
                    }
                });
            });
        };

        xhr.onerror = function () {
            setButtonLoading(false);
            resetProgress();
            showError("Сетевая ошибка при сохранении");
        };

        xhr.send(fd);
    }

    function finish() {
        setProgress(100, "Готово", "Перенаправление…");
        setTimeout(function () {
            window.location.href = ACTIVITY_URL + ACTIVITY_ID;
        }, 400);
    }

    function showError(msg) {
        if (!resultsBox) {
            alert(msg);
            return;
        }
        resultsBox.hidden = false;
        resultsBox.innerHTML =
            '<div class="alert alert--error">' + escapeHtml(msg) + '</div>';
    }

    form.addEventListener("submit", handleSubmit);
})();
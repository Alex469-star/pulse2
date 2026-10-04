<?php
declare(strict_types=1);

function config(?string $key = null)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/config.php';
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function url(string $path = ''): string
{
    $base = rtrim((string)config('base_url'), '/');
    return $base . '/' . ltrim($path, '/');
}

function app_url(string $path = ''): string
{
    $base = rtrim((string)config('app_url'), '/');
    return $base . '/' . ltrim($path, '/');
}

function flash(?string $message = null, string $type = 'info')
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token): void
{
    if (!$token || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(419);
        exit('CSRF token mismatch. Обновите страницу и попробуйте снова.');
    }
}

/* ============================================================
   ФОРМАТИРОВАНИЕ
   ============================================================ */

function format_distance(?float $meters): string
{
    if ($meters === null || $meters <= 0) return '—';
    if ($meters < 1000) return round($meters) . ' м';
    return number_format($meters / 1000, 2, '.', ' ') . ' км';
}

function format_duration(?int $seconds): string
{
    if ($seconds === null || $seconds <= 0) return '—';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}

function format_pace(?float $distance_m, ?int $duration_sec): string
{
    if (!$distance_m || !$duration_sec) return '—';
    if ($distance_m <= 0) return '—';

    $secPerKm = $duration_sec / ($distance_m / 1000);
    $m = intdiv((int)$secPerKm, 60);
    $s = (int)$secPerKm % 60;
    return sprintf('%d:%02d /км', $m, $s);
}

function time_ago(?string $datetime): string
{
    if ($datetime === null || $datetime === '') return 'только что';

    $ts = strtotime($datetime);
    if ($ts === false) return 'только что';

    $diff = time() - $ts;
    if ($diff < 0) return 'только что';
    if ($diff < 60) return 'только что';
    if ($diff < 3600) return floor($diff / 60) . ' мин назад';
    if ($diff < 86400) return floor($diff / 3600) . ' ч назад';
    if ($diff < 2592000) return floor($diff / 86400) . ' дн назад';

    return date('d.m.Y', $ts);
}

function safe_date(string $format, ?int $timestamp, string $default = '—'): string
{
    if ($timestamp === null || $timestamp <= 0) return $default;
    return date($format, $timestamp);
}

function str_short(?string $text, int $limit = 200, string $suffix = '…'): string
{
    $text = (string)$text;
    if (mb_strlen($text) <= $limit) return $text;

    $cut = mb_substr($text, 0, $limit);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $limit * 0.6) {
        $cut = mb_substr($cut, 0, $space);
    }
    return $cut . $suffix;
}

/* ============================================================
   ТЕКУЩАЯ СТРАНИЦА
   ============================================================ */

function current_script(): string
{
    return basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
}

function is_current(string $file): string
{
    return current_script() === $file ? 'is-active' : '';
}

/* ============================================================
   ФАЙЛЫ И ЛОГИ
   ============================================================ */

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function ensure_dir(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
}

function log_to_file(string $name, string $content): void
{
    $dir = __DIR__ . '/../storage';
    ensure_dir($dir);
    @file_put_contents(
        $dir . '/' . $name,
        '[' . date('Y-m-d H:i:s') . '] ' . $content . PHP_EOL,
        FILE_APPEND
    );
}

/* ============================================================
   ЗАГРУЗКА И ОПТИМИЗАЦИЯ ФОТО
   ============================================================ */

/**
 * Загружает фото, оптимизирует и сохраняет.
 * Возвращает ['url' => ..., 'error' => ...].
 *
 * @param array  $file    элемент из $_FILES
 * @param string $subdir  подпапка в assets/uploads/ (activities, posts, avatars, gear)
 * @param int    $userId  ID пользователя (для уникального имени файла)
 */
function upload_photo(array $file, string $subdir, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['url' => null, 'error' => 'Ошибка загрузки (код ' . (int)$file['error'] . ')'];
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        return ['url' => null, 'error' => 'Файл больше 10 МБ'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return ['url' => null, 'error' => 'Не изображение'];
    }

    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($info['mime'], $allowedMime, true)) {
        return ['url' => null, 'error' => 'Только JPG, PNG, WEBP'];
    }

    $ext = match ($info['mime']) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $dir = __DIR__ . '/../assets/uploads/' . $subdir;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $filename = 'u' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['url' => null, 'error' => 'Не удалось сохранить'];
    }

    // Сжимаем до 1600 по длинной стороне
    upload_photo_resize($target, 1600, 1600);

    return ['url' => url('assets/uploads/' . $subdir . '/' . $filename), 'error' => null];
}

/**
 * Уменьшает изображение до maxW × maxH и пересохраняет с оптимизацией.
 * Если GD нет — пишет предупреждение в storage/uploads.log.
 */
function upload_photo_resize(string $path, int $maxW, int $maxH): void
{
    if (!function_exists('imagecreatefromjpeg')) {
        log_to_file('uploads.log', 'GD не установлен — фото не сжато: ' . $path);
        return;
    }

    $info = @getimagesize($path);
    if (!$info) return;

    [$w, $h] = $info;
    $mime = $info['mime'];

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => null,
    };
    if (!$src) return;

    // Если картинка больше лимита — уменьшаем
    $needsResize = ($w > $maxW || $h > $maxH);
    $newW = $w;
    $newH = $h;

    if ($needsResize) {
        $ratio = min($maxW / $w, $maxH / $h);
        $newW = (int)round($w * $ratio);
        $newH = (int)round($h * $ratio);
    }

    // Создаём выходное изображение нужного размера
    $dst = imagecreatetruecolor($newW, $newH);

    // Для PNG сохраняем прозрачность
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        if ($mime === 'image/png') {
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);
        }
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

    // Пересохраняем с оптимизацией
    $saved = false;
    switch ($mime) {
        case 'image/jpeg':
            // quality 82 — оптимум между размером и качеством
            $saved = imagejpeg($dst, $path, 82);
            break;
        case 'image/png':
            // уровень сжатия 8 (0-9)
            $saved = imagepng($dst, $path, 8);
            break;
        case 'image/webp':
            // quality 82
            $saved = imagewebp($dst, $path, 82);
            break;
    }

    imagedestroy($src);
    imagedestroy($dst);

    if ($saved) {
        $newSize = @filesize($path);
        log_to_file('uploads.log', sprintf(
            'Фото обработано: %s | было %d×%d, стало %d×%d | размер %s КБ',
            basename($path),
            $w, $h, $newW, $newH,
            $newSize !== false ? round($newSize / 1024) : '?'
        ));
    }
}

/**
 * Удаляет файл фото по его URL, если он лежит в наших uploads.
 */
function delete_uploaded_photo(string $url): void
{
    $base = (string)config('base_url');
    if (strpos($url, $base . '/assets/uploads/') === false) return;

    $rel = substr($url, strlen($base) + 1); // assets/uploads/...
    $path = __DIR__ . '/../' . $rel;
    if (is_file($path)) @unlink($path);
}

/* ============================================================
   WAHOO OAUTH 2.0 + PKCE HELPERS
   ============================================================ */

/**
 * Генерирует code_verifier для PKCE (RFC 7636).
 * 64 символа из разрешённого набора [A-Za-z0-9-._~].
 */
function pkce_generate_verifier(int $length = 64): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
    $max = strlen($chars) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

/**
 * code_challenge = BASE64URL(SHA256(verifier)), без padding.
 */
function pkce_generate_challenge(string $verifier): string
{
    $hash = hash('sha256', $verifier, true);
    return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
}

/**
 * HTTP-запрос через cURL. Возвращает ['status' => int, 'body' => string, 'json' => ?array].
 */
function http_request(string $method, string $url, array $options = []): array
{
    $ch = curl_init($url);

    $headers = $options['headers'] ?? [];
    $body    = $options['body'] ?? null;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['status' => 0, 'body' => '', 'json' => null, 'error' => $error];
    }

    $json = json_decode($response, true);
    return [
        'status' => $status,
        'body'   => $response,
        'json'   => is_array($json) ? $json : null,
    ];
}
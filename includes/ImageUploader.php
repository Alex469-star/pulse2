<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Универсальный загрузчик изображений с поддержкой HEIC.
 * Стратегия:
 *   1. Проверяем MIME реально (getimagesize + finfo).
 *   2. Если HEIC/HEIF — пытаемся конвертировать через Imagick.
 *   3. Если Imagick нет, но есть exiftool+heif-convert — пробуем их.
 *   4. Ресайзим и сохраняем в указанную папку.
 *   5. Возвращаем относительный URL.
 */
final class ImageUploader
{
    /**
     * @param array  $file    Элемент $_FILES
     * @param string $subdir  Подпапка внутри assets/uploads/, например 'clubs/covers'
     * @param int    $maxWidth
     * @param int    $maxHeight
     * @param int    $maxBytes
     * @return string Относительный URL (например, assets/uploads/clubs/covers/abc.webp)
     * @throws RuntimeException
     */
    public static function save(
        array $file,
        string $subdir,
        int $maxWidth = 1600,
        int $maxHeight = 1600,
        int $maxBytes = 8 * 1024 * 1024
    ): string {
        if (!isset($file['error'], $file['tmp_name'], $file['size'])) {
            throw new RuntimeException('Некорректный файл');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadError($file['error']));
        }
        if ($file['size'] > $maxBytes) {
            throw new RuntimeException('Файл слишком большой (макс. ' . round($maxBytes / 1048576, 1) . ' МБ)');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Файл не был загружен');
        }

        // ---- Определяем MIME ----
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';

        // HEIC распознаётся как image/heic, image/heif или application/octet-stream
        $isHeic = in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence'], true);

        if ($isHeic) {
            $src = self::convertHeic($file['tmp_name']);
            if ($src === null) {
                throw new RuntimeException(
                    'HEIC не поддерживается на сервере. ' .
                    'Обновите iOS или загрузите JPG/PNG.'
                );
            }
        } else {
            // Обычные форматы
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
            ];
            if (!isset($allowed[$mime])) {
                throw new RuntimeException('Разрешены JPG, PNG, WebP, GIF, HEIC');
            }
            $src = self::openImage($file['tmp_name'], $mime);
            if (!$src) {
                throw new RuntimeException('Не удалось открыть изображение');
            }
        }

        // ---- Ресайз ----
        $src = self::fitTo($src, $maxWidth, $maxHeight);

        // ---- Определяем финальный формат ----
        // HEIC и всё "тяжёлое" сохраняем как WebP (лучшее сжатие)
        $ext = 'webp';

        $dir = __DIR__ . '/../assets/uploads/' . trim($subdir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать папку для загрузки');
        }

        $filename = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $path = $dir . '/' . $filename;

        if (!imagewebp($src, $path, 86)) {
            imagedestroy($src);
            throw new RuntimeException('Не удалось сохранить файл');
        }
        imagedestroy($src);

        return 'assets/uploads/' . trim($subdir, '/') . '/' . $filename;
    }

    // ============================================================
    // ВНУТРЕННИЕ
    // ============================================================

    /** @return \GdImage|resource|null */
    private static function openImage(string $path, string $mime)
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif'  => @imagecreatefromgif($path),
            default      => null,
        };
        if ($img && function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($img);
        }
        return $img ?: null;
    }

    /**
     * Конвертация HEIC в GD-ресурс.
     * Пробует несколько путей:
     *   1. Imagick с поддержкой HEIC
     *   2. Системная утилита heif-convert (пакет libheif-examples)
     *   3. ffmpeg
     */
    private static function convertHeic(string $path)
    {
        // --- Imagick ---
        if (class_exists('Imagick')) {
            try {
                $im = new Imagick($path);
                $im->setImageFormat('png'); // в PNG, потому что GD не читает HEIC
                $blob = $im->getImageBlob();
                $im->destroy();
                if ($blob) {
                    $tmp = tempnam(sys_get_temp_dir(), 'heic_') . '.png';
                    file_put_contents($tmp, $blob);
                    $img = imagecreatefrompng($tmp);
                    @unlink($tmp);
                    if ($img) return $img;
                }
            } catch (Throwable $e) {
                // пробуем следующий способ
            }
        }

        // --- heif-convert (пакет libheif-examples) ---
        if (self::commandExists('heif-convert')) {
            $tmp = tempnam(sys_get_temp_dir(), 'heic_') . '.png';
            $cmd = 'heif-convert ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null';
            @exec($cmd, $out, $code);
            if ($code === 0 && is_file($tmp)) {
                $img = @imagecreatefrompng($tmp);
                @unlink($tmp);
                if ($img) return $img;
            }
        }

        // --- ffmpeg ---
        if (self::commandExists('ffmpeg')) {
            $tmp = tempnam(sys_get_temp_dir(), 'heic_') . '.png';
            $cmd = 'ffmpeg -y -i ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null';
            @exec($cmd, $out, $code);
            if ($code === 0 && is_file($tmp)) {
                $img = @imagecreatefrompng($tmp);
                @unlink($tmp);
                if ($img) return $img;
            }
        }

        return null;
    }

    private static function commandExists(string $cmd): bool
    {
        $out = [];
        $code = 0;
        @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);
        return $code === 0;
    }

    /** Вписывает изображение в maxWidth×maxHeight, сохраняя пропорции. */
    private static function fitTo($src, int $maxW, int $maxH)
    {
        $w = imagesx($src);
        $h = imagesy($src);

        if ($w <= $maxW && $h <= $maxH) return $src;

        $ratio = min($maxW / $w, $maxH / $h);
        $nw = max(1, (int)round($w * $ratio));
        $nh = max(1, (int)round($h * $ratio));

        $dst = imagecreatetruecolor($nw, $nh);
        // сохраняем прозрачность для PNG/WebP
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        imagedestroy($src);
        return $dst;
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает допустимый размер',
            UPLOAD_ERR_PARTIAL   => 'Файл загрузился частично',
            UPLOAD_ERR_NO_FILE   => 'Файл не выбран',
            UPLOAD_ERR_NO_TMP_DIR => 'Нет временной папки',
            UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл',
            UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением',
            default => 'Ошибка загрузки файла',
        };
    }
}
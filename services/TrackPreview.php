<?php
declare(strict_types=1);

/**
 * TrackPreview — генерация превью трека активности в WebP.
 *
 * Использует MapBox Static Images API:
 *   https://docs.mapbox.com/api/maps/static-images/
 *
 * MapBox сам рисует линию трека, точки старта/финиша и подбирает
 * zoom/центр под трек (параметр `auto`). От нас требуется только:
 *   1. Извлечь координаты из track_json.
 *   2. Прореживать до ~100 точек (лимит длины URL).
 *   3. Собрать URL и скачать PNG.
 *   4. Сохранить как WebP.
 *
 * Если MapBox недоступен — рисует трек на стилизованной сетке
 * (fallback), чтобы превью всё равно было сгенерировано.
 */
class TrackPreview
{
    private const WIDTH   = 600;
    private const HEIGHT  = 300;
    private const SCALE   = 2;                // retina (@2x → 1200×600)
    private const HTTP_TIMEOUT = 10;
    private const MAX_PATH_POINTS = 90;       // лимит точек в URL MapBox

    /**
     * Генерирует превью трека и сохраняет как WebP.
     */
    public static function generate(int $activityId, string $trackJson, string $type = 'run'): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            log_to_file('track-preview.log', 'GD не установлен');
            return null;
        }

        $coords = self::extractCoords($trackJson);
        if (count($coords) < 2) {
            return null;
        }

        $colorHex = self::typeColor($type);

        // ---- Скачиваем карту с MapBox ----
        $bgImg = self::fetchMapBox($coords, $colorHex);

        // ---- Fallback: сетка ----
        if ($bgImg === null) {
            $bgImg = self::drawGridFallback($coords, $colorHex);
        }

        // ---- Сохраняем ----
        $url = self::saveImage($bgImg, $activityId);
        imagedestroy($bgImg);

        return $url;
    }

    public static function delete(int $activityId): void
    {
        $dir = __DIR__ . '/../assets/uploads/track-previews';
        foreach (['webp', 'png'] as $ext) {
            $file = $dir . '/' . $activityId . '.' . $ext;
            if (is_file($file)) @unlink($file);
        }
    }

    public static function urlFor(int $activityId): ?string
    {
        $dir = __DIR__ . '/../assets/uploads/track-previews';
        foreach (['webp', 'png'] as $ext) {
            if (is_file($dir . '/' . $activityId . '.' . $ext)) {
                return url('assets/uploads/track-previews/' . $activityId . '.' . $ext);
            }
        }
        return null;
    }

    // ============================================================
    // ВНУТРЕННИЕ
    // ============================================================

    private static function extractCoords(string $trackJson): array
    {
        $points = json_decode($trackJson, true);
        if (!is_array($points)) return [];

        $coords = [];
        foreach ($points as $p) {
            if (!isset($p['lat'], $p['lng'])) continue;
            $lat = (float)$p['lat'];
            $lng = (float)$p['lng'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            $coords[] = [$lat, $lng];
        }
        return $coords;
    }

    /**
     * Прореживает массив координат до $max точек (сохраняя первую и последнюю).
     */
    private static function simplify(array $coords, int $max): array
    {
        $n = count($coords);
        if ($n <= $max) return $coords;

        $step = (int)ceil($n / $max);
        $out = [];
        for ($i = 0; $i < $n; $i += $step) {
            $out[] = $coords[$i];
        }
        if (end($out) !== end($coords)) $out[] = end($coords);
        return $out;
    }

    /**
     * Скачивает карту с MapBox Static Images и возвращает GD-ресурс.
     */
    private static function fetchMapBox(array $coords, string $colorHex): ?\GdImage
    {
        $token = (string)config('mapbox.token');
        $style = (string)(config('mapbox.style') ?: 'outdoors-v12');

        if ($token === '') {
            log_to_file('track-preview.log', 'MapBox: пустой токен');
            return null;
        }

        // Прореживаем до лимита URL
        $pathCoords = self::simplify($coords, self::MAX_PATH_POINTS);

        // Формируем path: path-{width}+{color}-{opacity}(lng,lat,lng,lat,...)
        // Цвет без "#"
        $hex = ltrim($colorHex, '#');
        $pathParts = [];
        foreach ($pathCoords as $c) {
            $pathParts[] = sprintf('%.6f,%.6f', $c[1], $c[0]); // lng,lat
        }
        $pathStr = 'path-5+' . $hex . '-0.85(' . implode(',', $pathParts) . ')';

        // URL Static Images
        $url = sprintf(
            'https://api.mapbox.com/styles/v1/mapbox/%s/static/%s/auto/%dx%d@%dx?access_token=%s&logo=false&attribution=false',
            $style,
            $pathStr,
            self::WIDTH,
            self::HEIGHT,
            self::SCALE,
            urlencode($token)
        );

        // Если URL слишком длинный — уменьшаем количество точек
        if (strlen($url) > 8000) {
            $pathCoords = self::simplify($coords, 50);
            $pathParts = [];
            foreach ($pathCoords as $c) {
                $pathParts[] = sprintf('%.6f,%.6f', $c[1], $c[0]);
            }
            $pathStr = 'path-5+' . $hex . '-0.85(' . implode(',', $pathParts) . ')';
            $url = sprintf(
                'https://api.mapbox.com/styles/v1/mapbox/%s/static/%s/auto/%dx%d@%dx?access_token=%s&logo=false&attribution=false',
                $style,
                $pathStr,
                self::WIDTH,
                self::HEIGHT,
                self::SCALE,
                urlencode($token)
            );
        }

        $data = self::httpGet($url, self::HTTP_TIMEOUT);
        if ($data === null || strlen($data) < 100) {
            log_to_file('track-preview.log', 'MapBox fail: ' . $url);
            return null;
        }

        $img = @imagecreatefromstring($data);
        if (!$img) {
            log_to_file('track-preview.log', 'MapBox не картинка, size=' . strlen($data));
            return null;
        }

        // Проверяем размер — MapBox возвращает ровно то, что просили (×2)
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 100 || $h < 50) {
            imagedestroy($img);
            log_to_file('track-preview.log', "MapBox вернул {$w}x{$h}");
            return null;
        }

        return $img;
    }

    /**
     * HTTP GET через cURL (с fallback на file_get_contents).
     */
    private static function httpGet(string $url, int $timeout = 10): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT      => 'Pulse/1.0 (+https://roadrunnersteam.ru/pulse)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => ['Accept: image/png,image/*'],
            ]);
            $data = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($data !== false && $code >= 200 && $code < 300) {
                return $data;
            }

            log_to_file('track-preview.log', sprintf(
                'cURL fail: code=%d err=%s url=%s',
                $code, $err, $url
            ));
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'user_agent' => 'Pulse/1.0 (+https://roadrunnersteam.ru/pulse)',
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        return $data === false ? null : $data;
    }

    /**
     * Fallback: рисует трек на стилизованной сетке.
     * Используется, если MapBox недоступен.
     */
    private static function drawGridFallback(array $coords, string $colorHex): \GdImage
    {
        $w = self::WIDTH * self::SCALE;
        $h = self::HEIGHT * self::SCALE;
        $img = imagecreatetruecolor($w, $h);

        // Фон
        $bg = imagecolorallocate($img, 240, 243, 247);
        imagefill($img, 0, 0, $bg);

        // Сетка
        $grid = imagecolorallocate($img, 225, 230, 238);
        $step = 40 * self::SCALE;
        for ($x = 0; $x < $w; $x += $step) imageline($img, $x, 0, $x, $h, $grid);
        for ($y = 0; $y < $h; $y += $step) imageline($img, 0, $y, $w, $y, $grid);

        // Трек
        $latArr = array_column($coords, 0);
        $lngArr = array_column($coords, 1);
        $latMin = min($latArr); $latMax = max($latArr);
        $lngMin = min($lngArr); $lngMax = max($lngArr);

        $latSpan = max($latMax - $latMin, 1e-6);
        $lngSpan = max($lngMax - $lngMin, 1e-6);

        $pad = 20 * self::SCALE;
        $drawW = $w - 2 * $pad;
        $drawH = $h - 2 * $pad;

        $scale = min($drawW / $lngSpan, $drawH / $latSpan);

        $xOffset = $pad + ($drawW - $lngSpan * $scale) / 2;
        $yOffset = $pad + ($drawH - $latSpan * $scale) / 2;

        [$r, $g, $b] = self::hexToRgb($colorHex);
        $lineColor = imagecolorallocate($img, $r, $g, $b);
        imagesetthickness($img, 3 * self::SCALE);
        imageantialias($img, true);

        $prev = null;
        foreach ($coords as $c) {
            $x = (int)round(($c[1] - $lngMin) * $scale + $xOffset);
            $y = (int)round(($latMax - $c[0]) * $scale + $yOffset);

            if ($prev !== null) {
                imageline($img, $prev[0], $prev[1], $x, $y, $lineColor);
            }
            $prev = [$x, $y];
        }

        // Старт/финиш
        if (count($coords) >= 2) {
            $startX  = (int)round(($coords[0][1] - $lngMin) * $scale + $xOffset);
            $startY  = (int)round(($latMax - $coords[0][0]) * $scale + $yOffset);
            $finishX = (int)round(($coords[count($coords) - 1][1] - $lngMin) * $scale + $xOffset);
            $finishY = (int)round(($latMax - $coords[count($coords) - 1][0]) * $scale + $yOffset);

            $startColor  = imagecolorallocate($img, 10, 122, 58);
            $finishColor = imagecolorallocate($img, 179, 38, 30);
            $white       = imagecolorallocate($img, 255, 255, 255);
            $rad = 5 * self::SCALE;

            imagefilledellipse($img, $startX,  $startY,  $rad * 2 + 4, $rad * 2 + 4, $white);
            imagefilledellipse($img, $finishX, $finishY, $rad * 2 + 4, $rad * 2 + 4, $white);
            imagefilledellipse($img, $startX,  $startY,  $rad * 2, $rad * 2, $startColor);
            imagefilledellipse($img, $finishX, $finishY, $rad * 2, $rad * 2, $finishColor);
        }

        return $img;
    }

    private static function saveImage(\GdImage $img, int $activityId): ?string
{
    $dir = __DIR__ . '/../assets/uploads/track-previews';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // ---- Приводим изображение к truecolor ----
    // MapBox отдаёт PNG в палитровом режиме (8-bit).
    // imagewebp() работает только с truecolor, поэтому конвертируем.
    if (!imageistruecolor($img)) {
        $w = imagesx($img);
        $h = imagesy($img);
        $truecolor = imagecreatetruecolor($w, $h);

        // Сохраняем прозрачность, если она есть
        imagealphablending($truecolor, false);
        imagesavealpha($truecolor, true);
        $transparent = imagecolorallocatealpha($truecolor, 0, 0, 0, 127);
        imagefill($truecolor, 0, 0, $transparent);
        imagealphablending($truecolor, true);

        imagecopy($truecolor, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        $img = $truecolor;
    }

    $saved = false;
    $ext = 'webp';

    if (function_exists('imagewebp')) {
        $file = $dir . '/' . $activityId . '.webp';
        $saved = imagewebp($img, $file, 82);
    }

    if (!$saved) {
        $file = $dir . '/' . $activityId . '.png';
        $saved = imagepng($img, $file, 8);
        $ext = 'png';
    }

    if (!$saved) {
        log_to_file('track-preview.log', 'Не удалось сохранить превью для #' . $activityId);
        return null;
    }

    return url('assets/uploads/track-previews/' . $activityId . '.' . $ext);
}

    private static function typeColor(string $type): string
    {
        return match ($type) {
            'run'  => '#e94f2e',
            'ride' => '#2e7de9',
            'swim' => '#2ec4e9',
            'ski'  => '#5e9ee9',
            'walk' => '#68b96b',
            'hike' => '#8a6a3a',
            default => '#8a8a8f',
        };
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return [138, 138, 143];
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
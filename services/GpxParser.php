<?php
declare(strict_types=1);

class GpxParser
{
    /**
     * Парсит GPX-файл.
     * Возвращает массив с точками, дистанцией, временем, скоростями.
     */
    public static function parse(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException('GPX: файл недоступен для чтения');
        }

        // Отключаем libxml-ошибки и собираем их вручную
        $prevErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        if ($xml === false) {
            $msg = $xmlErrors
                ? trim($xmlErrors[0]->message)
                : 'не удалось распарсить XML';
            throw new RuntimeException('GPX: ' . $msg);
        }

        $points = [];

        // Основной формат: trk > trkseg > trkpt
        if (isset($xml->trk)) {
            foreach ($xml->trk as $trk) {
                foreach ($trk->trkseg as $seg) {
                    foreach ($seg->trkpt as $pt) {
                        $p = self::parsePoint($pt);
                        if ($p) $points[] = $p;
                    }
                }
            }
        }

        // Альтернатива: rte > rtept (маршрут без временных меток)
        if (!$points && isset($xml->rte)) {
            foreach ($xml->rte as $rte) {
                foreach ($rte->rtept as $pt) {
                    $p = self::parsePoint($pt);
                    if ($p) $points[] = $p;
                }
            }
        }

        // Альтернатива: wpt (путевые точки без трека)
        if (!$points && isset($xml->wpt)) {
            foreach ($xml->wpt as $pt) {
                $p = self::parsePoint($pt);
                if ($p) $points[] = $p;
            }
        }

        if (count($points) < 2) {
            throw new RuntimeException('GPX: не найдено достаточно GPS-точек (минимум 2)');
        }

        return self::summarize($points);
    }

    /**
     * Разбирает одну точку GPX.
     */
    private static function parsePoint(SimpleXMLElement $pt): ?array
    {
        if (!isset($pt['lat'], $pt['lon'])) return null;

        $lat = (float)$pt['lat'];
        $lng = (float)$pt['lon'];

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;

        $ele = null;
        if (isset($pt->ele)) {
            $e = (float)$pt->ele;
            if ($e > -500 && $e < 9000) $ele = $e;
        }

        $time = null;
        if (isset($pt->time)) {
            $t = strtotime((string)$pt->time);
            if ($t !== false && $t > 0) $time = $t;
        }

        return [
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
            'ele' => $ele,
            't'   => $time,
        ];
    }

    /**
     * Считает дистанцию, длительность, скорости, набор высоты.
     */
    public static function summarize(array $points): array
    {
        $distance  = 0.0;
        $elevGain  = 0.0;
        $maxSpeed  = 0.0;
        $prev      = null;

        foreach ($points as $p) {
            if ($prev !== null) {
                $d = self::haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
                $distance += $d;

                if ($prev['ele'] !== null && $p['ele'] !== null && $p['ele'] > $prev['ele']) {
                    $diff = $p['ele'] - $prev['ele'];
                    // Фильтруем шум: изменения менее 0.5 м не считаем
                    if ($diff >= 0.5) $elevGain += $diff;
                }

                if ($prev['t'] && $p['t'] && $p['t'] > $prev['t']) {
                    $dt = $p['t'] - $prev['t'];
                    $v  = $d / max(0.001, $dt);
                    // Игнорируем скачки > 50 м/с (реальный GPS-шум)
                    if ($v < 50 && $v > $maxSpeed) $maxSpeed = $v;
                }
            }
            $prev = $p;
        }

        $times = array_values(array_filter(array_column($points, 't')));
        sort($times);

        $duration  = (count($times) > 1) ? end($times) - $times[0] : null;
        $startedAt = $times ? date('Y-m-d H:i:s', $times[0]) : null;
        $avgSpeed  = ($duration && $distance) ? $distance / $duration : null;

        return [
            'points'           => $points,
            'distance_m'       => round($distance, 2),
            'duration_sec'     => $duration,
            'started_at'       => $startedAt,
            'elevation_gain_m' => round($elevGain, 2),
            'avg_speed_mps'    => $avgSpeed !== null ? round($avgSpeed, 3) : null,
            'max_speed_mps'    => $maxSpeed > 0 ? round($maxSpeed, 3) : null,
        ];
    }

    public static function haversinePublic(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return self::haversine($lat1, $lng1, $lat2, $lng2);
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $R * asin(min(1, sqrt($a)));
    }
}
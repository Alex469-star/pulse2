<?php
declare(strict_types=1);

class GpxParser
{
    /**
     * Парсит GPX-файл.
     * Возвращает массив с точками, дистанцией, временем, скоростями и метриками датчиков.
     */
    public static function parse(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException('GPX: файл недоступен для чтения');
        }

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
     * Разбирает одну точку GPX, включая расширения датчиков.
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

        // ---- Метрики датчиков из <extensions> ----
        $hr   = null;
        $cad  = null;
        $pwr  = null;
        $temp = null;

        if (isset($pt->extensions)) {
            self::readExtensions($pt->extensions, $hr, $cad, $pwr, $temp);
        }

        return [
            'lat'  => round($lat, 6),
            'lng'  => round($lng, 6),
            'ele'  => $ele,
            't'    => $time,
            'hr'   => $hr,
            'cad'  => $cad,
            'pwr'  => $pwr,
            'temp' => $temp,
        ];
    }

    /**
     * Читает расширения Garmin/PowerTap из <extensions>.
     * Поддерживаемые namespace:
     *  - http://www.garmin.com/xmlschemas/TrackPointExtension/v1  (hr, cad, atemp, wtemp)
     *  - http://www.garmin.com/xmlschemas/TrackPointExtension/v2
     *  - http://www.garmin.com/xmlschemas/PowerExtension/v1        (Watts)
     *  - http://www.cluetrust.com/XML/GPXDATA/1/0                  (hr, cad, power, temp)
     *  - http://www.garmin.com/xmlschemas/ActivityExtension/v2    (Watts, Temp)
     */
    private static function readExtensions(
        SimpleXMLElement $extensions,
        ?int &$hr,
        ?int &$cad,
        ?int &$pwr,
        ?float &$temp
    ): void {
        $namespaces = [
            'http://www.garmin.com/xmlschemas/TrackPointExtension/v1',
            'http://www.garmin.com/xmlschemas/TrackPointExtension/v2',
        ];

        foreach ($namespaces as $ns) {
            $ext = $extensions->children($ns);
            if (!isset($ext->TrackPointExtension)) continue;

            $tpx = $ext->TrackPointExtension;

            if ($hr === null && isset($tpx->hr)) {
                $v = (int)$tpx->hr;
                if ($v > 0 && $v < 250) $hr = $v;
            }
            if ($cad === null && isset($tpx->cad)) {
                $v = (int)$tpx->cad;
                if ($v > 0 && $v < 300) $cad = $v;
            }
            if ($temp === null && isset($tpx->atemp)) {
                $v = (float)$tpx->atemp;
                if ($v > -50 && $v < 60) $temp = $v;
            }
            if ($temp === null && isset($tpx->wtemp)) {
                $v = (float)$tpx->wtemp;
                if ($v > -50 && $v < 60) $temp = $v;
            }
        }

        // Мощность — Garmin PowerExtension v1
        $pwrExt = $extensions->children('http://www.garmin.com/xmlschemas/PowerExtension/v1');
        if (isset($pwrExt->TrackPointExtension->Watts)) {
            $v = (int)$pwrExt->TrackPointExtension->Watts;
            if ($v > 0 && $v < 2500) $pwr = $v;
        }

        // Альтернативный namespace: ActivityExtension v2 (Watts, Temp)
        $actExt = $extensions->children('http://www.garmin.com/xmlschemas/ActivityExtension/v2');
        if (isset($actExt->TPX)) {
            $tpx = $actExt->TPX;
            if ($pwr === null && isset($tpx->Watts)) {
                $v = (int)$tpx->Watts;
                if ($v > 0 && $v < 2500) $pwr = $v;
            }
            if ($temp === null && isset($tpx->Temp)) {
                $v = (float)$tpx->Temp;
                if ($v > -50 && $v < 60) $temp = $v;
            }
        }

        // Cluetrust GPXDATA: hr, cad, power, temp — прямые дочерние
        $ct = $extensions->children('http://www.cluetrust.com/XML/GPXDATA/1/0');
        if (isset($ct->trackPointExtension)) {
            $tpe = $ct->trackPointExtension;
            if ($hr === null && isset($tpe->hr)) {
                $v = (int)$tpe->hr;
                if ($v > 0 && $v < 250) $hr = $v;
            }
            if ($cad === null && isset($tpe->cad)) {
                $v = (int)$tpe->cad;
                if ($v > 0 && $v < 300) $cad = $v;
            }
            if ($pwr === null && isset($tpe->power)) {
                $v = (int)$tpe->power;
                if ($v > 0 && $v < 2500) $pwr = $v;
            }
            if ($temp === null && isset($tpe->temp)) {
                $v = (float)$tpe->temp;
                if ($v > -50 && $v < 60) $temp = $v;
            }
        }
    }

    /**
     * Считает дистанцию, длительность, скорости, набор высоты и агрегаты датчиков.
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
                    if ($diff >= 0.5) $elevGain += $diff;
                }

                if ($prev['t'] && $p['t'] && $p['t'] > $prev['t']) {
                    $dt = $p['t'] - $prev['t'];
                    $v  = $d / max(0.001, $dt);
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

        // ---- Агрегаты датчиков ----
        $hrs   = self::collectInt($points, 'hr');
        $cads  = self::collectInt($points, 'cad');
        $pwrs  = self::collectInt($points, 'pwr');
        $temps = self::collectFloat($points, 'temp');

        $avgHr   = $hrs   ? (int)round(array_sum($hrs) / count($hrs))    : null;
        $maxHr   = $hrs   ? (int)max($hrs)                                : null;
        $avgCad  = $cads  ? (int)round(array_sum($cads) / count($cads))  : null;
        $maxCad  = $cads  ? (int)max($cads)                               : null;
        $avgPwr  = $pwrs  ? (int)round(array_sum($pwrs) / count($pwrs))  : null;
        $maxPwr  = $pwrs  ? (int)max($pwrs)                               : null;
        $avgTemp = $temps ? round(array_sum($temps) / count($temps), 1)  : null;

        $hasSensors = ($hrs || $cads || $pwrs || $temps) ? 1 : 0;

        return [
            'points'           => $points,
            'distance_m'       => round($distance, 2),
            'duration_sec'     => $duration,
            'started_at'       => $startedAt,
            'elevation_gain_m' => round($elevGain, 2),
            'avg_speed_mps'    => $avgSpeed !== null ? round($avgSpeed, 3) : null,
            'max_speed_mps'    => $maxSpeed > 0 ? round($maxSpeed, 3) : null,
            'avg_hr'           => $avgHr,
            'max_hr'           => $maxHr,
            'avg_cadence'      => $avgCad,
            'max_cadence'      => $maxCad,
            'avg_power_w'      => $avgPwr,
            'max_power_w'      => $maxPwr,
            'avg_temp_c'       => $avgTemp,
            'has_sensors'      => $hasSensors,
        ];
    }

    /**
     * Собирает непустые целочисленные значения из массива точек.
     */
    private static function collectInt(array $points, string $key): array
    {
        $out = [];
        foreach ($points as $p) {
            if (isset($p[$key]) && $p[$key] !== null && $p[$key] !== '') {
                $out[] = (int)$p[$key];
            }
        }
        return $out;
    }

    /**
     * Собирает непустые вещественные значения из массива точек.
     */
    private static function collectFloat(array $points, string $key): array
    {
        $out = [];
        foreach ($points as $p) {
            if (isset($p[$key]) && $p[$key] !== null && $p[$key] !== '') {
                $out[] = (float)$p[$key];
            }
        }
        return $out;
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
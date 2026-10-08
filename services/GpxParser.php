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
            $msg = $xmlErrors ? trim($xmlErrors[0]->message) : 'не удалось распарсить XML';
            throw new RuntimeException('GPX: ' . $msg);
        }

        $points = [];

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

        if (!$points && isset($xml->rte)) {
            foreach ($xml->rte as $rte) {
                foreach ($rte->rtept as $pt) {
                    $p = self::parsePoint($pt);
                    if ($p) $points[] = $p;
                }
            }
        }

        if (!$points && isset($xml->wpt)) {
            foreach ($xml->wpt as $pt) {
                $p = self::parsePoint($pt);
                if ($p) $points[] = $p;
            }
        }

        if (!$points) {
            throw new RuntimeException('GPX: в файле не найдено ни одной точки (ни GPS, ни датчиков)');
        }

        return self::summarize($points);
    }

    private static function parsePoint(SimpleXMLElement $pt): ?array
    {
        $lat = null;
        $lng = null;

        if (isset($pt['lat'], $pt['lon'])) {
            $latV = (float)$pt['lat'];
            $lngV = (float)$pt['lon'];
            if ($latV >= -90 && $latV <= 90 && $lngV >= -180 && $lngV <= 180) {
                $lat = round($latV, 6);
                $lng = round($lngV, 6);
            }
        }

        // Если нет координат — всё равно возвращаем точку, если есть
        // хотя бы время/высота/датчики. Это важно для активностей без GPS
        // (велотренажёр, беговая дорожка, тоннель).
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

        $hr   = null;
        $cad  = null;
        $pwr  = null;
        $temp = null;
        $speed    = null;
        $distance = null;

        if (isset($pt->extensions)) {
            self::readExtensions($pt->extensions, $hr, $cad, $pwr, $temp, $speed, $distance);
        }

        // Если совсем ничего нет — эта точка бесполезна
        if ($lat === null && $time === null && $ele === null
            && $hr === null && $cad === null && $pwr === null && $temp === null
            && $speed === null && $distance === null) {
            return null;
        }

        return [
            'lat'      => $lat,
            'lng'      => $lng,
            'ele'      => $ele,
            't'        => $time,
            'hr'       => $hr,
            'cad'      => $cad,
            'pwr'      => $pwr,
            'temp'     => $temp,
            'speed'    => $speed,
            'distance' => $distance,
        ];
    }

    private static function readExtensions(
        SimpleXMLElement $extensions,
        ?int &$hr,
        ?int &$cad,
        ?int &$pwr,
        ?float &$temp,
        ?float &$speed = null,
        ?float &$distance = null
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

        $pwrExt = $extensions->children('http://www.garmin.com/xmlschemas/PowerExtension/v1');
        if (isset($pwrExt->TrackPointExtension->Watts)) {
            $v = (int)$pwrExt->TrackPointExtension->Watts;
            if ($v > 0 && $v < 2500) $pwr = $v;
        }

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
            if ($speed === null && isset($tpx->Speed)) {
                $v = (float)$tpx->Speed;
                if ($v >= 0 && $v < 100) $speed = $v;
            }
        }

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
            if ($speed === null && isset($tpe->speed)) {
                $v = (float)$tpe->speed;
                if ($v >= 0 && $v < 100) $speed = $v;
            }
            if ($distance === null && isset($tpe->distance)) {
                $v = (float)$tpe->distance;
                if ($v >= 0) $distance = $v;
            }
        }
    }

    /**
     * Считает дистанцию, длительность, скорости, набор высоты и агрегаты датчиков.
     *
     * Логика:
     *  - Дистанция: сначала distance из файла, если нет — haversine по GPS.
     *  - Скорость: сначала speed из файла, если нет — haversine / dt.
     *  - GPS-скачки отсекаются порогом по ускорению и абсолютной скорости.
     *  - Если GPS нет совсем, но есть датчики — работаем по ним.
     */
    public static function summarize(array $points): array
    {
        if (count($points) < 1) {
            throw new RuntimeException('GpxParser: пустой трек');
        }

        // Проставляем distance и speed вперёд, если они приходят «пачками»
        // (типично для FIT/TCX — поле идёт раз в несколько точек).
        self::fillForward($points);

        // Отсекаем GPS-скачки в точках
        self::sanitizeTrack($points);

        // Считаем
        $distance   = 0.0;
        $elevGain   = 0.0;
        $maxSpeed   = 0.0;
        $speedSum   = 0.0;
        $speedCnt   = 0;
        $hasSpeedSource    = false;
        $hasDistanceSource = false;
        $movingTimeSec     = 0;
        $stopTimeSec       = 0;
        $pauseStart        = null;
        $prevT             = null;
        $prev              = null;
        $prevDist          = null;

        $hrs   = [];
        $cads  = [];
        $pwrs  = [];
        $temps = [];

        $STOP_SPEED_MPS = 0.28; // ~1 км/ч
        $PAUSE_MIN_SEC  = 3;

        foreach ($points as $p) {
            // --- Датчики ---
            if ($p['hr'] !== null)   $hrs[]   = (int)$p['hr'];
            if ($p['cad'] !== null)  $cads[]  = (int)$p['cad'];
            if ($p['pwr'] !== null)  $pwrs[]  = (int)$p['pwr'];
            if ($p['temp'] !== null) $temps[] = (float)$p['temp'];

            // --- Дистанция ---
            if ($p['distance'] !== null && $p['distance'] >= 0) {
                if ($prevDist === null || $p['distance'] >= $prevDist) {
                    $distance          = (float)$p['distance'];
                    $prevDist          = (float)$p['distance'];
                    $hasDistanceSource = true;
                }
            }

            // --- Скорость ---
            if ($p['speed'] !== null && $p['speed'] >= 0) {
                $v = (float)$p['speed'];
                if ($v < 50) {
                    $speedSum += $v;
                    $speedCnt++;
                    if ($v > $maxSpeed) $maxSpeed = $v;
                    $hasSpeedSource = true;
                }
            }

            // --- Fallback: haversine, если нет ни distance, ни speed ---
            if ($prev !== null) {
                // Набор высоты
                if ($prev['ele'] !== null && $p['ele'] !== null && $p['ele'] > $prev['ele']) {
                    $diff = $p['ele'] - $prev['ele'];
                    if ($diff >= 0.5) $elevGain += $diff;
                }

                // Если distance нет в файле — считаем по GPS
                if (!$hasDistanceSource
                    && $p['lat'] !== null && $prev['lat'] !== null) {
                    $distance += self::haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
                }

                // Если speed нет — считаем по GPS
                if (!$hasSpeedSource
                    && $p['t'] !== null && $prev['t'] !== null && $p['t'] > $prev['t']
                    && $p['lat'] !== null && $prev['lat'] !== null) {
                    $dt  = $p['t'] - $prev['t'];
                    $seg = self::haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
                    $v   = $seg / max(0.001, $dt);
                    if ($v < 50 && $v > $maxSpeed) $maxSpeed = $v;
                }
            }

            // --- Время движения / остановок ---
            if ($prevT !== null && $p['t'] !== null && $p['t'] > $prevT) {
                $dt = $p['t'] - $prevT;

                // Разрыв > 5 минут — считаем, что трек прервался, не остановка
                if ($dt <= 300) {
                    // Мгновенная скорость для классификации
                    $v = null;
                    if ($hasSpeedSource && $p['speed'] !== null) {
                        $v = (float)$p['speed'];
                    } elseif ($prev !== null && $p['lat'] !== null && $prev['lat'] !== null) {
                        $seg = self::haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
                        $v   = $seg / max(0.001, $dt);
                    }

                    if ($v !== null && $v < $STOP_SPEED_MPS) {
                        if ($pauseStart === null) $pauseStart = $prevT;
                        $stopTimeSec += $dt;
                    } else {
                        if ($pauseStart !== null) {
                            $pauseLen = $prevT - $pauseStart;
                            if ($pauseLen < $PAUSE_MIN_SEC) {
                                $stopTimeSec -= $pauseLen;
                                $movingTimeSec += $pauseLen;
                            }
                            $pauseStart = null;
                        }
                        $movingTimeSec += $dt;
                    }
                }
            }

            if ($p['t'] !== null) $prevT = $p['t'];
            $prev = $p;
        }

        // --- Итоговое время ---
        $duration = $movingTimeSec + $stopTimeSec;

        // --- Средняя скорость ---
        $avgSpeed = null;
        if ($movingTimeSec > 0 && $distance > 0) {
            $avgSpeed = $distance / $movingTimeSec;
        } elseif ($duration > 0 && $distance > 0) {
            $avgSpeed = $distance / $duration;
        } elseif ($hasSpeedSource && $speedCnt > 0) {
            $avgSpeed = $speedSum / $speedCnt;
        }

        $avgWithStops = null;
        if ($duration > 0 && $distance > 0) {
            $avgWithStops = $distance / $duration;
        }

        // --- Агрегаты датчиков ---
        $avgHr   = $hrs   ? (int)round(array_sum($hrs) / count($hrs))    : null;
        $maxHr   = $hrs   ? (int)max($hrs)                                : null;
        $avgCad  = $cads  ? (int)round(array_sum($cads) / count($cads))  : null;
        $maxCad  = $cads  ? (int)max($cads)                               : null;
        $avgPwr  = $pwrs  ? (int)round(array_sum($pwrs) / count($pwrs))  : null;
        $maxPwr  = $pwrs  ? (int)max($pwrs)                               : null;
        $avgTemp = $temps ? round(array_sum($temps) / count($temps), 1)  : null;

        $hasSensors = ($hrs || $cads || $pwrs || $temps) ? 1 : 0;

        return [
            'points'              => $points,
            'distance_m'          => round($distance, 2),
            'duration_sec'        => $duration > 0 ? $duration : null,
            'moving_time_sec'     => $movingTimeSec > 0 ? $movingTimeSec : null,
            'stop_time_sec'       => $stopTimeSec > 0 ? (int)round($stopTimeSec) : null,
            'started_at'          => self::firstTime($points),
            'elevation_gain_m'    => round($elevGain, 2),
            'avg_speed_mps'       => $avgSpeed !== null ? round($avgSpeed, 3) : null,
            'avg_speed_stops_mps' => $avgWithStops !== null ? round($avgWithStops, 3) : null,
            'max_speed_mps'       => $maxSpeed > 0 ? round($maxSpeed, 3) : null,
            'avg_hr'              => $avgHr,
            'max_hr'              => $maxHr,
            'avg_cadence'         => $avgCad,
            'max_cadence'         => $maxCad,
            'avg_power_w'         => $avgPwr,
            'max_power_w'         => $maxPwr,
            'avg_temp_c'          => $avgTemp,
            'has_sensors'         => $hasSensors,
            'has_speed_source'    => $hasSpeedSource ? 1 : 0,
            'has_distance_source' => $hasDistanceSource ? 1 : 0,
            'gps_points_count'    => self::countGps($points),
            'total_points_count'  => count($points),
        ];
    }

    /**
     * Проставляет distance/speed вперёд, если они приходят «пачками».
     * Нужно для FIT и некоторых TCX/GPX.
     */
    private static function fillForward(array &$points): void
    {
        $lastDist  = null;
        $lastSpeed = null;

        foreach ($points as &$p) {
            if ($p['distance'] !== null) {
                $lastDist = $p['distance'];
            } else {
                $p['distance'] = $lastDist;
            }

            if ($p['speed'] !== null) {
                $lastSpeed = $p['speed'];
            } else {
                $p['speed'] = $lastSpeed;
            }
        }
        unset($p);

        // Если в последней точке ничего не было — проставим финальные значения
        if ($points && $lastDist !== null) {
            $last = count($points) - 1;
            if ($points[$last]['distance'] === null) $points[$last]['distance'] = $lastDist;
        }
        if ($points && $lastSpeed !== null) {
            $last = count($points) - 1;
            if ($points[$last]['speed'] === null) $points[$last]['speed'] = $lastSpeed;
        }
    }

    /**
     * Отсекает GPS-скачки: если расстояние между двумя точками слишком большое
     * для указанного времени — считаем, что это ошибка GPS.
     */
    private static function sanitizeTrack(array &$points): void
    {
        $n = count($points);
        if ($n < 3) return;

        // Порог скорости, выше которого считаем точку «битой»
        $maxReasonableMps = 50; // 180 км/ч — отсечка даже для машин

        for ($i = 1; $i < $n; $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];

            if ($a['lat'] === null || $b['lat'] === null) continue;
            if ($a['t'] === null || $b['t'] === null) continue;

            $dt = $b['t'] - $a['t'];
            if ($dt <= 0) continue;

            $d = self::haversine($a['lat'], $a['lng'], $b['lat'], $b['lng']);
            $v = $d / $dt;

            if ($v > $maxReasonableMps) {
                // Это GPS-скачок. Убираем координаты в b, но оставляем
                // время и датчики. Так трек не будет рисовать «пилу»,
                // но данные датчиков сохранятся.
                $points[$i]['lat'] = null;
                $points[$i]['lng'] = null;
            }
        }
    }

    private static function firstTime(array $points): ?string
    {
        foreach ($points as $p) {
            if ($p['t'] !== null) return date('Y-m-d H:i:s', $p['t']);
        }
        return null;
    }

    private static function countGps(array $points): int
    {
        $c = 0;
        foreach ($points as $p) {
            if ($p['lat'] !== null && $p['lng'] !== null) $c++;
        }
        return $c;
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
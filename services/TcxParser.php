<?php
declare(strict_types=1);

require_once __DIR__ . '/GpxParser.php';

class TcxParser
{
    /**
     * Парсит TCX-файл.
     */
    public static function parse(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException('TCX: файл недоступен для чтения');
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
            throw new RuntimeException('TCX: ' . $msg);
        }

        $points = [];

        // Основной формат: Activities > Activity > Lap > Track > Trackpoint
        if (isset($xml->Activities)) {
            foreach ($xml->Activities->Activity as $activity) {
                foreach ($activity->Lap as $lap) {
                    foreach ($lap->Track as $track) {
                        foreach ($track->Trackpoint as $tp) {
                            $p = self::parseTrackpoint($tp);
                            if ($p) $points[] = $p;
                        }
                    }
                }
            }
        }

        // Альтернатива: Courses > Course > Track > Trackpoint
        if (!$points && isset($xml->Courses)) {
            foreach ($xml->Courses->Course as $course) {
                foreach ($course->Track as $track) {
                    foreach ($track->Trackpoint as $tp) {
                        $p = self::parseTrackpoint($tp);
                        if ($p) $points[] = $p;
                    }
                }
            }
        }

        if (count($points) < 2) {
            throw new RuntimeException('TCX: не найдено достаточно GPS-точек (минимум 2)');
        }

        return GpxParser::summarize($points);
    }

    /**
     * Разбирает одну точку TCX.
     */
    private static function parseTrackpoint(SimpleXMLElement $tp): ?array
    {
        if (!isset($tp->Position)) return null;

        $lat = isset($tp->Position->LatitudeDegrees)
            ? (float)$tp->Position->LatitudeDegrees
            : null;
        $lng = isset($tp->Position->LongitudeDegrees)
            ? (float)$tp->Position->LongitudeDegrees
            : null;

        if ($lat === null || $lng === null) return null;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;

        $ele = null;
        if (isset($tp->AltitudeMeters)) {
            $e = (float)$tp->AltitudeMeters;
            if ($e > -500 && $e < 9000) $ele = $e;
        }

        $time = null;
        if (isset($tp->Time)) {
            $t = strtotime((string)$tp->Time);
            if ($t !== false && $t > 0) $time = $t;
        }

        return [
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
            'ele' => $ele,
            't'   => $time,
        ];
    }
}
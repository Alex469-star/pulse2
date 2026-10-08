<?php
declare(strict_types=1);

require_once __DIR__ . '/GpxParser.php';

class TcxParser
{
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
            $msg = $xmlErrors ? trim($xmlErrors[0]->message) : 'не удалось распарсить XML';
            throw new RuntimeException('TCX: ' . $msg);
        }

        $points = [];

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

        if (!$points) {
            throw new RuntimeException('TCX: не найдено ни одной точки (ни GPS, ни датчиков)');
        }

        return GpxParser::summarize($points);
    }

    private static function parseTrackpoint(SimpleXMLElement $tp): ?array
    {
        $lat = null;
        $lng = null;

        if (isset($tp->Position)) {
            $latV = isset($tp->Position->LatitudeDegrees) ? (float)$tp->Position->LatitudeDegrees : null;
            $lngV = isset($tp->Position->LongitudeDegrees) ? (float)$tp->Position->LongitudeDegrees : null;
            if ($latV !== null && $lngV !== null
                && $latV >= -90 && $latV <= 90
                && $lngV >= -180 && $lngV <= 180) {
                $lat = round($latV, 6);
                $lng = round($lngV, 6);
            }
        }

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

        $hr = null;
        if (isset($tp->HeartRateBpm->Value)) {
            $v = (int)$tp->HeartRateBpm->Value;
            if ($v > 0 && $v < 250) $hr = $v;
        }

        $cad = null;
        if (isset($tp->Cadence)) {
            $v = (int)$tp->Cadence;
            if ($v > 0 && $v < 300) $cad = $v;
        }

        $distance = null;
        if (isset($tp->DistanceMeters)) {
            $v = (float)$tp->DistanceMeters;
            if ($v >= 0) $distance = $v;
        }

        $pwr   = null;
        $temp  = null;
        $speed = null;

        if (isset($tp->Extensions)) {
            self::readExtensions($tp->Extensions, $pwr, $temp, $speed);
        }

        // Если вообще ничего — вернём null
        if ($lat === null && $time === null && $ele === null
            && $hr === null && $cad === null && $distance === null
            && $pwr === null && $temp === null && $speed === null) {
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
        ?int &$pwr,
        ?float &$temp,
        ?float &$speed
    ): void {
        $namespaces = [
            'http://www.garmin.com/xmlschemas/ActivityExtension/v2',
            'http://www.garmin.com/xmlschemas/ActivityExtension/v1',
        ];

        foreach ($namespaces as $ns) {
            $ext = $extensions->children($ns);

            if ($pwr === null && isset($ext->TPX->Watts)) {
                $v = (int)$ext->TPX->Watts;
                if ($v > 0 && $v < 2500) $pwr = $v;
            }
            if ($temp === null && isset($ext->TPX->Temp)) {
                $v = (float)$ext->TPX->Temp;
                if ($v > -50 && $v < 60) $temp = $v;
            }
            if ($speed === null && isset($ext->TPX->Speed)) {
                $v = (float)$ext->TPX->Speed;
                if ($v >= 0 && $v < 100) $speed = $v;
            }

            if ($pwr !== null && $temp !== null && $speed !== null) return;
        }

        if ($pwr === null && isset($extensions->TPX->Watts)) {
            $v = (int)$extensions->TPX->Watts;
            if ($v > 0 && $v < 2500) $pwr = $v;
        }
        if ($temp === null && isset($extensions->TPX->Temp)) {
            $v = (float)$extensions->TPX->Temp;
            if ($v > -50 && $v < 60) $temp = $v;
        }
        if ($speed === null && isset($extensions->TPX->Speed)) {
            $v = (float)$extensions->TPX->Speed;
            if ($v >= 0 && $v < 100) $speed = $v;
        }
    }
}
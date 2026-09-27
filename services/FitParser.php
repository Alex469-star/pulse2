<?php
declare(strict_types=1);

require_once __DIR__ . '/GpxParser.php';

/**
 * Парсер FIT-файлов (Garmin).
 *
 * Реализован «чистый» разбор бинарного формата FIT без внешних библиотек.
 * Поддерживает запись типа "record" (позиция, высота, время, пульс, каденс,
 * мощность, температура).
 * Не поддерживает: developer fields, compressed timestamp headers,
 * события, круги — этого достаточно для трека.
 */
class FitParser
{
    private const FIT_EPOCH = 631065600; // 1989-12-31 00:00:00 UTC в Unix-времени
    private const RECORD_GLOBAL_MSG_NUM = 20; // global message number для record

    /**
     * Парсит FIT-файл.
     */
    public static function parse(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException('FIT: файл недоступен для чтения');
        }

        $data = file_get_contents($filePath);
        if ($data === false || strlen($data) < 14) {
            throw new RuntimeException('FIT: файл пуст или повреждён');
        }

        // ---- 12-байтный header ----
        $headerSize = ord($data[0]);
        if ($headerSize < 12 || $headerSize > 14) {
            throw new RuntimeException('FIT: некорректный header');
        }

        $dataSize   = self::u32($data, 4);
        $dataStart  = $headerSize;
        $dataEnd    = $dataStart + $dataSize;

        if ($dataEnd > strlen($data)) {
            // Некоторые файлы не имеют полного trailer — обрежем
            $dataEnd = strlen($data);
        }

        // ---- Определения полей (local message type -> field defs) ----
        $definitions = [];
        $points      = [];

        $pos = $dataStart;

        while ($pos < $dataEnd) {
            $recordHeader = ord($data[$pos]);
            $pos++;

            $isDefinition = ($recordHeader & 0x40) !== 0;
            $hasDevData   = ($recordHeader & 0x20) !== 0;
            $localNum     = $recordHeader & 0x0F;

            if ($isDefinition) {
                if ($pos + 5 > $dataEnd) break;

                $pos++; // reserved
                $arch     = ord($data[$pos]); $pos++;
                $globalNum = self::u16($data, $pos, $arch === 1); $pos += 2;
                $fieldCount = ord($data[$pos]); $pos++;

                $fields = [];
                for ($i = 0; $i < $fieldCount; $i++) {
                    if ($pos + 3 > $dataEnd) break;
                    $fieldDefNum = ord($data[$pos]);
                    $size        = ord($data[$pos + 1]);
                    $baseType    = ord($data[$pos + 2]);
                    $pos += 3;
                    $fields[] = [
                        'num'      => $fieldDefNum,
                        'size'     => $size,
                        'baseType' => $baseType,
                    ];
                }

                if ($hasDevData) {
                    if ($pos >= $dataEnd) break;
                    $devCount = ord($data[$pos]); $pos++;
                    for ($i = 0; $i < $devCount; $i++) {
                        if ($pos + 3 > $dataEnd) break;
                        $pos += 3;
                    }
                }

                $definitions[$localNum] = [
                    'globalNum' => $globalNum,
                    'fields'    => $fields,
                ];
                continue;
            }

            // ---- Data record ----
            if (!isset($definitions[$localNum])) {
                break;
            }

            $def = $definitions[$localNum];

            // Считаем длину data record
            $recordLen = 0;
            foreach ($def['fields'] as $f) {
                $recordLen += $f['size'];
            }

            if ($pos + $recordLen > $dataEnd) break;

            // Извлекаем значения, только если это record message
            if ($def['globalNum'] === self::RECORD_GLOBAL_MSG_NUM) {
                $point = self::readRecord($data, $pos, $def['fields']);
                if ($point !== null) {
                    $points[] = $point;
                }
            }

            $pos += $recordLen;
        }

        if (count($points) < 2) {
            throw new RuntimeException(
                'FIT: не найдено достаточно GPS-точек. '
                . 'Возможно, активность записана без GPS (например, зал или велотренажёр).'
            );
        }

        // Убираем точки без координат
        $points = array_values(array_filter($points, function ($p) {
            return isset($p['lat'], $p['lng']);
        }));

        if (count($points) < 2) {
            throw new RuntimeException('FIT: недостаточно точек с координатами');
        }

        return GpxParser::summarize($points);
    }

    /**
     * Извлекает значения полей record-сообщения.
     * Поля record (по стандарту FIT):
     *   253 — timestamp
     *   0   — position_lat (semicircles, sint32)
     *   1   — position_long (semicircles, sint32)
     *   2   — altitude (uint16, scale 5, offset 500)
     *   3   — heart_rate (uint8, bpm)
     *   4   — cadence (uint8, rpm)
     *   5   — distance (uint32, scale 100)
     *   6   — speed (uint16, scale 1000, м/с)
     *   7   — power (uint16, W)
     *   13  — temperature (sint8, °C)
     */
    private static function readRecord(string $data, int $offset, array $fields): ?array
    {
        $lat  = null;
        $lng  = null;
        $ele  = null;
        $time = null;
        $hr   = null;
        $cad  = null;
        $pwr  = null;
        $temp = null;

        foreach ($fields as $f) {
            $raw = substr($data, $offset, $f['size']);
            $offset += $f['size'];

            if (strlen($raw) !== $f['size']) return null;

            switch ($f['num']) {
                case 253: // timestamp
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFFFFFFFF) {
                        $time = self::FIT_EPOCH + $v;
                    }
                    break;

                case 0: // position_lat (sint32 semicircles)
                    $v = self::sintFromBytes($raw);
                    if ($v !== null && $v !== 0x7FFFFFFF) {
                        $lat = $v * (180.0 / 2147483648.0);
                    }
                    break;

                case 1: // position_long (sint32 semicircles)
                    $v = self::sintFromBytes($raw);
                    if ($v !== null && $v !== 0x7FFFFFFF) {
                        $lng = $v * (180.0 / 2147483648.0);
                    }
                    break;

                case 2: // altitude (uint16, scale 5, offset 500)
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFFFF) {
                        $ele = ($v / 5.0) - 500;
                    }
                    break;

                case 3: // heart_rate (uint8)
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFF && $v > 0 && $v < 250) {
                        $hr = $v;
                    }
                    break;

                case 4: // cadence (uint8)
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFF && $v > 0 && $v < 300) {
                        $cad = $v;
                    }
                    break;

                case 7: // power (uint16, W)
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFFFF && $v > 0 && $v < 2500) {
                        $pwr = $v;
                    }
                    break;

                case 13: // temperature (sint8, °C)
                    $v = self::sintFromBytes($raw);
                    if ($v !== null && $v !== 0x7F && $v > -50 && $v < 60) {
                        $temp = (float)$v;
                    }
                    break;
            }
        }

        if ($lat === null || $lng === null) return null;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;

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
     * Читает беззнаковое целое из массива байт (little-endian).
     */
    private static function uintFromBytes(string $bytes): ?int
    {
        $len = strlen($bytes);
        if ($len < 1 || $len > 8) return null;

        $v = 0;
        for ($i = 0; $i < $len; $i++) {
            $v |= (ord($bytes[$i]) << (8 * $i));
        }
        return $v;
    }

    /**
     * Читает знаковое целое (sint32/sint16/sint8) из массива байт.
     */
    private static function sintFromBytes(string $bytes): ?int
    {
        $len = strlen($bytes);
        if ($len !== 4 && $len !== 2 && $len !== 1) return null;

        $v = self::uintFromBytes($bytes);
        if ($v === null) return null;

        $bits = $len * 8;
        $signBit = 1 << ($bits - 1);

        if ($v & $signBit) {
            $v -= (1 << $bits);
        }
        return $v;
    }

    /**
     * uint16 из потока с учётом архитектуры (big-endian).
     */
    private static function u16(string $data, int $offset, bool $bigEndian = false): int
    {
        if ($bigEndian) {
            return (ord($data[$offset]) << 8) | ord($data[$offset + 1]);
        }
        return (ord($data[$offset + 1]) << 8) | ord($data[$offset]);
    }

    private static function u32(string $data, int $offset): int
    {
        return ord($data[$offset])
             | (ord($data[$offset + 1]) << 8)
             | (ord($data[$offset + 2]) << 16)
             | (ord($data[$offset + 3]) << 24);
    }
}
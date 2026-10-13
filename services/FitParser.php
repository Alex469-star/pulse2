<?php
declare(strict_types=1);

require_once __DIR__ . '/GpxParser.php';

class FitParser
{
    private const FIT_EPOCH = 631065600;
    private const RECORD_GLOBAL_MSG_NUM  = 20;
    private const SESSION_GLOBAL_MSG_NUM = 18; // NEW

    /**
     * // NEW: добавлено поле source_type — тип из session-месседжа FIT.
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

        $headerSize = ord($data[0]);
        if ($headerSize < 12 || $headerSize > 14) {
            throw new RuntimeException('FIT: некорректный header');
        }

        $dataSize  = self::u32($data, 4);
        $dataStart = $headerSize;
        $dataEnd   = $dataStart + $dataSize;
        if ($dataEnd > strlen($data)) $dataEnd = strlen($data);

        $definitions = [];
        $points      = [];
        $sourceType  = null; // NEW

        $pos = $dataStart;

        while ($pos < $dataEnd) {
            $recordHeader = ord($data[$pos]);
            $pos++;

            $isDefinition = ($recordHeader & 0x40) !== 0;
            $hasDevData   = ($recordHeader & 0x20) !== 0;
            $localNum     = $recordHeader & 0x0F;

            if ($isDefinition) {
                if ($pos + 5 > $dataEnd) break;
                $pos++;
                $arch = ord($data[$pos]); $pos++;
                $globalNum = self::u16($data, $pos, $arch === 1); $pos += 2;
                $fieldCount = ord($data[$pos]); $pos++;

                $fields = [];
                for ($i = 0; $i < $fieldCount; $i++) {
                    if ($pos + 3 > $dataEnd) break;
                    $fieldDefNum = ord($data[$pos]);
                    $size        = ord($data[$pos + 1]);
                    $baseType    = ord($data[$pos + 2]);
                    $pos += 3;
                    $fields[] = ['num' => $fieldDefNum, 'size' => $size, 'baseType' => $baseType];
                }

                if ($hasDevData) {
                    if ($pos >= $dataEnd) break;
                    $devCount = ord($data[$pos]); $pos++;
                    for ($i = 0; $i < $devCount; $i++) {
                        if ($pos + 3 > $dataEnd) break;
                        $pos += 3;
                    }
                }

                $definitions[$localNum] = ['globalNum' => $globalNum, 'fields' => $fields];
                continue;
            }

            if (!isset($definitions[$localNum])) break;

            $def = $definitions[$localNum];
            $recordLen = 0;
            foreach ($def['fields'] as $f) $recordLen += $f['size'];
            if ($pos + $recordLen > $dataEnd) break;

            // NEW: читаем session-месседж для определения типа
            if ($def['globalNum'] === self::SESSION_GLOBAL_MSG_NUM) {
                if ($sourceType === null) {
                    $sessionType = self::readSessionSport($data, $pos, $def['fields']);
                    if ($sessionType !== null) {
                        $sourceType = $sessionType;
                    }
                }
            }

            if ($def['globalNum'] === self::RECORD_GLOBAL_MSG_NUM) {
                $point = self::readRecord($data, $pos, $def['fields']);
                if ($point !== null) $points[] = $point;
            }

            $pos += $recordLen;
        }

        if (!$points) {
            throw new RuntimeException(
                'FIT: не найдено ни одной записи. '
                . 'Возможно, файл повреждён или не содержит данных о тренировке.'
            );
        }

        $summary = GpxParser::summarize($points);
        $summary['source_type'] = $sourceType; // NEW
        return $summary;
    }

    /**
     * NEW: читает sport (field 5) и sub_sport (field 6) из session-месседжа.
     * Возвращает строковое имя типа или null.
     */
    private static function readSessionSport(string $data, int $offset, array $fields): ?string
    {
        $sport    = null;
        $subSport = null;

        foreach ($fields as $f) {
            $raw = substr($data, $offset, $f['size']);
            $offset += $f['size'];
            if (strlen($raw) !== $f['size']) return null;

            // sport и sub_sport — enum, читаются как uint
            if ($f['num'] === 5) {
                $v = self::uintFromBytes($raw);
                if ($v !== null && $v !== 0xFF) $sport = $v;
            } elseif ($f['num'] === 6) {
                $v = self::uintFromBytes($raw);
                if ($v !== null && $v !== 0xFF) $subSport = $v;
            }
        }

        if ($sport === null) return null;

        return self::fitSportToString($sport, $subSport);
    }

    /**
     * NEW: маппинг FIT sport/sub_sport enum → строковое имя.
     *
     * ВАЖНО: номера sub_sport сверяйте с FIT SDK:
     * https://developer.garmin.com/fit/protocol/
     * Маппинг ниже покрывает основные виды спорта; при сомнениях
     * используйте только sport (без sub_sport).
     */
    private static function fitSportToString(int $sport, ?int $subSport): string
    {
        $map = [
            0  => 'generic',
            1  => 'running',
            2  => 'cycling',
            3  => 'transition',
            4  => 'fitness_equipment',
            5  => 'swimming',
            6  => 'basketball',
            7  => 'soccer',
            8  => 'tennis',
            9  => 'american_football',
            10 => 'training',
            11 => 'walking',
            12 => 'cross_country_skiing',
            13 => 'alpine_skiing',
            14 => 'snowboarding',
            15 => 'rowing',
            16 => 'mountaineering',
            17 => 'hiking',
            18 => 'multisport',
            19 => 'paddling',
            20 => 'flying',
            21 => 'e_biking',
            22 => 'motorcycling',
            23 => 'boating',
            24 => 'driving',
            25 => 'golf',
            26 => 'hang_gliding',
            27 => 'horseback_riding',
            28 => 'hunting',
            29 => 'fishing',
            30 => 'inline_skating',
            31 => 'rock_climbing',
            32 => 'sailing',
            33 => 'ice_skating',
            34 => 'sky_diving',
            35 => 'snowshoeing',
            36 => 'snowmobiling',
            37 => 'stand_up_paddleboarding',
            38 => 'surfing',
            39 => 'wakeboarding',
            40 => 'water_skiing',
            41 => 'kayaking',
            42 => 'rafting',
            43 => 'windsurfing',
            44 => 'kitesurfing',
            45 => 'tactical',
            46 => 'jumpmaster',
            47 => 'boxing',
            48 => 'floor_climbing',
            49 => 'baseball',
            53 => 'diving',
            62 => 'hiit',
            64 => 'racket',
            65 => 'wheelchair_push_walk',
            66 => 'wheelchair_push_run',
            67 => 'meditation',
            76 => 'disc_golf',
            77 => 'ultimate_disc',
            80 => 'cricket',
            81 => 'rugby',
            82 => 'hockey',
            83 => 'lacrosse',
            84 => 'volleyball',
            85 => 'water_tubing',
            86 => 'wakesurfing',
            254 => 'all',
        ];

        $name = $map[$sport] ?? 'generic';

        // Уточнение по sub_sport для основных видов.
        // Номера сверяйте с FIT SDK — они могут отличаться в разных версиях.
        if ($subSport !== null) {
            if ($sport === 1) { // running
                $subRun = [
                    0 => 'running',
                    1 => 'treadmill_running',
                    2 => 'street_running',
                    3 => 'track_running',
                    4 => 'trail_running',
                    5 => 'trail_running',
                    6 => 'trail_running',
                    8 => 'indoor_running',
                    9 => 'virtual_run',
                ];
                if (isset($subRun[$subSport])) $name = $subRun[$subSport];
            } elseif ($sport === 2) { // cycling
                $subCyc = [
                    0 => 'cycling',
                    1 => 'road_cycling',
                    2 => 'road_cycling',
                    3 => 'road_cycling',
                    4 => 'road_cycling',
                    5 => 'mountain_biking',
                    6 => 'mountain_biking',
                    7 => 'mountain_biking',
                    8 => 'downhill_biking',
                    9 => 'recumbent_biking',
                    10 => 'cyclocross',
                    11 => 'hand_cycling',
                    12 => 'track_cycling',
                    13 => 'indoor_cycling',
                    14 => 'indoor_cycling',
                    15 => 'virtual_ride',
                    16 => 'virtual_ride',
                ];
                if (isset($subCyc[$subSport])) $name = $subCyc[$subSport];
            } elseif ($sport === 5) { // swimming
                $subSwim = [
                    0 => 'swimming',
                    1 => 'open_water_swimming',
                    2 => 'open_water_swimming',
                    3 => 'lap_swimming',
                    4 => 'lap_swimming',
                    5 => 'lap_swimming',
                ];
                if (isset($subSwim[$subSport])) $name = $subSwim[$subSport];
            }
        }

        return $name;
    }


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
        $speed    = null;
        $distance = null;

        foreach ($fields as $f) {
            $raw = substr($data, $offset, $f['size']);
            $offset += $f['size'];
            if (strlen($raw) !== $f['size']) return null;

            switch ($f['num']) {
                case 253:
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFFFFFFFF) $time = self::FIT_EPOCH + $v;
                    break;
                case 0:
                    $v = self::sintFromBytes($raw);
                    if ($v !== null && $v !== 0x7FFFFFFF) $lat = $v * (180.0 / 2147483648.0);
                    break;
                case 1:
                    $v = self::sintFromBytes($raw);
                    if ($v !== null && $v !== 0x7FFFFFFF) $lng = $v * (180.0 / 2147483648.0);
                    break;
                case 2:
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFFFF) $ele = ($v / 5.0) - 500;
                    break;
                case 3:
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFF && $v > 0 && $v < 250) $hr = $v;
                    break;
                case 4:
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFF && $v > 0 && $v < 300) $cad = $v;
                    break;
                case 5:
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFFFFFFFF) $distance = $v / 100.0;
                    break;
                case 6:
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFFFF) $speed = $v / 1000.0;
                    break;
                case 7:
                    $v = self::uintFromBytes($raw);
                    if ($v !== null && $v !== 0xFFFF && $v > 0 && $v < 2500) $pwr = $v;
                    break;
                case 13:
                    $v = self::sintFromBytes($raw);
                    if ($v !== null && $v !== 0x7F && $v > -50 && $v < 60) $temp = (float)$v;
                    break;
            }
        }

        // Если нет координат — проверим, есть ли что-то ещё полезное
        $hasData = $lat !== null || $time !== null || $ele !== null
                || $hr !== null || $cad !== null || $pwr !== null
                || $temp !== null || $speed !== null || $distance !== null;

        if (!$hasData) return null;

        // Валидируем координаты, если они есть
        if ($lat !== null && ($lat < -90 || $lat > 90)) { $lat = null; $lng = null; }
        if ($lng !== null && ($lng < -180 || $lng > 180)) { $lat = null; $lng = null; }

        return [
            'lat'      => $lat !== null ? round($lat, 6) : null,
            'lng'      => $lng !== null ? round($lng, 6) : null,
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

    private static function uintFromBytes(string $bytes): ?int
    {
        $len = strlen($bytes);
        if ($len < 1 || $len > 8) return null;
        $v = 0;
        for ($i = 0; $i < $len; $i++) $v |= (ord($bytes[$i]) << (8 * $i));
        return $v;
    }

    private static function sintFromBytes(string $bytes): ?int
    {
        $len = strlen($bytes);
        if ($len !== 4 && $len !== 2 && $len !== 1) return null;
        $v = self::uintFromBytes($bytes);
        if ($v === null) return null;
        $bits = $len * 8;
        $signBit = 1 << ($bits - 1);
        if ($v & $signBit) $v -= (1 << $bits);
        return $v;
    }

    private static function u16(string $data, int $offset, bool $bigEndian = false): int
    {
        if ($bigEndian) return (ord($data[$offset]) << 8) | ord($data[$offset + 1]);
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
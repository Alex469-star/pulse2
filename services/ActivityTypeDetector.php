<?php
declare(strict_types=1);

/**
 * ActivityTypeDetector
 *
 * Определяет тип активности двумя способами:
 *   1. По «сырому» типу из файла (source_type), который извлекают парсеры
 *      GPX/TCX/FIT. Это самый надёжный источник.
 *   2. Эвристически — по метрикам трека (скорость, каденс, мощность, HR,
 *      температура, набор высоты, наличие GPS). Используется, когда в файле
 *      типа нет или он неизвестен.
 *
 * Возвращает одно из значений enum activities.type:
 *   run | ride | swim | ski | walk | hike | other
 */
class ActivityTypeDetector
{
    /**
     * Маппинг «сырых» типов от провайдеров (Garmin, Strava, Wahoo, Polar,
     * Suunto, FIT SDK) в наш enum.
     *
     * Ключи — в нижнем регистре. Значения — наши типы.
     */
    private const MAP = [
        // ---------- run ----------
        'run'                    => 'run',
        'running'                => 'run',
        'jogging'                => 'run',
        'trail_running'          => 'run',
        'trailrun'               => 'run',
        'treadmill_running'      => 'run',
        'treadmill'              => 'run',
        'indoor_running'         => 'run',
        'indoorrun'              => 'run',
        'track_running'          => 'run',
        'street_running'         => 'run',
        'virtual_run'            => 'run',
        'virtualrun'             => 'run',

        // ---------- ride ----------
        'ride'                   => 'ride',
        'bike'                   => 'ride',
        'biking'                 => 'ride',
        'cycling'                => 'ride',
        'cycle'                  => 'ride',
        'road_cycling'           => 'ride',
        'roadcycling'            => 'ride',
        'road_biking'            => 'ride',
        'mountain_biking'        => 'ride',
        'mountainbiking'         => 'ride',
        'mtb'                    => 'ride',
        'gravel_cycling'         => 'ride',
        'gravelcycling'          => 'ride',
        'cyclocross'             => 'ride',
        'indoor_cycling'         => 'ride',
        'indoorcycling'          => 'ride',
        'virtual_ride'           => 'ride',
        'virtualride'            => 'ride',
        'track_cycling'          => 'ride',
        'trackcycling'           => 'ride',
        'downhill_biking'        => 'ride',
        'recumbent_biking'       => 'ride',
        'hand_cycling'           => 'ride',
        'e_bike_fitness'         => 'ride',
        'e_biking'               => 'ride',
        'ebike'                  => 'ride',
        'e-bike'                 => 'ride',

        // ---------- swim ----------
        'swim'                   => 'swim',
        'swimming'               => 'swim',
        'open_water_swimming'    => 'swim',
        'openwaterswimming'      => 'swim',
        'pool_swimming'          => 'swim',
        'poolswimming'           => 'swim',
        'lap_swimming'           => 'swim',
        'lapswimming'            => 'swim',

        // ---------- ski ----------
        'ski'                    => 'ski',
        'skiing'                 => 'ski',
        'alpine_skiing'          => 'ski',
        'alpineskiing'           => 'ski',
        'downhill_skiing'        => 'ski',
        'cross_country_skiing'   => 'ski',
        'crosscountryskiing'     => 'ski',
        'nordic_skiing'          => 'ski',
        'backcountry_skiing'     => 'ski',
        'backcountryskiing'      => 'ski',
        'snowboarding'           => 'ski',
        'snowshoeing'            => 'ski',
        'roller_skiing'          => 'ski',

        // ---------- walk ----------
        'walk'                   => 'walk',
        'walking'                => 'walk',
        'indoor_walking'         => 'walk',
        'indoorwalking'          => 'walk',
        'casual_walking'         => 'walk',
        'speed_walking'          => 'walk',

        // ---------- hike ----------
        'hike'                   => 'hike',
        'hiking'                 => 'hike',
        'mountaineering'         => 'hike',
        'trekking'               => 'hike',
        'rucking'                => 'hike',
        'backpacking'            => 'hike',

        // ---------- other (явные «прочие») ----------
        'other'                  => 'other',
        'generic'                => 'other',
        'workout'                => 'other',
        'training'               => 'other',
        'fitness_equipment'      => 'other',
        'rowing'                 => 'other',
        'kayaking'               => 'other',
        'paddling'               => 'other',
        'stand_up_paddleboarding'=> 'other',
        'surfing'                => 'other',
        'sailing'                => 'other',
        'inline_skating'         => 'other',
        'ice_skating'            => 'other',
        'rock_climbing'          => 'other',
        'floor_climbing'         => 'other',
        'sky_diving'             => 'other',
        'horseback_riding'       => 'other',
    ];

    /**
     * @param string|null $sourceType Сырой тип из файла (может быть null).
     * @param array       $summary    Результат GpxParser::summarize().
     * @param string      $fileName   Имя загруженного файла (для эвристик).
     * @return string Один из: run|ride|swim|ski|walk|hike|other
     */
    public static function detect(
        ?string $sourceType,
        array $summary,
        string $fileName = ''
    ): string {
        // 1. Явный тип из файла — самый приоритетный источник.
        if ($sourceType !== null && $sourceType !== '') {
            $mapped = self::mapSourceType($sourceType);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        // 2. Эвристика по метрикам.
        return self::heuristic($summary, $fileName);
    }

    /**
     * Пытается преобразовать сырой тип в наш enum.
     * Возвращает null, если тип неизвестен (тогда пойдёт эвристика).
     */
    private static function mapSourceType(string $sourceType): ?string
    {
        $key = strtolower(trim($sourceType));
        $key = str_replace([' ', '-'], '_', $key);

        if ($key === '') return null;

        // Точное совпадение
        if (isset(self::MAP[$key])) {
            return self::MAP[$key];
        }

        // Частичное совпадение: 'road_biking_race' содержит 'biking'
        // Ищем самый длинный ключ, который встречается в строке.
        $best = null;
        $bestLen = 0;
        foreach (self::MAP as $needle => $target) {
            if ($needle === 'other' || $needle === 'generic') continue;
            if (str_contains($key, $needle) && strlen($needle) > $bestLen) {
                $best = $target;
                $bestLen = strlen($needle);
            }
        }

        return $best;
    }

    /**
     * Эвристика по метрикам. Используется, когда source_type отсутствует
     * или не распознан.
     */
    private static function heuristic(array $s, string $fileName): string
    {
        $distM   = (float)($s['distance_m'] ?? 0);
        $durSec  = (int)($s['duration_sec'] ?? 0);
        $avgMps  = isset($s['avg_speed_mps']) && $s['avg_speed_mps'] !== null
                   ? (float)$s['avg_speed_mps']
                   : null;
        $cad     = isset($s['avg_cadence']) && $s['avg_cadence'] !== null
                   ? (int)$s['avg_cadence']
                   : null;
        $pwr     = isset($s['avg_power_w']) && $s['avg_power_w'] !== null
                   ? (int)$s['avg_power_w']
                   : null;
        $temp    = isset($s['avg_temp_c']) && $s['avg_temp_c'] !== null
                   ? (float)$s['avg_temp_c']
                   : null;
        $elev    = (float)($s['elevation_gain_m'] ?? 0);
        $gpsCnt  = (int)($s['gps_points_count'] ?? 0);
        $hasGps  = $gpsCnt > 0;

        // Скорость в км/ч
        $kmh = $avgMps !== null ? $avgMps * 3.6 : null;

        // ---------- 0. Подсказки из имени файла ----------
        $lowerName = strtolower($fileName);
        if (str_contains($lowerName, 'swim') || str_contains($lowerName, 'pool')) {
            return 'swim';
        }
        if (str_contains($lowerName, 'ride') || str_contains($lowerName, 'bike')
            || str_contains($lowerName, 'cycling') || str_contains($lowerName, 'velo')) {
            return 'ride';
        }
        if (str_contains($lowerName, 'run') || str_contains($lowerName, 'beg')) {
            return 'run';
        }
        if (str_contains($lowerName, 'ski') || str_contains($lowerName, 'lyzh')) {
            return 'ski';
        }
        if (str_contains($lowerName, 'hike') || str_contains($lowerName, 'trek')) {
            return 'hike';
        }
        if (str_contains($lowerName, 'walk') || str_contains($lowerName, 'hodba')) {
            return 'walk';
        }

        // ---------- 1. Плавание ----------
        // Признаки: нет GPS (бассейн) ИЛИ очень низкая скорость.
        if (!$hasGps && $durSec > 0 && $distM > 0) {
            // Бассейн: нет GPS, но есть дистанция и время
            return 'swim';
        }
        if ($kmh !== null && $kmh > 0 && $kmh < 4.5 && $distM > 0) {
            // Очень низкая скорость — почти наверняка плавание
            return 'swim';
        }

        // ---------- 2. Велосипед ----------
        // Признаки: высокая скорость, мощность, каденс 60–110.
        if ($kmh !== null && $kmh >= 15) {
            // Есть мощность — точно велосипед (бегуны редко имеют power meter)
            if ($pwr !== null && $pwr > 0) {
                return 'ride';
            }
            // Каденс велосипедиста (60–110 об/мин)
            if ($cad !== null && $cad >= 60 && $cad <= 110) {
                return 'ride';
            }
            // Даже без датчиков: > 25 км/ч — точно не бег
            if ($kmh >= 25) {
                return 'ride';
            }
        }

        // ---------- 3. Лыжи ----------
        // Признаки: холодно + средняя скорость + нет каденса или низкий.
        if ($temp !== null && $temp < 5) {
            if ($kmh !== null && $kmh >= 5 && $kmh <= 25) {
                return 'ski';
            }
            // Очень холодно и есть движение — почти наверняка лыжи
            if ($temp < -5 && $kmh !== null && $kmh >= 3) {
                return 'ski';
            }
        }

        // ---------- 4. Бег ----------
        // Признаки: скорость 7–18 км/ч, каденс 150–200.
        if ($kmh !== null && $kmh >= 7 && $kmh <= 18) {
            if ($cad !== null && $cad >= 150) {
                return 'run';
            }
            // Без каденса: 8–15 км/ч — очень похоже на бег
            if ($kmh >= 8 && $kmh <= 15) {
                return 'run';
            }
        }

        // ---------- 5. Ходьба / хайкинг ----------
        // Признаки: скорость 3–7 км/ч.
        if ($kmh !== null && $kmh >= 3 && $kmh < 7) {
            // Хайкинг: если есть серьёзный набор высоты и скорость < 6
            if ($elev > 100 && $kmh < 6) {
                return 'hike';
            }
            return 'walk';
        }

        // ---------- 6. Совсем медленно ----------
        if ($kmh !== null && $kmh > 0 && $kmh < 3) {
            // Медленнее ходьбы — вероятно, плавание или что-то специфичное
            if (!$hasGps) return 'swim';
            return 'walk';
        }

        // ---------- 7. Не смогли определить ----------
        return 'other';
    }
}
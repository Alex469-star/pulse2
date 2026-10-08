<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Segment.php';
require_once __DIR__ . '/../models/Activity.php';
require_once __DIR__ . '/GpxParser.php';

class SegmentMatcher
{
    private const GATE_RADIUS_M = 50;
    private const MIN_SEGMENT_LENGTH_M = 50;
    private const MAX_ACTIVITIES_PER_RUN = 5000;

    // ============================================================
    // 1. МАТЧ ОДНОЙ АКТИВНОСТИ ПО ВСЕМ СЕГМЕНТАМ
    // ============================================================

    public static function matchAllForActivity(int $activityId): array
    {
        $result = ['matched' => 0, 'checked' => 0, 'errors' => []];

        $activity = Activity::findById($activityId);
        if (!$activity) {
            $result['errors'][] = 'Активность не найдена';
            return $result;
        }

        if (empty($activity['track_json'])) {
            return $result;
        }

        $activityTrack = json_decode((string)$activity['track_json'], true);
        if (!is_array($activityTrack) || count($activityTrack) < 2) {
            return $result;
        }

        $activityType = (string)$activity['type'];
        $userId       = (int)$activity['user_id'];

                // ---- 1. Только ID сегментов ----
        $stmt = db()->prepare(
            'SELECT id FROM segments
             WHERE is_public = 1 AND type = ?
             LIMIT 2000'
        );
        $stmt->execute([$activityType]);
        $segmentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$segmentIds) return $result;

        $selectSeg = db()->prepare(
            'SELECT id, track_json, distance_m, type
             FROM segments WHERE id = ? LIMIT 1'
        );

        foreach ($segmentIds as $segmentId) {
            $segmentId = (int)$segmentId;
            $result['checked']++;

            try {
                $selectSeg->execute([$segmentId]);
                $segment = $selectSeg->fetch();
                if (!$segment) continue;

                $segmentTrack = Segment::parseTrackJson((string)$segment['track_json']);
                unset($segment);

                if (count($segmentTrack) < 2) { unset($segmentTrack); continue; }

                $segmentLength = self::trackLength($segmentTrack);
                if ($segmentLength < self::MIN_SEGMENT_LENGTH_M) { unset($segmentTrack); continue; }

                $start  = $segmentTrack[0];
                $finish = $segmentTrack[count($segmentTrack) - 1];
                unset($segmentTrack);

                $match = self::matchActivityToSegment($activityTrack, $start, $finish, (int)$segmentLength);

                if ($match === null) {
                    self::deleteAutoEffort($segmentId, $activityId);
                    try { Segment::notifyLeadershipChange($segmentId); } catch (Throwable $e) {}
                    continue;
                }

                self::saveAutoEffort(
                    $segmentId, $activityId, $userId,
                    $match['elapsed_sec'], $match['started_at'],
                    $match['matched_distance_m'], $match['quality']
                );

                try { Segment::notifyLeadershipChange($segmentId); } catch (Throwable $e) {}
                $result['matched']++;
            } catch (Throwable $e) {
                $result['errors'][] = 'Сегмент #' . $segmentId . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    // ============================================================
    // 2. МАТЧ ОДНОГО СЕГМЕНТА ПО ВСЕМ АКТИВНОСТЯМ ВСЕХ ЮЗЕРОВ
    // ============================================================

        public static function matchAllUsersForSegment(int $segmentId, ?int $onlyUserId = null): array
    {
        $result = ['processed' => 0, 'matched' => 0, 'errors' => []];

        $segment = Segment::findById($segmentId);
        if (!$segment) {
            $result['errors'][] = 'Сегмент не найден';
            return $result;
        }

        $segmentTrack = Segment::parseTrackJson((string)$segment['track_json']);
        if (count($segmentTrack) < 2) {
            $result['errors'][] = 'Трек сегмента пуст';
            return $result;
        }

        $segmentLength = self::trackLength($segmentTrack);
        if ($segmentLength < self::MIN_SEGMENT_LENGTH_M) {
            $result['errors'][] = 'Сегмент слишком короткий';
            return $result;
        }

        $start  = $segmentTrack[0];
        $finish = $segmentTrack[count($segmentTrack) - 1];

        // ---- 1. Только ID активностей, без track_json ----
        $sql = 'SELECT id
                FROM activities
                WHERE type = ? AND track_json IS NOT NULL';
        $params = [(string)$segment['type']];

        if ($onlyUserId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $onlyUserId;
        }

        $sql .= ' ORDER BY started_at DESC LIMIT ' . self::MAX_ACTIVITIES_PER_RUN;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $activityIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        unset($stmt);

        if (!$activityIds) return $result;

        // ---- 2. Обрабатываем по одной, грузим track_json точечно ----
        $selectOne = db()->prepare(
            'SELECT id, user_id, track_json
             FROM activities
             WHERE id = ? LIMIT 1'
        );

        foreach ($activityIds as $activityId) {
            $activityId = (int)$activityId;
            $result['processed']++;

            try {
                $selectOne->execute([$activityId]);
                $activity = $selectOne->fetch();

                if (!$activity || empty($activity['track_json'])) {
                    continue;
                }

                $userId = (int)$activity['user_id'];
                $activityTrack = json_decode((string)$activity['track_json'], true);

                // освобождаем память сразу
                unset($activity['track_json'], $activity);

                if (!is_array($activityTrack) || count($activityTrack) < 2) {
                    unset($activityTrack);
                    continue;
                }

                $match = self::matchActivityToSegment(
                    $activityTrack,
                    $start,
                    $finish,
                    (int)$segmentLength
                );

                // освобождаем трек сразу после матчинга
                unset($activityTrack);

                if ($match === null) {
                    self::deleteAutoEffort($segmentId, $activityId);
                    continue;
                }

                self::saveAutoEffort(
                    $segmentId,
                    $activityId,
                    $userId,
                    $match['elapsed_sec'],
                    $match['started_at'],
                    $match['matched_distance_m'],
                    $match['quality']
                );
                $result['matched']++;
            } catch (Throwable $e) {
                $result['errors'][] = 'Активность #' . $activityId . ': ' . $e->getMessage();
            }
        }

        try {
            Segment::notifyLeadershipChange($segmentId);
        } catch (Throwable $e) {}

        return $result;
    }

    // ============================================================
    // 3. СОВМЕСТИМОСТЬ
    // ============================================================

    public static function rematchUser(int $userId, int $segmentId, int $segmentType = 0): array
    {
        return self::matchAllUsersForSegment($segmentId, $userId);
    }

    // ============================================================
    // 4. ЯДРО МАТЧИНГА
    // ============================================================

    public static function matchActivityToSegment(
        array $activityTrack,
        array $start,
        array $finish,
        int $segmentLength
    ): ?array {
        $startIdx = self::findClosestIndex($activityTrack, $start['lat'], $start['lng'], self::GATE_RADIUS_M);
        if ($startIdx === null) return null;

        $finishIdx = null;
        $bestDist = PHP_FLOAT_MAX;
        $minGap = 3;

        for ($i = $startIdx + $minGap; $i < count($activityTrack); $i++) {
            $pt = $activityTrack[$i];
            if (!isset($pt['lat'], $pt['lng'])) continue;

            $d = GpxParser::haversinePublic(
                (float)$pt['lat'], (float)$pt['lng'],
                (float)$finish['lat'], (float)$finish['lng']
            );

            if ($d <= self::GATE_RADIUS_M && $d < $bestDist) {
                $bestDist = $d;
                $finishIdx = $i;
            }
        }

        if ($finishIdx === null) return null;

        $startT  = $activityTrack[$startIdx]['t'] ?? null;
        $finishT = $activityTrack[$finishIdx]['t'] ?? null;

        if ($startT && $finishT && $finishT > $startT) {
            $elapsed = $finishT - $startT;
            $startedAt = date('Y-m-d H:i:s', $startT);
        } else {
            $elapsed = self::estimateTimeByDistance($activityTrack, $startIdx, $finishIdx);
            $startedAt = null;
            if ($elapsed === null || $elapsed <= 0) return null;
        }

        $actualDistance = 0.0;
        for ($i = $startIdx + 1; $i <= $finishIdx; $i++) {
            $a = $activityTrack[$i - 1];
            $b = $activityTrack[$i];
            if (!isset($a['lat'], $a['lng'], $b['lat'], $b['lng'])) continue;
            $actualDistance += GpxParser::haversinePublic(
                (float)$a['lat'], (float)$a['lng'],
                (float)$b['lat'], (float)$b['lng']
            );
        }

        $quality = 100;
        if ($segmentLength > 0) {
            $ratio = $actualDistance / $segmentLength;
            if ($ratio < 0.8) {
                $quality = (int)max(0, $ratio * 100);
            } elseif ($ratio > 1.5) {
                $quality = (int)max(0, 100 - ($ratio - 1.5) * 100);
            }
        }

        return [
            'elapsed_sec'        => (int)$elapsed,
            'started_at'         => $startedAt,
            'matched_distance_m' => round($actualDistance, 2),
            'quality'            => $quality,
        ];
    }

    // ============================================================
    // 5. ХЕЛПЕРЫ
    // ============================================================

    private static function findClosestIndex(array $track, float $lat, float $lng, float $radius): ?int
    {
        $bestIdx = null;
        $bestDist = PHP_FLOAT_MAX;
        foreach ($track as $i => $pt) {
            if (!isset($pt['lat'], $pt['lng'])) continue;
            $d = GpxParser::haversinePublic((float)$pt['lat'], (float)$pt['lng'], $lat, $lng);
            if ($d <= $radius && $d < $bestDist) {
                $bestDist = $d;
                $bestIdx = $i;
            }
        }
        return $bestIdx;
    }

    private static function estimateTimeByDistance(array $track, int $fromIdx, int $toIdx): ?int
    {
        $totalDist = 0.0;
        $totalTime = 0;
        $firstT = null;
        $lastT  = null;

        for ($i = 1; $i < count($track); $i++) {
            $a = $track[$i - 1];
            $b = $track[$i];
            if (!isset($a['lat'], $a['lng'], $b['lat'], $b['lng'])) continue;
            $totalDist += GpxParser::haversinePublic(
                (float)$a['lat'], (float)$a['lng'],
                (float)$b['lat'], (float)$b['lng']
            );
            if (isset($a['t']) && $a['t'] > 0 && $firstT === null) $firstT = $a['t'];
            if (isset($b['t']) && $b['t'] > 0) $lastT = $b['t'];
        }

        if ($firstT && $lastT && $lastT > $firstT) $totalTime = $lastT - $firstT;
        if ($totalDist <= 0 || $totalTime <= 0) return null;

        $speed = $totalDist / $totalTime;
        $dist = 0.0;
        for ($i = $fromIdx + 1; $i <= $toIdx; $i++) {
            $a = $track[$i - 1];
            $b = $track[$i];
            if (!isset($a['lat'], $a['lng'], $b['lat'], $b['lng'])) continue;
            $dist += GpxParser::haversinePublic(
                (float)$a['lat'], (float)$a['lng'],
                (float)$b['lat'], (float)$b['lng']
            );
        }
        if ($speed <= 0) return null;
        return (int)round($dist / $speed);
    }

    private static function trackLength(array $track): float
    {
        $d = 0.0;
        for ($i = 1; $i < count($track); $i++) {
            $a = $track[$i - 1];
            $b = $track[$i];
            if (!isset($a['lat'], $a['lng'], $b['lat'], $b['lng'])) continue;
            $d += GpxParser::haversinePublic(
                (float)$a['lat'], (float)$a['lng'],
                (float)$b['lat'], (float)$b['lng']
            );
        }
        return $d;
    }

    private static function saveAutoEffort(
        int $segmentId,
        int $activityId,
        int $userId,
        int $elapsedSec,
        ?string $startedAt,
        ?float $matchedDistance,
        ?int $quality
    ): void {
        $s = db()->prepare(
            'INSERT INTO segment_efforts
                (segment_id, activity_id, user_id, elapsed_time_sec, is_auto,
                 matched_distance_m, match_quality, started_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                elapsed_time_sec   = VALUES(elapsed_time_sec),
                is_auto            = 1,
                matched_distance_m = VALUES(matched_distance_m),
                match_quality      = VALUES(match_quality),
                started_at         = VALUES(started_at)'
        );
        $s->execute([$segmentId, $activityId, $userId, $elapsedSec,
                     $matchedDistance, $quality, $startedAt]);
    }

    private static function deleteAutoEffort(int $segmentId, int $activityId): void
    {
        db()->prepare(
            'DELETE FROM segment_efforts
             WHERE segment_id = ? AND activity_id = ? AND is_auto = 1'
        )->execute([$segmentId, $activityId]);
    }
}
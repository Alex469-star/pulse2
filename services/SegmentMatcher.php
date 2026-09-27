<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Segment.php';
require_once __DIR__ . '/GpxParser.php';

class SegmentMatcher
{
    private const GATE_RADIUS_M = 50;
    private const MIN_SEGMENT_LENGTH_M = 50;

    /**
     * Пересчёт усилий пользователя по сегменту + проверка смены лидера.
     */
    public static function rematchUser(int $userId, int $segmentId, int $segmentType = 0): array
    {
        $segment = Segment::findById($segmentId);
        if (!$segment) {
            return ['processed' => 0, 'matched' => 0, 'errors' => ['Сегмент не найден']];
        }

        // Запоминаем предыдущего лидера ДО матчинга
        $previousLeader = Segment::currentLeader($segmentId);

        $segmentTrack = Segment::parseTrackJson((string)$segment['track_json']);
        if (count($segmentTrack) < 2) {
            return ['processed' => 0, 'matched' => 0, 'errors' => ['Трек сегмента пуст']];
        }

        $segmentLength = self::trackLength($segmentTrack);
        if ($segmentLength < self::MIN_SEGMENT_LENGTH_M) {
            return ['processed' => 0, 'matched' => 0, 'errors' => ['Сегмент слишком короткий']];
        }

        $start  = $segmentTrack[0];
        $finish = $segmentTrack[count($segmentTrack) - 1];

        $activities = self::userActivities($userId, (string)$segment['type']);

        $processed = 0;
        $matched   = 0;
        $errors    = [];

        foreach ($activities as $activity) {
            $processed++;
            try {
                $activityTrack = json_decode((string)$activity['track_json'], true);
                if (!is_array($activityTrack) || count($activityTrack) < 2) continue;

                $match = self::matchActivityToSegment(
                    $activityTrack,
                    $start,
                    $finish,
                    (int)$segmentLength
                );

                if ($match === null) {
                    self::deleteAutoEffort($segmentId, (int)$activity['id']);
                    continue;
                }

                self::saveAutoEffort(
                    $segmentId,
                    (int)$activity['id'],
                    $userId,
                    $match['elapsed_sec'],
                    $match['started_at'],
                    $match['matched_distance_m'],
                    $match['quality']
                );
                $matched++;
            } catch (Throwable $e) {
                $errors[] = 'Активность #' . $activity['id'] . ': ' . $e->getMessage();
            }
        }

        // После матчинга — проверяем, изменился ли лидер
        if ($matched > 0) {
            Segment::notifyLeadershipChange($segmentId, $previousLeader);
        }

        return ['processed' => $processed, 'matched' => $matched, 'errors' => $errors];
    }

    /**
     * Матчинг одного трека к сегменту.
     */
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

    private static function userActivities(int $userId, string $type): array
    {
        $s = db()->prepare(
            'SELECT id, track_json, started_at
             FROM activities
             WHERE user_id = ? AND type = ? AND track_json IS NOT NULL
             ORDER BY created_at DESC
             LIMIT 200'
        );
        $s->execute([$userId, $type]);
        return $s->fetchAll();
    }
}
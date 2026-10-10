<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/redis.php';

/**
 * Универсальная обёртка для кэша поверх Redis.
 * 
 * Все методы безопасны: если Redis недоступен — они не бросают исключений,
 * просто возвращают null / false / 0.
 * 
 * Пример:
 *   $data = Cache::remember('user:42', fn() => User::findById(42), 300);
 *   Cache::forget('user:42');
 */
final class Cache
{
    /**
     * Получить значение из кэша.
     * @return mixed null, если нет ключа или Redis недоступен
     */
    public static function get(string $key): mixed
    {
        try {
            $v = redis()->get($key);
            return $v === false || $v === null ? null : $v;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Положить значение в кэш.
     * @param int $ttl Время жизни в секундах. 0 = без TTL (не рекомендуется)
     */
    public static function set(string $key, mixed $value, int $ttl = 300): bool
    {
        try {
            if ($ttl > 0) {
                return (bool)redis()->setex($key, $ttl, $value);
            }
            return (bool)redis()->set($key, $value);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Удалить ключ.
     */
    public static function forget(string $key): bool
    {
        try {
            return (bool)redis()->del($key);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Удалить по шаблону (использует SCAN, не блокирует Redis).
     * Например: Cache::forgetByPattern('club.liveStats:*');
     */
    public static function forgetByPattern(string $pattern): int
    {
        try {
            $r = redis();
            if (!method_exists($r, 'scan')) return 0;

            $it = null;
            $count = 0;
            $r->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
            while (($keys = $r->scan($it, $pattern, 500)) !== false) {
                if (!empty($keys)) {
                    $count += (int)$r->del($keys);
                }
                if ($it === 0) break;
            }
            return $count;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Получить из кэша или посчитать и положить.
     * 
     * @param callable $callback Функция-генератор значения (без аргументов)
     * @param int $ttl TTL в секундах
     */
    public static function remember(string $key, callable $callback, int $ttl = 300): mixed
    {
        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();

        // Не кэшируем null — иначе «отсутствие» залипнет на весь TTL
        if ($value !== null) {
            self::set($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Инкремент (для rate limiting).
     * Возвращает новое значение. Устанавливает TTL при первом создании ключа.
     */
    public static function incr(string $key, int $ttl = 60): int
    {
        try {
            $r = redis();
            $val = (int)$r->incr($key);
            if ($val === 1 && $ttl > 0) {
                $r->expire($key, $ttl);
            }
            return $val;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Хелпер: собрать ключ с суффиксами.
     * 
     * Cache::key('club.liveStats', 42) → 'club.liveStats:42'
     * Cache::key('club.leaderboard', 42, 'week', 5) → 'club.leaderboard:42:week:5'
     */
    public static function key(string $base, mixed ...$parts): string
    {
        if (!$parts) return $base;
        return $base . ':' . implode(':', array_map(
            fn($p) => is_scalar($p) ? (string)$p : md5(json_encode($p)),
            $parts
        ));
    }

    /**
     * Проверить, доступен ли Redis (для отладки).
     */
    public static function available(): bool
    {
        try {
            $r = redis();
            return $r instanceof Redis && $r->ping();
        } catch (Throwable $e) {
            return false;
        }
    }
}
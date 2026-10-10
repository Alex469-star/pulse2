<?php
declare(strict_types=1);

/**
 * Singleton-подключение к Redis.
 * 
 * Использование:
 *   $r = redis();
 *   $r->set('key', 'value', 60);
 * 
 * Если Redis недоступен или отключён в конфиге — возвращает NullObject,
 * который безопасно игнорирует все вызовы. Сайт продолжит работать без кэша.
 */

final class NullRedis
{
    public function __call(string $name, array $args)
    {
        // Для get-подобных методов возвращаем null, для остальных false
        if (in_array($name, ['get', 'hget', 'hgetall', 'lrange', 'smembers', 'keys'], true)) {
            return null;
        }
        return false;
    }

    public function ping(): bool { return false; }
    public function isConnected(): bool { return false; }
    public function exists(...$args): int { return 0; }
    public function del(...$args): int { return 0; }
    public function ttl(string $key): int { return -1; }
    public function incr(string $key, int $by = 1): int { return 0; }
    public function expire(string $key, int $ttl): bool { return false; }
    public function setex(string $key, int $ttl, $value): bool { return false; }
    public function set(string $key, $value, $opts = null): bool { return false; }
    public function select(int $db): bool { return false; }
    public function auth(string $password): bool { return false; }
    public function setOption(int $opt, $value): bool { return false; }
}

function redis(): Redis|NullRedis
{
    static $instance = null;
    if ($instance !== null) return $instance;

        $config = config()['redis'] ?? [];

    // Отключён только если явно указано false.
    // Если секции нет вообще — считаем включённым и берём дефолты.
    if (isset($config['enabled']) && $config['enabled'] === false) {
        return $instance = new NullRedis();
    }

    // Расширение не установлено
    if (!class_exists('Redis')) {
        error_log('Redis extension not loaded — falling back to NullRedis');
        return $instance = new NullRedis();
    }

    try {
        $r = new Redis();
        $ok = $r->connect(
            $config['host'] ?? '127.0.0.1',
            (int)($config['port'] ?? 6379),
            1.0 // таймаут соединения 1 сек
        );
        if (!$ok) {
            throw new RuntimeException('Redis connect failed');
        }

        if (!empty($config['password'])) {
            $r->auth((string)$config['password']);
        }
        if (!empty($config['database'])) {
            $r->select((int)$config['database']);
        }
        if (!empty($config['prefix'])) {
            $r->setOption(Redis::OPT_PREFIX, (string)$config['prefix']);
        }

        // Сериализация PHP: можно класть массивы, объекты
        $r->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

        // Не бросать исключения при ошибках — сами обработаем
        $r->setOption(Redis::OPT_REPLY_LITERAL, false);

        $instance = $r;
    } catch (Throwable $e) {
        error_log('Redis connection failed: ' . $e->getMessage());
        $instance = new NullRedis();
    }

    return $instance;
}
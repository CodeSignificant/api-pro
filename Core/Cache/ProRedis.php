<?php

class ProRedis
{
    private static ?Redis $redis = null;

    /**
     * Establish and return the Redis connection
     */
    public static function connect(): ?Redis
    {
        if (self::$redis === null) {
            if (!extension_loaded('redis')) {
                error_log("ApiPro Error: ext-redis is not installed on this server.");
                return null;
            }

            self::$redis = new Redis();
            
            try {
                // Use persistent connection for better performance across multiple servers
                $host = defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1';
                $port = defined('REDIS_PORT') ? REDIS_PORT : 6379;
                
                $connected = self::$redis->pconnect($host, $port, 2.5); // 2.5 sec timeout
                
                if (!$connected) {
                    error_log("ApiPro Error: Failed to connect to Redis at $host:$port");
                    self::$redis = null;
                    return null;
                }

                $pass = defined('REDIS_PASS') ? REDIS_PASS : null;
                if ($pass !== null && $pass !== '') {
                    self::$redis->auth($pass);
                }

                $db = defined('REDIS_DB') ? REDIS_DB : 0;
                if ($db !== 0) {
                    self::$redis->select($db);
                }

            } catch (Exception $e) {
                error_log("ApiPro Error: Redis Connection Exception: " . $e->getMessage());
                self::$redis = null;
                return null;
            }
        }

        return self::$redis;
    }

    /**
     * Get the Redis instance directly
     */
    public static function instance(): ?Redis
    {
        return self::connect();
    }

    public static function get(string $key)
    {
        $redis = self::instance();
        return $redis ? $redis->get($key) : false;
    }

    public static function set(string $key, $value, ?int $ttl = null): bool
    {
        $redis = self::instance();
        if (!$redis) return false;
        if ($ttl !== null) {
            return $redis->setex($key, $ttl, $value);
        }
        return $redis->set($key, $value);
    }

    public static function del(string $key): int
    {
        $redis = self::instance();
        return $redis ? $redis->del($key) : 0;
    }

    public static function keys(string $pattern): array
    {
        $redis = self::instance();
        if (!$redis) return [];
        $res = $redis->keys($pattern);
        return is_array($res) ? $res : [];
    }
}

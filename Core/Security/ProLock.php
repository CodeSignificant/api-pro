<?php

class ProLock
{
    /**
     * Acquire a distributed lock.
     * 
     * @param string $key The lock identifier (e.g., 'booking_room_123')
     * @param int $ttlSeconds The maximum time to hold the lock before it automatically expires
     * @return bool True if lock acquired, False if someone else already holds it
     */
    public static function acquire(string $key, int $ttlSeconds = 10): bool
    {
        $redis = ProRedis::instance();

        if ($redis === null) {
            // If Redis is down, we must fail-close for critical operations (prevent race conditions by denying the action)
            // Or throw an exception depending on strictness. Here we just say "cannot acquire lock".
            return false; 
        }

        $lockKey = "apipro:lock:" . $key;
        
        try {
            // SETNX (Set if Not eXists) is atomic
            $acquired = $redis->set($lockKey, "locked", ['nx', 'ex' => $ttlSeconds]);
            return (bool)$acquired;
        } catch (Exception $e) {
            error_log("ApiPro Error: ProLock acquire exception - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Release a distributed lock.
     * 
     * @param string $key The lock identifier
     */
    public static function release(string $key): void
    {
        $redis = ProRedis::instance();
        
        if ($redis === null) {
            return;
        }

        $lockKey = "apipro:lock:" . $key;

        try {
            $redis->del($lockKey);
        } catch (Exception $e) {
            error_log("ApiPro Error: ProLock release exception - " . $e->getMessage());
        }
    }
}

<?php

class RateLimiter
{
    /**
     * Check if a key has exceeded its rate limit.
     * 
     * @param string $key The unique identifier (e.g., IP address, User ID)
     * @param int $maxRequests Maximum number of requests allowed
     * @param int $decaySeconds The time window in seconds
     * @return bool True if request is allowed, False if limit exceeded
     */
    public static function check(string $key, int $maxRequests = 60, int $decaySeconds = 60): bool
    {
        $redis = ProRedis::instance();

        // If Redis is not available, fail-open (allow request) so the API doesn't go down
        if ($redis === null) {
            return true;
        }

        $rateKey = "apipro:ratelimit:" . $key;

        try {
            $currentCount = $redis->get($rateKey);

            if ($currentCount !== false && (int)$currentCount >= $maxRequests) {
                return false; // Limit exceeded
            }

            // Atomic increment
            $redis->incr($rateKey);
            
            // Set expiration only on the first request
            if ($currentCount === false) {
                $redis->expire($rateKey, $decaySeconds);
            }

            return true;

        } catch (Exception $e) {
            error_log("ApiPro Error: RateLimiter exception - " . $e->getMessage());
            // Fail-open
            return true;
        }
    }
}

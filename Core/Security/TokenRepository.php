<?php

class TokenRepository extends Repository
{
    private string $driver;

    public function __construct(string $driver = 'stateless')
    {
        $this->driver = strtolower($driver);

        parent::__construct([
            'active_sessions' => [
                'lock' => true,                       // Protect active_sessions table against structure drops or changes
                'schema' => "CREATE TABLE IF NOT EXISTS active_sessions (
                    user_id INT NOT NULL,
                    device_id VARCHAR(255) NOT NULL,
                    device_name VARCHAR(255) NULL,
                    ip_address VARCHAR(45) NULL,
                    user_agent VARCHAR(512) NULL,
                    last_active DATETIME NOT NULL,
                    expires_at DATETIME NOT NULL,
                    PRIMARY KEY (user_id, device_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
            ]
        ]);
    }

    /**
     * Save/upsert the active session details
     */
    public function save(
        $userId,
        string $deviceId,
        ?string $deviceName,
        ?string $ipAddress,
        ?string $userAgent,
        int $expiresAtTimestamp
    ): void {
        if ($this->driver === 'stateless') {
            return;
        }

        $deviceName = $deviceName ?: 'Unknown Device';
        $ipAddress = $ipAddress ?: '127.0.0.1';
        $userAgent = $userAgent ?: 'Unknown';
        $lastActive = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', $expiresAtTimestamp);

        if ($this->driver === 'redis') {
            $key = "apipro:session:{$userId}:{$deviceId}";
            $payload = [
                'device_id' => $deviceId,
                'device_name' => $deviceName,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'last_active' => $lastActive,
                'expires_at' => $expiresAt
            ];
            $ttl = max(1, $expiresAtTimestamp - time());
            ProRedis::set($key, json_encode($payload), $ttl);
            return;
        }

        if ($this->driver === 'database') {
            $escUserId = (int)$userId;
            $escDeviceId = ProSql::Escape($deviceId);
            $escDeviceName = ProSql::Escape($deviceName);
            $escIpAddress = ProSql::Escape($ipAddress);
            $escUserAgent = ProSql::Escape($userAgent);
            $escLastActive = ProSql::Escape($lastActive);
            $escExpiresAt = ProSql::Escape($expiresAt);

            $query = "REPLACE INTO active_sessions 
                (user_id, device_id, device_name, ip_address, user_agent, last_active, expires_at)
                VALUES 
                ($escUserId, '$escDeviceId', '$escDeviceName', '$escIpAddress', '$escUserAgent', '$escLastActive', '$escExpiresAt')";
            ProSql::Update($query);
        }
    }

    /**
     * Delete/Revoke a specific device session
     */
    public function delete($userId, string $deviceId): void
    {
        if ($this->driver === 'stateless') {
            return;
        }

        if ($this->driver === 'redis') {
            $key = "apipro:session:{$userId}:{$deviceId}";
            ProRedis::del($key);
            return;
        }

        if ($this->driver === 'database') {
            $escUserId = (int)$userId;
            $escDeviceId = ProSql::Escape($deviceId);
            $query = "DELETE FROM active_sessions WHERE user_id = $escUserId AND device_id = '$escDeviceId'";
            ProSql::Update($query);
        }
    }

    /**
     * Get all active sessions for a specific user ID
     */
    public function getByUser($userId): array
    {
        if ($this->driver === 'stateless') {
            return [];
        }

        $sessions = [];

        if ($this->driver === 'redis') {
            // Find all matching keys
            $pattern = "apipro:session:{$userId}:*";
            $keys = ProRedis::keys($pattern);
            if (!empty($keys) && is_array($keys)) {
                foreach ($keys as $key) {
                    $raw = ProRedis::get($key);
                    if ($raw) {
                        $decoded = json_decode($raw, true);
                        if ($decoded) {
                            $sessions[] = $decoded;
                        }
                    }
                }
            }
        } elseif ($this->driver === 'database') {
            $escUserId = (int)$userId;
            $query = "SELECT device_id, device_name, ip_address, user_agent, last_active, expires_at 
                      FROM active_sessions 
                      WHERE user_id = $escUserId AND expires_at > NOW()";
            $res = ProSql::FetchList($query);
            if ($res && $res->success && !empty($res->data)) {
                $sessions = $res->data;
            }
        }

        // Sort by last_active descending for premium UX order
        usort($sessions, function ($a, $b) {
            return strcmp($b['last_active'], $a['last_active']);
        });

        return $sessions;
    }

    /**
     * Delete all sessions for a user EXCEPT a specific device ID
     */
    public function deleteByUserAndExcludeDevice($userId, string $excludeDeviceId): void
    {
        if ($this->driver === 'stateless') {
            return;
        }

        if ($this->driver === 'redis') {
            $pattern = "apipro:session:{$userId}:*";
            $keys = ProRedis::keys($pattern);
            if (!empty($keys) && is_array($keys)) {
                foreach ($keys as $key) {
                    if (substr($key, -strlen($excludeDeviceId)) !== $excludeDeviceId) {
                        ProRedis::del($key);
                    }
                }
            }
        } elseif ($this->driver === 'database') {
            $escUserId = (int)$userId;
            $escExcludeDeviceId = ProSql::Escape($excludeDeviceId);
            $query = "DELETE FROM active_sessions WHERE user_id = $escUserId AND device_id != '$escExcludeDeviceId'";
            ProSql::Update($query);
        }
    }

    /**
     * Revoke all sessions for a specific user ID
     */
    public function deleteAllByUser($userId): void
    {
        if ($this->driver === 'stateless') {
            return;
        }

        if ($this->driver === 'redis') {
            $pattern = "apipro:session:{$userId}:*";
            $keys = ProRedis::keys($pattern);
            if (!empty($keys) && is_array($keys)) {
                foreach ($keys as $key) {
                    ProRedis::del($key);
                }
            }
        } elseif ($this->driver === 'database') {
            $escUserId = (int)$userId;
            $query = "DELETE FROM active_sessions WHERE user_id = $escUserId";
            ProSql::Update($query);
        }
    }
}

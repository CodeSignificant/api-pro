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
                    user_role VARCHAR(50) NOT NULL DEFAULT 'user',
                    device_id VARCHAR(255) NOT NULL,
                    device_name VARCHAR(255) NULL,
                    ip_address VARCHAR(45) NULL,
                    user_agent VARCHAR(512) NULL,
                    last_active DATETIME NOT NULL,
                    expires_at DATETIME NOT NULL,
                    token TEXT NULL,
                    PRIMARY KEY (user_id, user_role, device_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
            ]
        ]);
    }

    /**
     * Save/upsert the active session details
     */
    public function save(
        $userId,
        string $role,
        string $deviceId,
        ?string $deviceName,
        ?string $ipAddress,
        ?string $userAgent,
        int $expiresAtTimestamp,
        ?string $token = null
    ): void {
        if ($this->driver === 'stateless') {
            return;
        }

        $role = !empty($role) ? strtolower(trim($role)) : 'user';
        $deviceName = $deviceName ?: 'Unknown Device';
        $ipAddress = $ipAddress ?: '127.0.0.1';
        $userAgent = $userAgent ?: 'Unknown';
        $lastActive = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', $expiresAtTimestamp);

        if ($this->driver === 'redis') {
            $key = "apipro:session:{$userId}:{$role}:{$deviceId}";
            $payload = [
                'user_id' => $userId,
                'user_role' => $role,
                'device_id' => $deviceId,
                'device_name' => $deviceName,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'last_active' => $lastActive,
                'expires_at' => $expiresAt,
                'token' => $token
            ];
            $ttl = max(1, $expiresAtTimestamp - time());
            ProRedis::set($key, json_encode($payload), $ttl);
            return;
        }

        if ($this->driver === 'database') {
            $escUserId = (int)$userId;
            $escRole = ProSql::Escape($role);
            $escDeviceId = ProSql::Escape($deviceId);
            $escDeviceName = ProSql::Escape($deviceName);
            $escIpAddress = ProSql::Escape($ipAddress);
            $escUserAgent = ProSql::Escape($userAgent);
            $escLastActive = ProSql::Escape($lastActive);
            $escExpiresAt = ProSql::Escape($expiresAt);
            $escToken = ProSql::Escape($token ?? '');

            $query = "REPLACE INTO active_sessions 
                (user_id, user_role, device_id, device_name, ip_address, user_agent, last_active, expires_at, token)
                VALUES 
                ($escUserId, '$escRole', '$escDeviceId', '$escDeviceName', '$escIpAddress', '$escUserAgent', '$escLastActive', '$escExpiresAt', '$escToken')";
            ProSql::Update($query);
        }
    }

    /**
     * Delete/Revoke a specific device session
     */
    public function delete($userId, string $deviceId, ?string $role = null): void
    {
        if ($this->driver === 'stateless') {
            return;
        }

        if ($role !== null) {
            $role = strtolower(trim($role));
        }

        if ($this->driver === 'redis') {
            if ($role !== null) {
                $key = "apipro:session:{$userId}:{$role}:{$deviceId}";
                ProRedis::del($key);
            } else {
                $pattern = "apipro:session:{$userId}:*:{$deviceId}";
                $keys = ProRedis::keys($pattern);
                if (!empty($keys) && is_array($keys)) {
                    foreach ($keys as $key) {
                        ProRedis::del($key);
                    }
                }
            }
            return;
        }

        if ($this->driver === 'database') {
            $escUserId = (int)$userId;
            $escDeviceId = ProSql::Escape($deviceId);
            if ($role !== null) {
                $escRole = ProSql::Escape($role);
                $query = "DELETE FROM active_sessions WHERE user_id = $escUserId AND user_role = '$escRole' AND device_id = '$escDeviceId'";
            } else {
                $query = "DELETE FROM active_sessions WHERE user_id = $escUserId AND device_id = '$escDeviceId'";
            }
            ProSql::Update($query);
        }
    }

    /**
     * Get all active sessions for a specific user ID and optional role
     */
    public function getByUser($userId, ?string $role = null): array
    {
        if ($this->driver === 'stateless') {
            return [];
        }

        if ($role !== null) {
            $role = strtolower(trim($role));
        }

        $sessions = [];

        if ($this->driver === 'redis') {
            // Find all matching keys
            $pattern = $role !== null ? "apipro:session:{$userId}:{$role}:*" : "apipro:session:{$userId}:*:*";
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
            if ($role !== null) {
                $escRole = ProSql::Escape($role);
                $query = "SELECT user_id, user_role, device_id, device_name, ip_address, user_agent, last_active, expires_at, token 
                          FROM active_sessions 
                          WHERE user_id = $escUserId AND user_role = '$escRole' AND expires_at > NOW()";
            } else {
                $query = "SELECT user_id, user_role, device_id, device_name, ip_address, user_agent, last_active, expires_at, token 
                          FROM active_sessions 
                          WHERE user_id = $escUserId AND expires_at > NOW()";
            }
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
     * Delete all sessions for a user EXCEPT a specific device ID (optionally scoped by role)
     */
    public function deleteByUserAndExcludeDevice($userId, string $excludeDeviceId, ?string $role = null): void
    {
        if ($this->driver === 'stateless') {
            return;
        }

        if ($role !== null) {
            $role = strtolower(trim($role));
        }

        if ($this->driver === 'redis') {
            $pattern = $role !== null ? "apipro:session:{$userId}:{$role}:*" : "apipro:session:{$userId}:*:*";
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
            if ($role !== null) {
                $escRole = ProSql::Escape($role);
                $query = "DELETE FROM active_sessions WHERE user_id = $escUserId AND user_role = '$escRole' AND device_id != '$escExcludeDeviceId'";
            } else {
                $query = "DELETE FROM active_sessions WHERE user_id = $escUserId AND device_id != '$escExcludeDeviceId'";
            }
            ProSql::Update($query);
        }
    }

    /**
     * Revoke all sessions for a specific user ID (optionally scoped by role)
     */
    public function deleteAllByUser($userId, ?string $role = null): void
    {
        if ($this->driver === 'stateless') {
            return;
        }

        if ($role !== null) {
            $role = strtolower(trim($role));
        }

        if ($this->driver === 'redis') {
            $pattern = $role !== null ? "apipro:session:{$userId}:{$role}:*" : "apipro:session:{$userId}:*:*";
            $keys = ProRedis::keys($pattern);
            if (!empty($keys) && is_array($keys)) {
                foreach ($keys as $key) {
                    ProRedis::del($key);
                }
            }
        } elseif ($this->driver === 'database') {
            $escUserId = (int)$userId;
            if ($role !== null) {
                $escRole = ProSql::Escape($role);
                $query = "DELETE FROM active_sessions WHERE user_id = $escUserId AND user_role = '$escRole'";
            } else {
                $query = "DELETE FROM active_sessions WHERE user_id = $escUserId";
            }
            ProSql::Update($query);
        }
    }
}


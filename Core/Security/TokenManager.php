<?php

class TokenManager
{
    private string $driver;
    private TokenRepository $repo;

    public function __construct(?string $driver = null)
    {
        if ($driver === null) {
            $driver = defined('TOKEN_DRIVER') ? TOKEN_DRIVER : 'stateless';
        }
        $this->driver = strtolower($driver);
        $this->repo = new TokenRepository($this->driver);
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getRepository(): TokenRepository
    {
        return $this->repo;
    }

    /**
     * Harvest client device characteristics from request headers, payload, or agent fallback
     */
    public function harvestDeviceDetails(?string $deviceId = null, ?string $deviceName = null): array
    {
        // 1. Resolve Device ID
        if (empty($deviceId)) {
            if (isset($_SERVER['HTTP_X_DEVICE_ID'])) {
                $deviceId = $_SERVER['HTTP_X_DEVICE_ID'];
            } elseif (isset($_SERVER['HTTP_DEVICE_ID'])) {
                $deviceId = $_SERVER['HTTP_DEVICE_ID'];
            } else {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown_device';
                $deviceId = md5($userAgent);
            }
        }

        // 2. Resolve Device Name
        if (empty($deviceName)) {
            if (isset($_SERVER['HTTP_X_DEVICE_NAME'])) {
                $deviceName = $_SERVER['HTTP_X_DEVICE_NAME'];
            } elseif (isset($_SERVER['HTTP_DEVICE_NAME'])) {
                $deviceName = $_SERVER['HTTP_DEVICE_NAME'];
            } else {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown_device';
                $deviceName = $this->parseFriendlyDeviceName($userAgent);
            }
        }

        // 3. Resolve IP and User-Agent
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown User Agent';

        return [
            'device_id' => trim($deviceId),
            'device_name' => trim($deviceName),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ];
    }

    /**
     * Enforce maximum device session limit rules
     */
    public function enforceDeviceLimits($userId, string $newDeviceId): void
    {
        if ($this->driver === 'stateless') {
            return;
        }

        $allowMultiple = $this->isMultipleDeviceLoginAllowed();

        if (!$allowMultiple) {
            // Kick out all other devices completely
            $this->repo->deleteAllByUser($userId);
            return;
        }

        // Fetch current active sessions
        $sessions = $this->repo->getByUser($userId);
        $max = $this->getMaxDevices();

        // Count does not include current device if it's already active (refreshing/re-authenticating)
        $existingDeviceIds = array_column($sessions, 'device_id');
        
        if (!in_array($newDeviceId, $existingDeviceIds)) {
            if (count($sessions) >= $max) {
                // Sort by last active ascending (oldest first)
                usort($sessions, function ($a, $b) {
                    return strcmp($a['last_active'], $b['last_active']);
                });

                // Delete oldest sessions to fit under maximum
                $toDeleteCount = count($sessions) - $max + 1;
                for ($i = 0; $i < $toDeleteCount; $i++) {
                    if (isset($sessions[$i]['device_id'])) {
                        $this->repo->delete($userId, $sessions[$i]['device_id']);
                    }
                }
            }
        }
    }

    public function isMultipleDeviceLoginAllowed(): bool
    {
        return defined('TOKEN_MULTIPLE_DEVICE_LOGIN') ? TOKEN_MULTIPLE_DEVICE_LOGIN : true;
    }

    public function getMaxDevices(): int
    {
        return defined('TOKEN_MAX_DEVICES') ? (int)TOKEN_MAX_DEVICES : 5;
    }

    public function isConcurrentAllowed(): bool
    {
        return defined('TOKEN_ALLOW_CONCURRENT') ? TOKEN_ALLOW_CONCURRENT : true;
    }

    /**
     * Make user agent strings beautiful and readable
     */
    private function parseFriendlyDeviceName(string $userAgent): string
    {
        if (stripos($userAgent, 'android') !== false) {
            return 'Android Device';
        }
        if (stripos($userAgent, 'iphone') !== false || stripos($userAgent, 'ipad') !== false) {
            return 'iOS Device';
        }
        if (stripos($userAgent, 'windows') !== false) {
            return 'Windows Desktop';
        }
        if (stripos($userAgent, 'macintosh') !== false) {
            return 'Mac Desktop';
        }
        if (stripos($userAgent, 'linux') !== false) {
            return 'Linux Desktop';
        }
        if (stripos($userAgent, 'curl') !== false) {
            return 'Curl CLI Engine';
        }
        if (stripos($userAgent, 'postman') !== false) {
            return 'Postman Client';
        }
        return 'Web Browser';
    }
}

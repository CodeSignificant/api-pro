<?php

class Token
{
    private static ?TokenManager $manager = null;

    public static function setManager(TokenManager $manager): void
    {
        self::$manager = $manager;
    }

    private static function getManager(): TokenManager
    {
        if (self::$manager === null) {
            self::$manager = new TokenManager();
        }
        return self::$manager;
    }

    // ======================================================================
    // Create JWT-like encrypted token with random IV and state tracking
    // ======================================================================
    public static function Generate($id, $load = [], $role = "user")
    {
        $data = [];

        if (!empty($load) && is_array($load)) {
            $data = array_merge($data, $load);
        }

        $now = time();
        $lifetime = defined('SESSION_TIME') ? SESSION_TIME : 1800;
        $expiresAt = $now + $lifetime;

        // Auto-harvest deviceId and deviceName from client request
        $harvested = self::getManager()->harvestDeviceDetails();
        $deviceId = $harvested['device_id'];
        $deviceName = $harvested['device_name'];
        $ipAddress = $harvested['ip_address'];
        $userAgent = $harvested['user_agent'];

        $data['tg']  = date('Y-m-d H:i:s', $now);                     // generated time
        $data['te']  = date('Y-m-d H:i:s', $expiresAt);               // expiry
        $data['id']  = $id;
        $data['r']   = $role;
        $data['v']   = defined('VERSION') ? VERSION : '1.0.0';
        $data['did'] = $deviceId;

        // Enforce concurrency rules and device limits before saving
        self::getManager()->enforceDeviceLimits($id, $deviceId);

        // Persist session state in repository driver (Redis or DB)
        self::getManager()->getRepository()->save($id, $deviceId, $deviceName, $ipAddress, $userAgent, $expiresAt);

        return self::_encrypt(json_encode($data));
    }

    // ======================================================================
    // Basic Getters
    // ======================================================================
    public static function GetId()
    {
        return self::Get()['id'];
    }

    public static function GetRole()
    {
        return self::Get()['r'];
    }

    // ======================================================================
    public static function Get()
    {
        $data = self::TryGet();

        if ($data === [] || $data === 0) {
            $err = new DataFailed("Unauthorized: Invalid token.", 401);
            $err->response();
        }

        if ($data === 2) { // expired
            $err = new DataFailed("Token expired.", 419);
            $err->response();
        }

        return $data;
    }

    // ======================================================================
    // Soft Get (Returns [] instead of failing)
    // ======================================================================
    public static function TryGet()
    {
        try {
            $token = self::_verifyToken();

            if ($token === 0 || $token === 2 || empty($token)) {
                return [];
            }

            return $token;
        } catch (Exception $e) {
            return [];
        }
    }

    // ======================================================================
    // Refresh decoded token (sliding expiration)
    // ======================================================================
    public static function Refresh($decodedToken, int $session = 1800)
    {
        if (!is_array($decodedToken) || empty($decodedToken)) {
            return 0;
        }

        $required = ['tg', 'te', 'id', 'v', 'did'];
        foreach ($required as $key) {
            if (!isset($decodedToken[$key])) {
                return 0;
            }
        }

        $tg = strtotime($decodedToken['tg']);
        $now = time();

        // Token cannot exceed 3 months lifetime
        if (strtotime("+3 months", $tg) < $now) {
            $err = new DataFailed("Token lifetime limit exceeded.", 401);
            $err->response();
        }

        // Extend expiry
        $decodedToken['te'] = date('Y-m-d H:i:s', $now + $session);

        // Update state in repository
        $harvested = self::getManager()->harvestDeviceDetails();
        self::getManager()->getRepository()->save(
            $decodedToken['id'],
            $decodedToken['did'],
            $harvested['device_name'],
            $harvested['ip_address'],
            $harvested['user_agent'],
            $now + $session
        );

        return self::_encrypt(json_encode($decodedToken));
    }

    // ======================================================================
    // Validate raw token BEFORE refreshing
    // Returns decoded payload
    // ======================================================================
    public static function CanRefresh()
    {
        $rawToken = self::RawToken();

        // Decode
        $decoded = json_decode(self::_decrypt($rawToken), true);
        if (!$decoded || !is_array($decoded)) {
            return 0;
        }

        $required = ['tg', 'te', 'id', 'v', 'did'];
        foreach ($required as $key) {
            if (!isset($decoded[$key])) {
                return 0;
            }
        }

        $tg = strtotime($decoded['tg']);
        $now = time();

        if (strtotime("+3 months", $tg) < $now) {
            $err = new DataFailed("Token lifetime limit exceeded.", 401);
            $err->response();
        }

        return $decoded;
    }

    public static function RawToken()
    {
        if (
            !isset($_SERVER['HTTP_AUTHORIZATION']) ||
            !preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)
        ) {
            $err = new DataFailed("Unauthorized: Bearer token is missing.", 401);
            $err->response();
        }

        return trim($matches[1]);
    }

    // ======================================================================
    // Revocation Interface Methods
    // ======================================================================
    public static function RevokeDevice($userId, string $deviceId): void
    {
        self::getManager()->getRepository()->delete($userId, $deviceId);
    }

    public static function GetDevices($userId): array
    {
        return self::getManager()->getRepository()->getByUser($userId);
    }

    // ======================================================================
    // Internal Token Validator
    // ======================================================================
    private static function _verifyToken()
    {
        if (
            !isset($_SERVER['HTTP_AUTHORIZATION']) ||
            !preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)
        ) {
            return 0;
        }

        $token = trim($matches[1]);

        // Decode & Decrypt
        $decrypted = self::_decrypt($token);
        if ($decrypted === false) {
            return 0;
        }

        $decoded = json_decode($decrypted, true);
        if (!$decoded || !is_array($decoded)) {
            return 0;
        }

        // Required fields
        $required = ['tg', 'te', 'id', 'v'];
        foreach ($required as $key) {
            if (!isset($decoded[$key])) {
                return 0;
            }
        }

        $tg  = strtotime($decoded['tg']);
        $te  = strtotime($decoded['te']);
        $now = time();

        // Max lifetime check
        if (strtotime("+3 months", $tg) < $now) {
            return 0;
        }

        // Expired token
        if ($te < $now) {
            return 2;
        }

        // Version mismatch
        $version = defined('VERSION') ? VERSION : '1.0.0';
        if ($decoded['v'] !== $version) {
            return 0;
        }

        // Concurrency / Device Binding Verification
        if (isset($decoded['did'])) {
            $driver = self::getManager()->getDriver();

            if ($driver !== 'stateless') {
                // If this session is not in state repository, it has been revoked
                $sessions = self::getManager()->getRepository()->getByUser($decoded['id']);
                $activeDeviceIds = array_column($sessions, 'device_id');
                if (!in_array($decoded['did'], $activeDeviceIds)) {
                    return 0; // Revoked token!
                }
            }

            // Check if explicit custom headers are set
            $hasExplicitHeaders = isset($_SERVER['HTTP_X_DEVICE_ID']) || isset($_SERVER['HTTP_DEVICE_ID']);

            if ($hasExplicitHeaders || $driver === 'stateless') {
                // Device binding check
                $harvested = self::getManager()->harvestDeviceDetails();
                $currentDid = $harvested['device_id'];
                if ($decoded['did'] !== $currentDid) {
                    return 0; // Device ID mismatch (anti-hijack)
                }
            }
            // If NO explicit device headers are provided and we are in stateful mode, 
            // the state repository check above is sufficient to authorize testing clients (e.g. curl/Postman).
        }

        return $decoded;
    }

    // ======================================================================
    // Upgraded Cryptography: Random IV + SHA256 HMAC Signatures
    // ======================================================================
    private static function _encrypt($plaintext)
    {
        $password = defined('SERVER_ENC') ? SERVER_ENC : 'apipro@4ss';
        $method   = 'aes-256-cbc';
        $key      = substr(hash('sha256', $password, true), 0, 32);

        // Generate non-deterministic cryptographically secure random IV (16 bytes for AES-256-CBC)
        $iv = openssl_random_pseudo_bytes(16);

        // Encrypt
        $ciphertext = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv);

        // Calculate HMAC signature to ensure integrity and prevent timing attacks
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $key);

        $envelope = [
            'iv'   => base64_encode($iv),
            'ct'   => base64_encode($ciphertext),
            'hmac' => $hmac
        ];

        return base64_encode(json_encode($envelope));
    }

    private static function _decrypt($encrypted)
    {
        try {
            $password = defined('SERVER_ENC') ? SERVER_ENC : 'apipro@4ss';
            $method   = 'aes-256-cbc';
            $key      = substr(hash('sha256', $password, true), 0, 32);

            $envelope = json_decode(base64_decode($encrypted), true);
            if (!$envelope || !isset($envelope['iv'], $envelope['ct'], $envelope['hmac'])) {
                return false;
            }

            $iv         = base64_decode($envelope['iv']);
            $ciphertext = base64_decode($envelope['ct']);
            $hmac       = $envelope['hmac'];

            // Timing-safe verification of HMAC signature
            $expectedHmac = hash_hmac('sha256', $iv . $ciphertext, $key);
            if (!hash_equals($expectedHmac, $hmac)) {
                return false;
            }

            return openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);
        } catch (Exception $e) {
            return false;
        }
    }
}

<?php

class Token{

    // ======================================================================
    // Create JWT-like encrypted token
    // ======================================================================
    public static function Generate($id, $load = [], $role = "user") {

        $data = [];

        if (!empty($load) && is_array($load)) {
            $data = array_merge($data, $load);
        }

        $now = time();

        $data['tg'] = date('Y-m-d H:i:s', $now);                     // generated time
        $data['te'] = date('Y-m-d H:i:s', $now + SESSION_TIME);      // expiry
        $data['id'] = $id;
        $data['r']  = $role;
        $data['v']  = VERSION;

        return self::_encrypt(json_encode($data));
    }


    // ======================================================================
    // Basic Getters
    // ======================================================================
    public static function GetId() {
        return self::Get()['id'];
    }

    public static function GetRole() {
        return self::Get()['r'];
    }


    // ======================================================================
    // Get token strictly (throws 401 or 419)
    // ======================================================================
    public static function Get() {
        $data = self::TryGet();

        if ($data === [] || $data === 0) {
            header('HTTP/1.0 401 Unauthorized'); exit();
        }

        if ($data === 2) {           // expired
            http_response_code(419); exit();
        }

        return $data;
    }


    // ======================================================================
    // Soft Get (Returns [] instead of failing)
    // ======================================================================
    public static function TryGet() {
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

        $tg  = strtotime($decodedToken['tg']);
        $now = time();

        // Token cannot exceed 3 months lifetime
        if (strtotime("+3 months", $tg) < $now) {
            header('HTTP/1.0 401 Unauthorized'); exit();
        }

        // Extend expiry
        $decodedToken['te'] = date('Y-m-d H:i:s', $now + $session);

        return self::_encrypt(json_encode($decodedToken));
    }


    // ======================================================================
    // Validate raw token BEFORE refreshing
    // Returns decoded payload + rawToken
    // ======================================================================
    public static function CanRefresh()
    {
        // Extract raw Authorization token
        if (
            !isset($_SERVER['HTTP_AUTHORIZATION']) ||
            !preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)
        ) {
            return 0;
        }

        $rawToken = trim($matches[1]);

        // Decode
        $decoded = json_decode(self::_decrypt($rawToken), true);
        if (!$decoded || !is_array($decoded)) {
            return 0;
        }

        // Required fields
        $required = ['tg', 'te', 'id', 'v', 'did'];
        foreach ($required as $key) {
            if (!isset($decoded[$key])) {
                return 0;
            }
        }

        // Check if older than 3 months
        $tg  = strtotime($decoded['tg']);
        $now = time();

        if (strtotime("+3 months", $tg) < $now) {
            header('HTTP/1.0 401 Unauthorized'); exit();
        }


        return $decoded;
    }
    
    public static function RawToken(){
        
        if (
            !isset($_SERVER['HTTP_AUTHORIZATION']) ||
            !preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)
        ) {
            header('HTTP/1.0 401 Unauthorized'); exit();
        }

        return trim($matches[1]);
    }


    // ======================================================================
    // Internal Token Validator
    // ======================================================================
    private static function _verifyToken() {

        // Extract raw bearer token
        if (
            !isset($_SERVER['HTTP_AUTHORIZATION']) ||
            !preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)
        ) {
            return 0;
        }

        $token = trim($matches[1]);

        // Decode
        $decoded = json_decode(self::_decrypt($token), true);
        if (!$decoded || !is_array($decoded)) return 0;

        // Required fields
        $required = ['tg', 'te', 'id', 'v'];
        foreach ($required as $key) {
            if (!isset($decoded[$key])) return 0;
        }

        $tg  = strtotime($decoded['tg']);
        $te  = strtotime($decoded['te']);
        $now = time();

        // Max lifetime check
        if (strtotime("+3 months", $tg) < $now) return 0;

        // Expired token
        if ($te < $now) return 2;

        // Version mismatch
        if ($decoded['v'] !== VERSION) return 0;

        return $decoded;
    }


    // ======================================================================
    // Encryption / Decryption
    // ======================================================================
    private static function _encrypt($plaintext) {

        $password = SERVER_ENC;
        $method   = 'aes-256-cbc';

        $password = substr(hash('sha256', $password, true), 0, 32);

        $iv = str_repeat(chr(0x0), 16);

        return base64_encode(openssl_encrypt($plaintext, $method, $password, OPENSSL_RAW_DATA, $iv));
    }

    public static function _decrypt($encrypted) {

        $password = SERVER_ENC;
        $method   = 'aes-256-cbc';

        $password = substr(hash('sha256', $password, true), 0, 32);

        $iv = str_repeat(chr(0x0), 16);

        return openssl_decrypt(base64_decode($encrypted), $method, $password, OPENSSL_RAW_DATA, $iv);
    }
}

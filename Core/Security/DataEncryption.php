<?php

class DataEncryption
{
    /**
     * Encrypt data using DATA_ENC key or an optional custom key.
     * Supports arrays, objects, strings, numbers, booleans.
     * 
     * Conditions:
     * 1. If $customKey is null:
     *    - If DATA_ENC is defined and has a non-empty value, encrypt.
     *    - Otherwise, return original data as-is.
     * 2. If $customKey is "" (empty string):
     *    - Return original data as-is (bypasses encryption).
     * 3. If $customKey is a non-empty string:
     *    - Encrypt using the custom key value directly.
     */
    public static function encrypt($data, ?string $customKey = null)
    {
        if ($data === null) {
            return null;
        }

        // Determine key
        $key = null;
        
        // Condition 2: customKey is empty string -> bypass
        if ($customKey === '') {
            return $data;
        }

        // Condition 3: customKey is a non-empty string
        if ($customKey !== null && $customKey !== '') {
            $key = $customKey;
        }

        // Condition 1: customKey is null
        if ($customKey === null) {
            if (!defined('DATA_ENC')) {
                return $data;
            }
            if (empty(DATA_ENC)) {
                return $data;
            }
            $key = DATA_ENC;
        }

        if (empty($key)) {
            return $data;
        }

        // Prepare key hash to ensure it is 32 bytes (256 bits) for aes-256-cbc
        $hashKey = hash('sha256', $key, true);

        // Serialize data
        $serialized = json_encode($data);

        // Encrypt
        $ivlen = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext = openssl_encrypt($serialized, 'aes-256-cbc', $hashKey, OPENSSL_RAW_DATA, $iv);

        // HMAC signature
        $mac = hash_hmac('sha256', $ciphertext, $hashKey);

        // Package as base64 JSON
        $package = [
            'iv' => base64_encode($iv),
            'ct' => base64_encode($ciphertext),
            'mac' => $mac
        ];

        return base64_encode(json_encode($package));
    }

    /**
     * Decrypt encrypted data using DATA_ENC key or an optional custom key.
     */
    public static function decrypt($encrypted, ?string $customKey = null): ?string
    {
        if ($encrypted === null || !is_string($encrypted)) {
            return null;
        }

        // Determine key
        $key = null;

        // Condition 2: customKey is empty string -> bypass (meaning it was not encrypted)
        if ($customKey === '') {
            return $encrypted;
        }

        // Condition 3: customKey is a non-empty string
        if ($customKey !== null && $customKey !== '') {
            $key = $customKey;
        }

        // Condition 1: customKey is null
        if ($customKey === null) {
            if (!defined('DATA_ENC')) {
                return null;
            }
            if (empty(DATA_ENC)) {
                return null;
            }
            $key = DATA_ENC;
        }

        if (empty($key)) {
            return null;
        }

        $decodedPackageJson = base64_decode($encrypted, true);
        if ($decodedPackageJson === false) {
            return null;
        }

        $package = json_decode($decodedPackageJson, true);
        if (!is_array($package)) {
            return null;
        }

        if (!isset($package['iv']) || !isset($package['ct']) || !isset($package['mac'])) {
            return null;
        }

        $iv = base64_decode($package['iv'], true);
        $ciphertext = base64_decode($package['ct'], true);
        $mac = $package['mac'];

        if ($iv === false || $ciphertext === false) {
            return null;
        }

        $hashKey = hash('sha256', $key, true);

        // Validate HMAC signature using timing-safe comparison
        $calculatedMac = hash_hmac('sha256', $ciphertext, $hashKey);
        if (!hash_equals($calculatedMac, $mac)) {
            return null;
        }

        // Decrypt
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $hashKey, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            return null;
        }

        return $decrypted;
    }
}

<?php

class Session
{
    // ======================================================================
    // Clean Extraction of Raw Token (Optional / Try)
    // ======================================================================
    public static function TryToken(): ?string
    {
        if (!self::HasToken()) {
            return null;
        }

        if (
            isset($_SERVER['HTTP_AUTHORIZATION']) &&
            preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)
        ) {
            return trim($matches[1]);
        }

        return null;
    }

    // ======================================================================
    // Soft TryGet (Returns array payload if valid, otherwise null)
    // ======================================================================
    public static function TryGet(): ?array
    {
        $decoded = Token::TryGet();
        return empty($decoded) ? null : $decoded;
    }

    // ======================================================================
    // Has Valid Token check (No Exits)
    // ======================================================================
    public static function HasToken(): bool
    {
        $decoded = Token::TryGet();
        return !empty($decoded);
    }

    // ======================================================================
    // Mandatory Token Retrieval (Halts with 401 on missing/invalid)
    // ======================================================================
    public static function GetToken(): string
    {
        self::Get(); // enforce validity
        
        if (
            isset($_SERVER['HTTP_AUTHORIZATION']) &&
            preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)
        ) {
            return trim($matches[1]);
        }

        $err = new DataFailed("Unauthorized: Bearer token is missing.", 401);
        $err->response();
    }

    // ======================================================================
    // Mandatory Decoded Payload Get with Optional Role Verification
    // ======================================================================
    public static function Get(?string $expectedRole = null): array
    {
        $decoded = Token::Get(); // Enforces 401 or 419 natively
        
        if ($expectedRole !== null) {
            $userRole = $decoded['r'] ?? $decoded['role'] ?? null;
            if ($userRole === null || strcasecmp($userRole, $expectedRole) !== 0) {
                $err = new DataFailed("Forbidden: Insufficient privileges.", 403);
                $err->response();
            }
        }

        return $decoded;
    }

    // ======================================================================
    // Get Current Logged-in User ID (Mandatory)
    // ======================================================================
    public static function GetId()
    {
        $decoded = self::Get();
        return $decoded['id'];
    }

    // ======================================================================
    // Proxy Token actions so project lib layer only interacts with Session
    // ======================================================================
    public static function Create($userId, array $payload = [], ?string $role = null): string
    {
        return Token::Generate($userId, $payload, $role);
    }

    public static function Generate($userId, array $payload = [], ?string $role = null): string
    {
        return Token::Generate($userId, $payload, $role);
    }

    public static function RevokeDevice($userId, string $deviceId): void
    {
        Token::RevokeDevice($userId, $deviceId);
    }

    public static function GetDevices($userId): array
    {
        return Token::GetDevices($userId);
    }
}

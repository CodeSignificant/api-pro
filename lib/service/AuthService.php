<?php

require_once __DIR__ . '/../repo/AuthRepository.php';

class AuthService
{
    private AuthRepository $repo;

    public function __construct()
    {
        $this->repo = new AuthRepository();
    }

    /**
     * Authenticate and generate a new token
     */
    public function login(string $email, string $password): DataResponse
    {
        $user = $this->repo->authenticate($email, $password);
        if (!$user) {
            return new DataFailed('Invalid credentials', 401);
        }

        // Generate token and track multi-device persistence (handled natively by Core/Security/Token)
        $token = Session::Create($user['id'], ['email' => $user['email']], $user['role']);

        return new DataSuccess('Login successful', [
            'token' => $token,
            'user' => $user
        ]);
    }

    /**
     * Log out active device session
     */
    public function logout($userId, ?string $deviceId, ?string $role = null): DataResponse
    {
        if ($deviceId) {
            Session::RevokeDevice($userId, $deviceId, $role);
        }

        return new DataSuccess('Logged out successfully');
    }

    /**
     * List all active logged-in devices
     */
    public function getDevices($userId, ?string $role = null): DataResponse
    {
        $devices = Session::GetDevices($userId, $role);
        return new DataSuccess('Active devices fetched successfully', $devices);
    }

    /**
     * Revoke access for a specific device ID
     */
    public function revokeDevice($userId, string $deviceId, ?string $role = null): DataResponse
    {
        Session::RevokeDevice($userId, $deviceId, $role);
        return new DataSuccess('Device revoked successfully');
    }
}

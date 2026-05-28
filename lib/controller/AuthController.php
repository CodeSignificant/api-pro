<?php

require_once __DIR__ . '/../service/AuthService.php';

#[Controller('/v1/auth')]
class AuthController
{
    private AuthService $service;

    public function __construct()
    {
        $this->service = new AuthService();
    }

    #[Post('/login')]
    public function login()
    {
        $body = Node::body(['email', 'password']);
        return $this->service->login($body['email'], $body['password']);
    }

    #[Post('/logout')]
    public function logout()
    {
        $session = Session::Get();
        $userId = $session['id'];
        $deviceId = $session['did'] ?? null;
        return $this->service->logout($userId, $deviceId);
    }

    #[Get('/devices')]
    public function getDevices()
    {
        $session = Session::Get();
        $userId = $session['id'];
        return $this->service->getDevices($userId);
    }

    #[Post('/devices/revoke')]
    public function revokeDevice()
    {
        $session = Session::Get();
        $userId = $session['id'];
        $body = Node::body(['deviceId']);
        return $this->service->revokeDevice($userId, $body['deviceId']);
    }
}

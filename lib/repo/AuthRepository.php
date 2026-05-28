<?php

class AuthRepository extends Repository
{
    public function __construct()
    {
        parent::__construct([
            'users' => [
                'lock' => true,                     
                'schema' => "CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    role VARCHAR(50) DEFAULT 'user'
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
            ]
        ]);
    }
    /**
     * Authenticate user with credentials.
     * Mocked for clean database-less running, matching config.php parameters.
     */
    public function authenticate(string $email, string $password): ?array
    {
        // Trim and sanitize inputs
        $email = trim(strtolower($email));
        $password = trim($password);

        // Simple mock authentication for testing
        if ($email === 'admin@example.com' && $password === 'password') {
            return [
                'id' => 1,
                'email' => 'admin@example.com',
                'role' => 'user'
            ];
        }

        return null;
    }
}

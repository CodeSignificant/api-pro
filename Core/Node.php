<?php

$allowedOrigins = [
    "http://localhost:3000",
    "http://127.0.0.1:3000",
    "https://crm.4ss.in",
    "https://crm.doland.in"
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
} else {
    header("Access-Control-Allow-Origin: *"); // fallback
}

// CORS headers
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Preflight response
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}



class Node {
    /**
     * Get query parameters (?key=value)
     */
    public static function params(array $requiredKeys = []): array {
        $data = $_GET ?? [];
        if (empty($data)) $data = $_POST; // fallback for POST
        if (empty($requiredKeys)) return $data;

        // Validate required keys
        foreach ($requiredKeys as $key) {
            if (!isset($data[$key]) || $data[$key] === '' || $data[$key] === null) {
                http_response_code(400);
                // echo json_encode(['error' => "Missing or invalid value for '$key'"]);
                exit();
            }
        }

        return $data;
    }
    /**
     * Get JSON body payload from POST, PATCH, DELETE, etc.
     */
    public static function body(array $requiredKeys = []): array {
        // Read raw input (needed for PATCH, DELETE)
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
    
        // Fallback for form-data / normal POST
        if (!is_array($data) || empty($data)) {
            $data = $_POST;
        }
    
        // Validate required keys
        if (!empty($requiredKeys)) {
            foreach ($requiredKeys as $key) {
                if (!isset($data[$key]) || $data[$key] === '' || $data[$key] === null) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing or invalid value: '$key'"]);
                    exit;
                }
            }
        }
    
        return $data;
    }



    
    /**
     * Get uploaded files (supports single & multiple)
     * Example:
     * $files = Node::getFiles();
     * $avatar = Node::getFiles(['avatar']);
     */
    public static function files(array $requiredKeys = []): array {
        if (empty($_FILES)) return [];

        $files = [];

        foreach ($_FILES as $key => $file) {
            if (is_array($file['name'])) {
                // Handle multiple uploads with same key
                $count = count($file['name']);
                $files[$key] = [];
                for ($i = 0; $i < $count; $i++) {
                    $files[$key][] = [
                        'name' => $file['name'][$i],
                        'type' => $file['type'][$i],
                        'tmp_name' => $file['tmp_name'][$i],
                        'error' => $file['error'][$i],
                        'size' => $file['size'][$i],
                    ];
                }
            } else {
                // Single file upload
                $files[$key] = [
                    'name' => $file['name'],
                    'type' => $file['type'],
                    'tmp_name' => $file['tmp_name'],
                    'error' => $file['error'],
                    'size' => $file['size'],
                ];
            }
        }

        // Validate required file keys
        if (!empty($requiredKeys)) {
            foreach ($requiredKeys as $key) {
                if (!isset($files[$key]) || empty($files[$key]['name'])) {
                    http_response_code(400);
                    exit(json_encode(['error' => "Missing required file: '$key'"]));
                }
            }
        }

        return $files;
    }
    
    
    
    
    
}

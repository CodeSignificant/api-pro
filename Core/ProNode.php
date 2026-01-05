<?php

class ProNode {
    public static array $routes = [];

    // ===============================
    // Utility Print Methods
    // ===============================
    public static function print($data = "ApiPro: Hello\n") {
        echo ($data);
    }

    public static function println($data = "ApiPro: Hello") {
        echo ($data . "\n");
    }

    // ===============================
    // Route Registration
    // ===============================
    public static function Service(string $basePath, $controller) {
        return new class($basePath, $controller) {
            private string $basePath;
            private $controller;

            public function __construct($basePath, $controller) {
                $this->basePath = rtrim($basePath, '/');
                $this->controller = $controller;
            }

            public function get(string $path, string $method) {
                ProNode::$routes['GET'][$this->basePath . $path] = [$this->controller, $method];
            }

            public function post(string $path, string $method) {
                ProNode::$routes['POST'][$this->basePath . $path] = [$this->controller, $method];
            }

            public function put(string $path, string $method) {
                ProNode::$routes['PUT'][$this->basePath . $path] = [$this->controller, $method];
            }

            public function patch(string $path, string $method) {
                ProNode::$routes['PATCH'][$this->basePath . $path] = [$this->controller, $method];
            }

            public function delete(string $path, string $method) {
                ProNode::$routes['DELETE'][$this->basePath . $path] = [$this->controller, $method];
            }

            public function options(string $path, string $method) {
                ProNode::$routes['OPTIONS'][$this->basePath . $path] = [$this->controller, $method];
            }

            public function head(string $path, string $method) {
                ProNode::$routes['HEAD'][$this->basePath . $path] = [$this->controller, $method];
            }
        };
    }

    // ===============================
    // Request Listener
    // ===============================
    public static function start() {
        // --- 1️⃣ Get raw HTTP method
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // --- 2️⃣ Allow method override for shared hosting (Hostinger, etc.)
        if ($method === 'POST') {
            if (isset($_REQUEST['_method'])) {
                $method = strtoupper($_REQUEST['_method']);
            } elseif (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
                $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
            }
        }

        // --- 3️⃣ Normalize path
        $path = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
        $path = preg_replace('/\/+/', '/', $path);

        // --- 4️⃣ Match route
        $allMethods = self::$routes;
        $matchedRoute = null;
        $allowedMethods = [];

        foreach ($allMethods as $m => $routes) {
            if (isset($routes[$path])) {
                $allowedMethods[] = $m;
                if ($m === $method) {
                    $matchedRoute = $routes[$path];
                }
            }
        }

        // --- 5️⃣ Route not found at all
        if (empty($allowedMethods)) {
            self::respond(new DataFailed("Route not found: $method $path", 404));
            return;
        }

        // --- 6️⃣ Path found but wrong method
        if (!$matchedRoute) {
            header('Allow: ' . implode(', ', $allowedMethods));
            self::respond(new DataFailed("Method Not Allowed for $path", 405));
            return;
        }

        // --- 7️⃣ Execute route
        [$controller, $fn] = $matchedRoute;

        if (!method_exists($controller, $fn)) {
            self::respond(new DataFailed("Internal Server Error: Method '$fn' not found in controller", 500));
            return;
        }

        $node = new Node();
        $response = $controller->$fn($node);
        self::respond($response);
    }

    // ===============================
    // Response Output
    // ===============================
    private static function respond($response) {
        if ($response instanceof DataResponse) {
            $response->response();
        } else {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Invalid or unsupported response type'
            ], JSON_PRETTY_PRINT);
            exit();
        }
    }
}

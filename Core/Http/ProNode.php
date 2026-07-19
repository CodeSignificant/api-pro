<?php

class ProNode {
    public static array $routes = [];

    // ===============================
    // Auto-Discover Controllers via Attributes
    // ===============================
    public static function scan(string $directory): void
    {
        if (!is_dir($directory)) return;

        foreach (glob($directory . '/*.php') as $file) {
            require_once $file;

            $className = basename($file, '.php');
            if (!class_exists($className)) continue;

            $refClass = new ReflectionClass($className);
            $controllerAttrs = $refClass->getAttributes(Controller::class);
            if (empty($controllerAttrs)) continue;

            $basePath = rtrim($controllerAttrs[0]->newInstance()->basePath, '/');
            $instance = new $className();

            foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ([Get::class, Post::class, Put::class, Patch::class, Delete::class] as $httpAttr) {
                    $attrs = $method->getAttributes($httpAttr);
                    if (empty($attrs)) continue;

                    $httpMethod = strtoupper(basename(str_replace('\\', '/', $httpAttr)));
                    $path = $attrs[0]->newInstance()->path;
                    $fullPath = $basePath . '/' . ltrim($path, '/');

                    self::$routes[$httpMethod][$fullPath] = [$instance, $method->getName()];
                }
            }
        }
    }


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
        // Enforce controller location constraint (Compile/Startup Error)
        if (class_exists('Controller')) {
            $expectedDir = realpath(getcwd() . '/lib/controller');
            foreach (get_declared_classes() as $className) {
                $refClass = new ReflectionClass($className);
                if ($refClass->isInternal()) continue;
                
                if (!empty($refClass->getAttributes(Controller::class))) {
                    $fileName = $refClass->getFileName();
                    if ($fileName && ($expectedDir === false || strpos(realpath($fileName), $expectedDir) !== 0)) {
                        die("Compile Error: Controller class '$className' must be located inside the 'lib/controller' directory. Found in: '$fileName'.\n");
                    }
                }
            }
        }

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
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // --- Log Incoming Request
        if (defined('LOG_ENABLED') && LOG_ENABLED === true) {
            if (!str_starts_with($path, '/apipro/') && $path !== '/logs.html' && $path !== '/test.html') {
                $timestamp = date('Y-m-d H:i:s');
                $inputData = '';
                if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
                    $rawInput = @file_get_contents('php://input');
                    if (!empty($rawInput)) {
                        $inputData = ' | Body: ' . trim(preg_replace('/\s+/', ' ', $rawInput));
                    }
                }
                error_log("[$timestamp] [INFO] Request: $method $path$inputData");
            }
        }

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
            $response = new DataFailed("Route not found: $method $path", 404);
            self::logResponse($method, $path, $response);
            self::respond($response);
            return;
        }

        // --- 6️⃣ Path found but wrong method
        if (!$matchedRoute) {
            header('Allow: ' . implode(', ', $allowedMethods));
            $response = new DataFailed("Method Not Allowed for $path", 405);
            self::logResponse($method, $path, $response);
            self::respond($response);
            return;
        }

        // --- 7️⃣ Execute route
        [$controller, $fn] = $matchedRoute;

        if (!method_exists($controller, $fn)) {
            $response = new DataFailed("Internal Server Error: Method '$fn' not found in controller", 500);
            self::logResponse($method, $path, $response);
            self::respond($response);
            return;
        }

        // Reflection to inspect first parameter of the controller method
        $refMethod = new ReflectionMethod($controller, $fn);
        $methodParams = $refMethod->getParameters();
        $arg = null;
        if (!empty($methodParams)) {
            $firstParam = $methodParams[0];
            $type = $firstParam->getType();
            if ($type && !$type->isBuiltin() && $type->getName() === 'Node') {
                $arg = new Node();
                if (!headers_sent()) {
                    header("X-ApiPro-Warning: Node class is deprecated. Please migrate to Request class.", false);
                }
            } else {
                $arg = new Request();
            }
        } else {
            $arg = new Request();
        }

        $response = $controller->$fn($arg);
        self::logResponse($method, $path, $response);
        self::respond($response);
    }

    private static function logResponse(string $method, string $path, $response) {
        if (defined('LOG_ENABLED') && LOG_ENABLED === true) {
            if (!str_starts_with($path, '/apipro/') && $path !== '/logs.html' && $path !== '/test.html') {
                $status = ($response instanceof DataResponse) ? $response->getStatusCode() : 500;
                $level = ($status >= 400) ? 'ERROR' : 'INFO';
                $timestamp = date('Y-m-d H:i:s');
                error_log("[$timestamp] [$level] Response: $method $path - Status: $status");
            }
        }
    }

    // ===============================
    // Response Output
    // ===============================
    private static function respond($response) {
        if ($response instanceof DataResponse) {
            $response->response();
        } else {
            $err = new DataFailed('Invalid or unsupported response type', 500);
            $err->response();
        }
    }
}

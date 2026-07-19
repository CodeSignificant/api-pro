<?php

class Request {
    public RequestData $body;
    public RequestData $params;
    public RequestData $files;
    
    private array $comments = [];

    public function __construct() {
        // Parse params (GET)
        $paramsData = $_GET ?? [];
        if (empty($paramsData) && isset($_POST) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $paramsData = $_POST;
        }
        $this->params = new RequestData($paramsData, 'params');

        // Parse body (POST/JSON)
        $raw = file_get_contents('php://input');
        $bodyData = json_decode($raw, true);
        if (!is_array($bodyData) || empty($bodyData)) {
            $bodyData = $_POST ?? [];
        }
        $this->body = new RequestData($bodyData, 'body');

        // Parse files
        $filesData = $this->parseFiles();
        $this->files = new RequestData($filesData, 'files');
    }

    private function parseFiles(): array {
        $files = [];
        if (!empty($_FILES)) {
            foreach ($_FILES as $key => $file) {
                if (is_array($file['name'])) {
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
                    $files[$key] = [
                        'name' => $file['name'],
                        'type' => $file['type'],
                        'tmp_name' => $file['tmp_name'],
                        'error' => $file['error'],
                        'size' => $file['size'],
                    ];
                }
            }
        }
        return $files;
    }

    public function addComment(string $text): void {
        $this->comments[] = $text;
    }

    public function getComments(): array {
        return $this->comments;
    }

    // Direct type-safe lookup delegators (checks body first, then params)
    public function getString(string $key, $default = null): string {
        if ($this->body->has($key)) {
            return $this->body->getString($key, ...array_slice(func_get_args(), 1));
        }
        return $this->params->getString($key, ...array_slice(func_get_args(), 1));
    }

    public function getInt(string $key, $default = null): int {
        if ($this->body->has($key)) {
            return $this->body->getInt($key, ...array_slice(func_get_args(), 1));
        }
        return $this->params->getInt($key, ...array_slice(func_get_args(), 1));
    }

    public function getFloat(string $key, $default = null): float {
        if ($this->body->has($key)) {
            return $this->body->getFloat($key, ...array_slice(func_get_args(), 1));
        }
        return $this->params->getFloat($key, ...array_slice(func_get_args(), 1));
    }

    public function getBool(string $key, $default = null): bool {
        if ($this->body->has($key)) {
            return $this->body->getBool($key, ...array_slice(func_get_args(), 1));
        }
        return $this->params->getBool($key, ...array_slice(func_get_args(), 1));
    }

    public function getArray(string $key, $default = null): array {
        if ($this->body->has($key)) {
            return $this->body->getArray($key, ...array_slice(func_get_args(), 1));
        }
        return $this->params->getArray($key, ...array_slice(func_get_args(), 1));
    }

    public function getObject(string $key, $default = null) {
        if ($this->body->has($key)) {
            return $this->body->getObject($key, ...array_slice(func_get_args(), 1));
        }
        return $this->params->getObject($key, ...array_slice(func_get_args(), 1));
    }

    public function getFile(string $key, $default = null) {
        return $this->files->getFile($key, ...array_slice(func_get_args(), 1));
    }

    public function getFiles(string $key = null, $default = null) {
        return $this->files->getFiles($key, ...array_slice(func_get_args(), 1));
    }
}

class RequestData {
    private array $data;
    private string $source;

    public function __construct(array $data, string $source) {
        $this->data = $data;
        $this->source = $source;
    }

    public function has(string $key): bool {
        return array_key_exists($key, $this->data);
    }

    public function all(): array {
        return $this->data;
    }

    private function validateOrReturn(string $key, bool $defaultExists, $default, $checkFn, $castFn) {
        if (!array_key_exists($key, $this->data) || $this->data[$key] === null || $this->data[$key] === '') {
            if (!$defaultExists) {
                $err = new DataFailed("Missing or invalid value: '$key'", 400);
                $err->response();
            }
            return $default;
        }

        $val = $this->data[$key];
        if ($checkFn !== null && !$checkFn($val)) {
            if (!$defaultExists) {
                $err = new DataFailed("Missing or invalid value: '$key'", 400);
                $err->response();
            }
            return $default;
        }

        return $castFn($val);
    }

    public function getString(string $key, $default = null): string {
        $defaultExists = func_num_args() >= 2;
        return $this->validateOrReturn(
            $key, 
            $defaultExists, 
            $default, 
            null, 
            function($v) { return (string)$v; }
        );
    }

    public function getInt(string $key, $default = null): int {
        $defaultExists = func_num_args() >= 2;
        return $this->validateOrReturn(
            $key, 
            $defaultExists, 
            $default, 
            function($v) { return is_numeric($v); }, 
            function($v) { return (int)$v; }
        );
    }

    public function getFloat(string $key, $default = null): float {
        $defaultExists = func_num_args() >= 2;
        return $this->validateOrReturn(
            $key, 
            $defaultExists, 
            $default, 
            function($v) { return is_numeric($v); }, 
            function($v) { return (float)$v; }
        );
    }

    public function getBool(string $key, $default = null): bool {
        $defaultExists = func_num_args() >= 2;
        return $this->validateOrReturn(
            $key, 
            $defaultExists, 
            $default, 
            null, 
            function($v) {
                if (is_bool($v)) return $v;
                return filter_var($v, FILTER_VALIDATE_BOOLEAN);
            }
        );
    }

    public function getArray(string $key, $default = null): array {
        $defaultExists = func_num_args() >= 2;
        return $this->validateOrReturn(
            $key, 
            $defaultExists, 
            $default, 
            function($v) { return is_array($v); }, 
            function($v) { return $v; }
        );
    }

    public function getObject(string $key, $default = null) {
        $defaultExists = func_num_args() >= 2;
        return $this->validateOrReturn(
            $key, 
            $defaultExists, 
            $default, 
            function($v) {
                if (is_array($v) || is_object($v)) return true;
                if (is_string($v)) {
                    $decoded = json_decode($v, true);
                    return json_last_error() === JSON_ERROR_NONE;
                }
                return false;
            }, 
            function($v) {
                if (is_array($v) || is_object($v)) return $v;
                return json_decode($v, true);
            }
        );
    }

    public function getFile(string $key, $default = null) {
        $defaultExists = func_num_args() >= 2;
        $file = $this->data[$key] ?? null;
        $hasFile = ($file !== null);
        if ($hasFile && is_array($file)) {
            if (isset($file['error']) && $file['error'] === UPLOAD_ERR_NO_FILE) {
                $hasFile = false;
            } elseif (empty($file['name'])) {
                $hasFile = false;
            }
        }
        if (!$hasFile) {
            if (!$defaultExists) {
                $err = new DataFailed("Missing or invalid value: '$key'", 400);
                $err->response();
            }
            return $default;
        }
        return $file;
    }

    public function getFiles(string $key = null, $default = null) {
        $defaultExists = func_num_args() >= 2;
        if ($key === null) {
            return $this->data;
        }
        $file = $this->data[$key] ?? null;
        $hasFile = ($file !== null);
        if ($hasFile && is_array($file)) {
            if (isset($file['error']) && $file['error'] === UPLOAD_ERR_NO_FILE) {
                $hasFile = false;
            } elseif (empty($file['name']) && (!is_array($file) || empty($file))) {
                $hasFile = false;
            }
        }
        if (!$hasFile) {
            if (!$defaultExists) {
                $err = new DataFailed("Missing or invalid value: '$key'", 400);
                $err->response();
            }
            return $default;
        }
        return $file;
    }
}

<?php

class Request {
    public RequestData $body;
    public RequestData $params;
    public RequestData $query;
    public RequestData $files;
    
    private array $comments = [];

    public function __construct() {
        // Parse params (GET)
        $paramsData = $_GET ?? [];
        if (empty($paramsData) && isset($_POST) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $paramsData = $_POST;
        }
        $this->params = new RequestData($paramsData, 'params');
        $this->query = new RequestData($paramsData, 'query');

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

    // Direct getter calls are disabled - users must specify body or query/params
    public function getString(string $key, $default = null): string {
        throw new Exception("Direct getter call '\$request->getString()' is not allowed. Please specify whether you want to retrieve it from 'body' or 'query' (e.g., '\$request->body->getString()' or '\$request->query->getString()').");
    }

    public function getInt(string $key, $default = null): int {
        throw new Exception("Direct getter call '\$request->getInt()' is not allowed. Please specify whether you want to retrieve it from 'body' or 'query' (e.g., '\$request->body->getInt()' or '\$request->query->getInt()').");
    }

    public function getFloat(string $key, $default = null): float {
        throw new Exception("Direct getter call '\$request->getFloat()' is not allowed. Please specify whether you want to retrieve it from 'body' or 'query' (e.g., '\$request->body->getFloat()' or '\$request->query->getFloat()').");
    }

    public function getBool(string $key, $default = null): bool {
        throw new Exception("Direct getter call '\$request->getBool()' is not allowed. Please specify whether you want to retrieve it from 'body' or 'query' (e.g., '\$request->body->getBool()' or '\$request->query->getBool()').");
    }

    public function getArray(string $key, $default = null): array {
        throw new Exception("Direct getter call '\$request->getArray()' is not allowed. Please specify whether you want to retrieve it from 'body' or 'query' (e.g., '\$request->body->getArray()' or '\$request->query->getArray()').");
    }

    public function getObject(string $key, $default = null) {
        throw new Exception("Direct getter call '\$request->getObject()' is not allowed. Please specify whether you want to retrieve it from 'body' or 'query' (e.g., '\$request->body->getObject()' or '\$request->query->getObject()').");
    }

    public function getFile(string $key, $default = null) {
        throw new Exception("Direct getter call '\$request->getFile()' is not allowed. Please specify whether you want to retrieve it from 'files' (e.g., '\$request->files->getFile()').");
    }

    public function getFiles(string $key = null, $default = null) {
        throw new Exception("Direct getter call '\$request->getFiles()' is not allowed. Please specify whether you want to retrieve them from 'files' (e.g., '\$request->files->getFiles()').");
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

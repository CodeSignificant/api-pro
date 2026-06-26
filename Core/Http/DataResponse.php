<?php

interface DataResponse {
    public function toArray(): array;
    public function response(): void;
    public function isSuccess(): bool;
    public function failed(): bool;
    public function isEmpty(): bool;
    public function getData();
    public function getMessage(): string;
    public function encrypt(string $key): DataResponse;
    public function status(int $code): DataResponse;
    public function header(string $name, string $value): DataResponse;
    public function getStatusCode(): int;
}

/**
 * ✅ Successful response wrapper
 */
class DataSuccess implements DataResponse {
    private $data;
    private $message;
    private $statusCode;
    private $encryptionKey;
    private $headers = [];

    public function __construct(string $message = 'Success', $data = null, int $statusCode = 200, ?string $encryptionKey = null) {
        $this->data = $data;
        $this->message = $message;
        $this->statusCode = $statusCode;
        $this->encryptionKey = $encryptionKey;
    }

    public function toArray(): array {
        $arr = [
            'success' => true,
            'message' => $this->message
        ];
        if ($this->data !== null) {
            $arr['data'] = DataEncryption::encrypt($this->data, $this->encryptionKey);
        }
        return $arr;
    }


    public function response(): void {
        http_response_code($this->statusCode);
        header('Content-Type: application/json');
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo json_encode($this->toArray(), JSON_PRETTY_PRINT);
        exit();
    }

    public function isSuccess(): bool {
        return true;
    }
    
    public function failed(): bool {
        return false;
    }
    
    public function getData() {
        return $this->data;
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function encrypt(string $key): DataResponse {
        $this->encryptionKey = $key;
        return $this;
    }

    public function status(int $code): DataResponse {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $name, string $value): DataResponse {
        $this->headers[$name] = $value;
        return $this;
    }


 /** ✅ True if data is empty/null/zero-length */
    public function isEmpty(): bool {
        if (is_array($this->data)) return empty($this->data);
        if (is_object($this->data)) return empty((array)$this->data);
        return empty($this->data);
    }
    
    public function __toString(): string {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}

/**
 * ❌ Failed response wrapper
 */
class DataFailed implements DataResponse {
    private $message;
    private $statusCode;
    private $data;
    private $encryptionKey;
    private $headers = [];

    public function __construct(string $message = 'Request failed', int $statusCode = 200, $data = null, ?string $encryptionKey = null) {
        $this->message = $message;
        $this->statusCode = $statusCode;
        $this->data = $data;
        $this->encryptionKey = $encryptionKey;
    }

    public function toArray(): array {
        $arr = [
            'success' => false,
            'message' => $this->message
        ];
        if ($this->data !== null) {
            $arr['data'] = DataEncryption::encrypt($this->data, $this->encryptionKey);
        }
        return $arr;
    }

    public function response(): void {
        http_response_code($this->statusCode);
        header('Content-Type: application/json');
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo json_encode($this->toArray(), JSON_PRETTY_PRINT);
        exit();
    }

    public function isSuccess(): bool {
        return false;
    }
    
    public function failed(): bool {
        return true;
    }
    
    
    public function isEmpty(): bool {
        return true;
    }
    
    public function getData() {
        return $this->data;
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function encrypt(string $key): DataResponse {
        $this->encryptionKey = $key;
        return $this;
    }

    public function status(int $code): DataResponse {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $name, string $value): DataResponse {
        $this->headers[$name] = $value;
        return $this;
    }



    public function __toString(): string {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}

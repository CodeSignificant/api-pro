<?php

interface DataResponse {
    public function toArray(): array;
    public function response(): void;
    public function isSuccess(): bool;
    public function failed(): bool;
    public function isEmpty(): bool;
    public function getData();
    public function getMessage(): string;
}

/**
 * ✅ Successful response wrapper
 */
class DataSuccess implements DataResponse {
    private $data;
    private $message;
    private $statusCode;

    public function __construct(string $message = 'Success', $data = null, int $statusCode = 200) {
        $this->data = $data;
        $this->message = $message;
        $this->statusCode = $statusCode;
    }

    public function toArray(): array {
        return [
            'success' => true,
            'message' => $this->message,
            'data' => $this->data
        ];
    }


    public function response(): void {
        http_response_code($this->statusCode);
        header('Content-Type: application/json');
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

    public function __construct(string $message = 'Request failed', int $statusCode = 200, $data = null) {
        $this->message = $message;
        $this->statusCode = $statusCode;
        $this->data = $data;
    }

    public function toArray(): array {
        return [
            'success' => false,
            'message' => $this->message,
            'data' => $this->data
        ];
    }

    public function response(): void {
        http_response_code($this->statusCode);
        header('Content-Type: application/json');
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



    public function __toString(): string {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}

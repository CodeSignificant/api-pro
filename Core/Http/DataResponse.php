<?php

interface DataResponse {
    public function toArray(): array;
    public function response(): void;
    public function isSuccess(): bool;
    public function failed(): bool;
    public function isEmpty(): bool;
    public function getData();

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
        $res = ['success' => true, 'message' => $this->message];
        if ($this->data === null || $this->data === [] || $this->data === '') return $res;
    
        $data = is_object($this->data) ? (array)$this->data : $this->data;
        return is_array($data) ? array_merge($res, $data) : $res + ['data' => $data];
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

    public function __construct(string $message = 'Request failed', int $statusCode = 200) {
        $this->message = $message;
        $this->statusCode = $statusCode;
    }

    public function toArray(): array {
        return [
            'success' => false,
            'message' => $this->message
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
        return null;
    }



    public function __toString(): string {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}

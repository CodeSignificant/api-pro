# ApiPro Framework - AI Developer Instructions

Hello AI / MCP Client. This document provides the strict rules and guidelines for generating API endpoints and services using the **ApiPro** framework (a highly secure, lightweight PHP REST API framework). 

Whenever the user asks you to create a new API, Service, or Endpoint using ApiPro, you **MUST** follow these rules.

## 1. File Structure & Architecture
- All business logic lives in "Service" classes located in `lib/services/`.
- Services are plain PHP classes (e.g., `class UserService { ... }`).
- Do not create standalone scripts for endpoints. Everything goes through a Service class method.

## 2. Routing (`index.php`)
Routes are registered programmatically. When asked to create an endpoint, provide the route registration code to be placed in `index.php`.
```php
$userService = ProNode::Service('/v1/users', new UserService());
$userService->get('/profile', 'getProfile'); // Maps to UserService->getProfile($node)
$userService->post('/create', 'createUser');
```

## 3. The `$node` Object (Request Parsing)
Every service method receives a `$node` object, but you should use the static methods on the `Node` class to parse request data.
- **Query Params (GET):** `$params = Node::params(['required_key']);`
- **JSON Body (POST/PUT/PATCH):** `$body = Node::body(['required_key']);`
- **Uploaded Files:** `$files = Node::files(['avatar']);`

*Note: Calling these methods with an array of required keys will automatically throw a 400 error if the key is missing.*

## 4. Standardized Responses
Never use `echo` or `print_r` or native `http_response_code` in a controller. Always return a `DataResponse` object. The framework will handle rendering it to JSON.
- **Success:** `return new DataSuccess("Operation successful", $optionalDataArray);`
- **Failure:** `return new DataFailed("Error message", 400);` (Second param is HTTP status code).

## 5. Database & Schema (ProSql)
- **Primary Keys MUST be UUIDs.** Never use Auto-Increment. Use `ProSql::UUID()` or the MySQL `uuid()` default.
- **Fetching a List:** `$res = ProSql::FetchList("SELECT * FROM users WHERE active = 1");`
- **Fetching a Single Item:** `$res = ProSql::FetchItem("SELECT * FROM users WHERE id = '$id'");`
- **Pagination:** Always use this for listings. 
  ```php
  $res = ProSql::FetchPaginated('users', '*', "active=1", $page, ['-created_at'], 20);
  ```
- **Insert/Update/Delete:** `$res = ProSql::Updated("UPDATE users SET name = 'John' WHERE id = '$id'");`
- **Security:** Always escape variables if not using parameterized queries: `$safe = ProSql::Escape($var);`

## 6. Authentication (`Token` class)
The framework uses a JWT-like symmetric encryption token system.
- **Get Current User ID:** `$userId = Token::GetId();` (Automatically throws 401 if unauthorized).
- **Generate Token (e.g., Login):** `$token = Token::Generate($userId, ['custom_claim' => 'value'], 'user');`

## 7. High-Concurrency Security (Rate Limiting & Locks)
When building sensitive endpoints (e.g., bookings, payments, logins), use these Redis-backed tools to prevent abuse and race conditions.

### Rate Limiting
Limit requests by IP or User ID.
```php
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!RateLimiter::check($ip, 5, 10)) { // 5 requests per 10 seconds
    return new DataFailed("Too many requests", 429);
}
```

### Distributed Mutex (Preventing Race Conditions)
If an endpoint modifies shared state (like booking a single room, or deducting inventory), you MUST wrap the logic in a distributed lock.
```php
$lockKey = "inventory_" . $itemId;
if (!ProLock::acquire($lockKey, 10)) { // 10 second timeout
    return new DataFailed("Resource is busy, try again later", 409);
}

// ... Check inventory and update database ...

ProLock::release($lockKey);
```

## Summary Example
```php
class ProductService {
    public function buy($node) {
        // 1. Auth
        $userId = Token::GetId();

        // 2. Body parsing
        $body = Node::body(['productId']);
        $productId = ProSql::Escape($body['productId']);

        // 3. Mutex Lock
        $lock = "product_" . $productId;
        if (!ProLock::acquire($lock, 5)) {
            return new DataFailed("System busy");
        }

        // 4. Database logic
        $product = ProSql::FetchItem("SELECT * FROM products WHERE id = '$productId'")->getData();
        if (!$product) {
            ProLock::release($lock);
            return new DataFailed("Not found", 404);
        }

        // 5. Response
        ProLock::release($lock);
        return new DataSuccess("Purchased!");
    }
}
```

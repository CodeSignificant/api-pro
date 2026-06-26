# ApiPro Framework - AI Developer Instructions

Hello AI / MCP Client. This document provides the strict architectural standards and guidelines for generating controllers, services, repositories, and models in the **ApiPro** framework—a highly secure, lightweight PHP REST API framework.

Whenever the user asks you to create a new API, Service, or Endpoint in ApiPro, you **MUST** adhere to these rules.

---

## 1. Clean Architecture & Layered Structure

ApiPro strictly separates concerns across three core architectural layers to guarantee state-free logic and modular testability:

### 1. Controller Layer (`lib/controller/`)
- Acts as the HTTP gateway and routing boundary.
- Registered via modern declarative attributes: `#[Controller('/v1/path')]`, `#[Get('/route')]`, `#[Post('/route')]`.
- **Responsibilities**:
  1. Parses raw HTTP requests using static helpers: `Node::body()`, `Node::params()`, `Node::files()`.
  2. Resolves and validates security sessions: `Session::Get($expectedRole)`.
  3. Translates HTTP-level details (session ID, device ID) into simple, primitive values.
  4. Delegates business rules to the Service Layer and forwards the returned `DataResponse`.

### 2. Service Layer (`lib/service/`)
- Contains **pure, state-free business logic**.
- **Rule**: Must **NEVER** reference `Session::Get()`, HTTP headers, or request contexts directly.
- Service methods accept plain PHP parameters (e.g. `$userId`, `$deviceId`) passed down by the Controller, keeping the business logic 100% testable and decoupled from the web runtime.

### 3. Repository Layer (`lib/repo/`)
- Interfaces directly with state stores (MySQL, Redis) and returns raw data arrays or models.

---

## 2. Standardized Responses (`DataResponse`)

Every route method **MUST** return a clean `DataResponse` object. The framework automatically parses and prints a unified JSON structure:

### Clean Success Envelope (`DataSuccess`)
```php
return new DataSuccess("Message", $payload);
```
Outputs the standard, unified response layout (never merges properties into root):
```json
{
    "success": true,
    "message": "Message",
    "data": { ... }
}
```

### Clean Failure Envelope (`DataFailed`)
```php
return new DataFailed("Error detail", 400); // 400 is the HTTP status code
```
Outputs:
```json
{
    "success": false,
    "message": "Error detail"
}
```

---

## 3. Declarative Routing & Request Extraction

```php
#[Controller('/v1/users')]
class UserController
{
    private UserService $service;

    public function __construct() {
        $this->service = new UserService();
    }

    #[Post('/register')]
    public function register()
    {
        // 1. Mandatory Body Param Validation (throws 400 if missing)
        $body = Node::body(['email', 'password']);
        
        // 2. Delegate to state-free service
        return $this->service->createUser($body['email'], $body['password']);
    }

    #[Get('/profile')]
    public function getProfile()
    {
        // 1. Resolve active session from HTTP context
        $session = Session::Get(); 
        $userId = $session['id'];

        // 2. Delegate using primitive identifier
        return $this->service->getUserProfile($userId);
    }
}
```

### Request Extraction Methods:
- **Query Params**: `Node::params(['key1', 'key2'])`
- **JSON Body**: `Node::body(['email', 'password'])`
- **Files**: `Node::files(['avatar'])`

---

## 4. Authentication & Device Security

ApiPro utilizes an Encrypt-then-MAC symmetric token binding mechanism for robust multi-device session management.

- **Login (Generating Token)**:
  ```php
  $token = Token::Generate($user['id'], ['email' => $user['email']], $user['role']);
  ```
- **Session Parsing (Controller Gateway)**:
  ```php
  $session = Session::Get(); // returns ['id' => '...', 'did' => '...', 'r' => '...']
  ```
- **Cross-Client Device Auto-Sync**:
  The framework dynamically binds tokens to client devices via the `X-Device-Id` and `Device-Id` headers. If running terminal-based commands (like `curl` or Postman) where headers are missing, the state verification dynamically validates active sessions against the Redis store to ensure seamless developer testing.

---

## 5. Database Operations (`ProSql`)

- **Primary Keys**: Always use UUID strings. Never use auto-increment integers. Fetch via `ProSql::UUID()`.
- **Query Escaping**: Always wrap inputs using `ProSql::Escape($value)`.
- **List Fetching**: `$list = ProSql::FetchList("SELECT * FROM products");`
- **Item Fetching**: `$item = ProSql::FetchItem("SELECT * FROM products WHERE id = '$id'");`
- **Deterministic Pagination**:
  ```php
  $pageResult = ProSql::FetchPaginated(
      'products', 
      '*', 
      'active = 1', 
      $page, 
      ['-created_at'], // Order by created_at ASC ('+' for DESC)
      20
  );
  ```

---

## 6. High-Concurrency Controls (Locks & Rate Limiting)

### Distributed Lock (`ProLock`)
Wrap critical write operations (deducting inventory, booking assets, payments) in a distributed Redis mutex:
```php
$lock = "order_lock_" . $productId;
if (!ProLock::acquire($lock, 5)) {
    return new DataFailed("Resource is currently locked. Try again later.", 409);
}

try {
    // Process write operation...
} finally {
    ProLock::release($lock);
}
```

### Rate Limiter (`RateLimiter`)
Protect public endpoints from abuse:
```php
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!RateLimiter::check($ip, 10, 60)) { // Max 10 requests per 60 seconds
    return new DataFailed("Too many requests", 429);
}
```

---

## Summary Implementation Example

```php
// lib/controller/ProductController.php
#[Controller('/v1/products')]
class ProductController {
    private ProductService $service;

    public function __construct() {
        $this->service = new ProductService();
    }

    #[Post('/purchase')]
    public function purchase() {
        $session = Session::Get();
        $userId = $session['id'];
        
        $body = Node::body(['productId']);
        return $this->service->purchaseProduct($userId, $body['productId']);
    }
}

// lib/service/ProductService.php
class ProductService {
    public function purchaseProduct($userId, $productId): DataResponse {
        $safeProductId = ProSql::Escape($productId);
        $lockKey = "purchase_" . $safeProductId;

        if (!ProLock::acquire($lockKey, 5)) {
            return new DataFailed("System busy, please try again", 409);
        }

        try {
            $product = ProSql::FetchItem("SELECT * FROM products WHERE id = '$safeProductId'")->getData();
            if (!$product) {
                return new DataFailed("Product not found", 404);
            }
            
            // Execute pure database update logic here...
            
            return new DataSuccess("Purchase complete!");
        } finally {
            ProLock::release($lockKey);
        }
    }
}
```

---

## 7. Automatic Database Migrations & Locking (`Repository`)

All repositories **MUST** inherit from the base `Repository` parent class. This class automatically manages database schema checks, creations, incremental alterations, and structural locks controlled globally by the `DB_WRITE` setting inside `config.php`.

### Available Sync Settings in `config.php`:
- `define('DB_WRITE', 'update');` (Default / Development): Automatically creates missing tables and runs incremental structure alterations on existing tables.
- `define('DB_WRITE', 'create');`: Creates tables only if they are missing.
- `define('DB_WRITE', 'force');` (or `'recreate'`): Automatically drops existing tables and recreates them fresh.
- `define('DB_WRITE', false);`: Completely suspends all constructor checks and database sync activity (highly recommended for production performance).

### Constructor Table Schema Declarations:
Repositories pass an associative array mapping table names to their SQL schemas, along with optional structural lock protection settings:

```php
class AuthRepository extends Repository
{
    public function __construct()
    {
        parent::__construct([
            'users' => [
                'lock' => true,                       // Guards the table against all drops, recreations, or alters if it already exists
                'schema' => "CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    role VARCHAR(50) DEFAULT 'user'
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
            ]
        ]);
    }
}
```

### The 3-Tier Lock Hierarchy:
1. **Global Lock (Project Scope)**: Set `define('DB_WRITE', 'lock');` in `config.php` to prevent drops or alterations on any table in the entire codebase.
2. **Repository Lock (Class Scope)**: Pass `true` or `['lock' => true]` as the second argument to `parent::__construct($tables, true)` to lock all tables declared inside that repository class.
3. **Table Lock (Table Scope)**: Define `'lock' => true` on an individual table configuration array to protect only that table.

---

## 8. Data Encryption Layer

ApiPro features a dedicated symmetric data encryption layer. This encrypts the payload `"data"` key in all success/failed envelopes without changing the API contract.

- **Configuration**: Set `define("DATA_ENC", "your_secret_key");` in `config.php`. If `DATA_ENC` is empty or undefined, data encryption is disabled by default.
- **Custom Key / Bypass Overrides**: Both `DataSuccess` and `DataFailed` constructors accept a fourth optional parameter `$encryptionKey` (`?string $encryptionKey = null`):
  1. **Null (Default)**: Uses the global `DATA_ENC` config. If configured, encrypts the data; otherwise, returns data as-is.
  2. **Empty String `""`**: Explicitly disables encryption for this response (even if `DATA_ENC` is configured globally).
  3. **Non-Empty String**: Encrypts the response data using this custom key.

### Code Usage Examples:
```php
// 1. Default (uses global DATA_ENC key)
return new DataSuccess("Message", $data);

// 2. Explicitly unencrypted (passes empty string key override)
return new DataSuccess("Message", $data, 200, '');

// 3. Custom key encryption (uses 'custom_secret_key')
return new DataSuccess("Message", $data, 200, 'custom_secret_key');
```

---

## 9. Setup, Project Startup, and Local Execution

### Local Development Server
To start the local built-in PHP development server, run from the project root:
```bash
php -S 127.0.0.1:8000 index.php
```
Alternatively, you can run:
```bash
composer start
```
The server will be accessible at `http://127.0.0.1:8000`.

### Setup Checklist
1. **Initialize Workspace**: Run `composer install` to download dependencies and trigger post-install configuration mappings.
2. **Environment Configuration**: Set credentials and encryption constants in `config.php`:
   - `SERVER_ENC` (Tokens and session keys)
   - `DATA_ENC` (Symmetric response data keys)
   - `CORS` (Comma-separated allowed CORS origins. If empty/undefined, defaults to `"http://localhost:3000, http://127.0.0.1:3000"`)
   - Redis host and port config (`TOKEN_DRIVER => 'redis'`)
   - Database sync modes (`DB_WRITE => 'update'` for dev, `DB_WRITE => false` for database-less or production mode)

---

## 10. ApiPro CLI Tool

The framework includes a terminal CLI tool in the project root: `api-pro`.

### Commands
- **Check version**:
  ```bash
  php api-pro version
  ```
- **Update Framework**:
  Downloads the latest release ZIP package from the official repository and securely updates the `Core/` framework directory, entry points (`index.php`, `.htaccess`), configuration setup (`post-install.php`), `composer.json` file, and dependencies while completely preserving your custom application code (`lib/`) and configuration settings (`config.php`). It automatically executes the `post-install.php` setup script on completion to finalize configuration and sync missing settings.
  
  > [!IMPORTANT]
  > Always run the CLI tool from the project root directory where the `api-pro` script is located.
  ```bash
  php api-pro update [version]
  ```
  *(Example: `php api-pro update 2.3.1` or simply `php api-pro update` to fetch the latest release).*


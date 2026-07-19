# ApiPro — Lightweight PHP REST API Framework

ApiPro is a high-performance, lightweight, production-ready PHP REST framework built on a secure, service-oriented paradigm. It is engineered with a strict **Separation of Concerns** architecture, native multi-device stateful sessions via Redis, deterministic page builders, and distributed high-concurrency protection tools.

---

## Key Features

- **Clean Layered Architecture**: Strictly isolates HTTP parsing and routing gateways (Controllers) from pure, state-free business logic (Services).
- **Attribute-Based Declarative Routing**: Map endpoints using clean PHP attributes: `#[Controller]`, `#[Get]`, `#[Post]`.
- **Unified JSON Response Envelopes**: Consistent API contracts structured cleanly under `'success'`, `'message'`, and `'data'` keys without top-level property pollution.
- **Robust Stateful Session Security**: Crypto-secure Encrypt-then-MAC tokens with Redis-backed multi-device limiters, automatic hijacking protection, and developer-friendly `curl` compatibility.
- **Distributed Concurrency Mutex**: Native Redis-backed distributed locks to prevent race conditions during write/read operations.
- **Deterministic Pagination**: Group-safe, JOIN-safe paginator that structures queries with absolute mathematical precision.

---

## Directory Layout

```text
app/
 ├── Core/                  # Core Framework Engine
 │    ├── Http/             # Unified HTTP responses & models
 │    ├── Security/         # Tokens, Sessions, and Device Security managers
 │    ├── Services/         # Diagnostic & Tester backend services
 │    ├── ProSql.php        # Deterministic paginator & SQL escape engine
 │    └── Files.php         # Strict HTTP uploaded & generated file manager
 ├── lib/                   # Application Namespace
 │    ├── controller/       # HTTP Entry Controllers (Session verification, Routing)
 │    ├── service/          # Pure, State-Free Service Logic (Calculations, Database writes)
 │    └── repo/             # Data access Repositories
 ├── index.php              # Application Router & Entrypoint
 └── config.php             # System constants & Redis/MySQL Configuration
```

---

## 1. Clean Separation of Concerns

To maximize testability and keep code state-free, ApiPro strictly separates request boundaries:

1. **The Controller** parses inputs, manages HTTP headers, verifies permissions using the secure `Session::Get()` module, and converts web state into primitives (such as `$userId`).
2. **The Service** acts as a pure machine. It has zero knowledge of HTTP headers, cookies, or the global web environment. It operates purely on arguments passed down by the Controller and returns a standardized `DataResponse`.

### Implementation Example

#### Controller Layer (`lib/controller/ProductController.php`):
```php
#[Controller('/v1/products')]
class ProductController
{
    private ProductService $service;

    public function __construct() {
        $this->service = new ProductService();
    }

    #[Post('/purchase')]
    public function purchase($request)
    {
        // 1. Authenticate and resolve session context
        $session = Session::Get(); 
        $userId = $session['id'];

        // 2. Validate mandatory request body arguments (throws 400 if missing or invalid)
        $productId = $request->body->getString('productId');

        // 3. Delegate to the pure service
        return $this->service->purchaseProduct($userId, $productId);
    }
}
```

#### Service Layer (`lib/service/ProductService.php`):
```php
class ProductService
{
    public function purchaseProduct($userId, string $productId): DataResponse
    {
        $safeId = ProSql::Escape($productId);
        
        // Pure business operation decoupled from HTTP variables
        $product = ProSql::FetchItem("SELECT * FROM products WHERE id = '$safeId'")->getData();
        if (!$product) {
            return new DataFailed("Product not found", 404);
        }

        // Process purchase...
        return new DataSuccess("Purchase completed successfully", [
            'productId' => $productId,
            'timestamp' => time()
        ]);
    }
}
```

---

## 2. Request Handling & Parameter Validation (Deprecated: Node)

ApiPro introduces the object-oriented `Request` class for extracting and validating input parameters.

### Request Injection
Controller methods access the current HTTP request by accepting a `$request` parameter (which is injected automatically by the router):

```php
#[Post('/user-details')]
public function showDetails($request)
{
    // Access GET/POST query parameters
    $request->params->getString('key');
    
    // Access POST/PATCH/DELETE JSON or form body
    $request->body->getInt('key');

    // Access uploaded files
    $request->files->getFile('avatar');
}
```

### Type-Safe Retrieval & Validation Rules
The `$request->body`, `$request->params`, and `$request->files` sub-properties (instances of `RequestData`) provide type-specific methods that cast values and handle validation:

* `getString(string $key, $default = null): string`
* `getInt(string $key, $default = null): int`
* `getFloat(string $key, $default = null): float`
* `getBool(string $key, $default = null): bool`
* `getArray(string $key, $default = null): array`
* `getObject(string $key, $default = null)`
* `getFile(string $key, $default = null)`
* `getFiles(string $key = null, $default = null)`

#### Mandatory vs Optional Parameters:
1. **Mandatory**: If the second argument (`$default`) is **omitted** (checked via argument count), the parameter is treated as required. If it is missing or fails the type validation, the request automatically halts and returns a standard `400 Bad Request` validation response (`DataFailed`) detailing the missing key.
2. **Optional**: If a default value is provided, the key is treated as optional, and the default value is returned if the parameter is missing or invalid.

### Endpoint Documentation (`addComment`)
You can document an endpoint inside its controller method using `$request->addComment("Read me description")`. The static router analysis parses these comments and displays them in the interactive tester UI:

```php
#[Post('/book')]
public function bookRoom($request)
{
    $request->addComment("This endpoint accepts a booking. Requires roomId (int) and description (string).");
    ...
}
```

### Legacy Node Class (Deprecated)
The static `Node` class (`Node::body()`, `Node::params()`, `Node::files()`) is deprecated. Existing usages are fully backward compatible but will log and trigger deprecation headers:
```http
X-ApiPro-Warning: Node class is deprecated. Please migrate to Request class.
```

---

## 3. Standardized JSON Envelope Contract

All success responses returned by the framework are automatically formatted using a unified, clean three-key envelope:

```json
{
    "success": true,
    "message": "Purchase completed successfully",
    "data": {
        "productId": "4df1c080-...",
        "timestamp": 1779991200
    }
}
```
If a request fails, a clean error structure is returned:
```json
{
    "success": false,
    "message": "Product not found"
}
```

---

## 4. High-Concurrency Distributed Locks (`ProLock`)

Protect critical pathways (deducting balances, booking hotel rooms, checking out shopping carts) from double-spend and race conditions with native Redis-backed mutexes:

```php
$lock = "balance_deduct_" . $userId;
if (!ProLock::acquire($lock, 5)) { // Attempt to acquire lock with 5s timeout
    return new DataFailed("System busy. Please try again.", 409);
}

try {
    // Perform critical write action safely...
} finally {
    ProLock::release($lock); // Always release lock in finally block
}
```

---

## 5. Multi-Device Stateful Session Security

ApiPro provides full session control with customizable maximum device limits:

- **Stateful Driver Support**: Easily switch session management to Redis by setting `TOKEN_DRIVER` to `'redis'` in `config.php`.
- **Multi-Device Concurrency Control**: Define `TOKEN_MAX_DEVICES` (default `5`) to limit concurrent sessions.
- **Developer-Friendly API Testing**: Dynamic validation allows stateful tokens to be tested seamlessly on `curl` or Postman without getting blocked by user-agent fingerprint mismatches when custom device tracking headers are omitted.

---

## 6. Config-Driven Automatic Schema Sync & 3-Tier Locks

ApiPro introduces a base `Repository` (backed by `ProRepository`) class that automatically synchronizes database table schemas declared inside child constructors, managed globally or protected by a robust 3-tier lock system.

### Key Operational Sync Modes (`config.php`):
- `define('DB_WRITE', 'update');` (Default / Development): Automatically creates missing tables and runs incremental structure alterations on existing tables.
- `define('DB_WRITE', 'create');`: Creates tables only if they are missing.
- `define('DB_WRITE', 'force');` (or `'recreate'`): Automatically drops existing tables and recreates them fresh.
- `define('DB_WRITE', false);`: Completely suspends all constructor checks and database sync activity (highly recommended for production performance).

### The 3-Tier Lock Hierarchy:
1. **Global Lock (Project Scope)**: Set `define('DB_WRITE', 'lock');` in `config.php` to prevent drops or alterations on any table in the entire codebase.
2. **Repository Lock (Class Scope)**: Pass `true` or `['lock' => true]` as the second argument to `parent::__construct($tables, true)` to lock all tables declared inside that repository class.
3. **Table Lock (Table Scope)**: Define `'lock' => true` on an individual table configuration array to protect only that table.

If locked at any level, the engine will still auto-create the table if it's missing (to prevent app crashes), but will completely bypass any drops, recreations, or alters if the table already exists.

---

## 7. Symmetric Data Encryption Layer

ApiPro features a dedicated symmetric data encryption layer for response payloads. This encrypts the value of the `"data"` key in all success and failure response envelopes.

- **Global Configuration**: Set `define("DATA_ENC", "your_secret_key");` in `config.php`. If `DATA_ENC` is empty or undefined, data encryption is disabled globally.
- **Custom Key / Bypass Overrides**: Both `DataSuccess` and `DataFailed` constructors accept a fourth optional parameter `$encryptionKey` (`?string $encryptionKey = null`):
  1. **Null (Default)**: Uses the global `DATA_ENC` key. If configured, encrypts the data; otherwise, returns data as-is.
  2. **Empty String `""`**: Explicitly disables encryption for this response (even if `DATA_ENC` is configured globally).
  3. **Non-Empty String**: Encrypts the response data using this custom key.

### Code Examples:
```php
// 1. Default (uses global DATA_ENC key)
return new DataSuccess("Message", $data);

// 2. Explicitly unencrypted (passes empty string key override)
return new DataSuccess("Message", $data, 200, '');

// 3. Custom key encryption (uses 'custom_secret_key')
return new DataSuccess("Message", $data, 200, 'custom_secret_key');
```

---

## 8. Running and Setting Up the Project

### Local Development Server
To start the built-in PHP development server, run from the project root:
```bash
php -S 127.0.0.1:8000 index.php
```
Or you can use the composer script:
```bash
composer start
```
The server will be running on `http://127.0.0.1:8000`.

### Setup Checklist
1. **Initialize Workspace**: Run `composer install` to download dependencies and trigger post-install mappings.
2. **Environment Configuration**: Set credentials and encryption constants in `config.php`:
   - `SERVER_ENC` (Tokens and session keys)
   - `DATA_ENC` (Symmetric response data keys)
   - Redis host and port config (`TOKEN_DRIVER => 'redis'`)
   - Database sync modes (`DB_WRITE => 'update'` for dev, `DB_WRITE => false` for database-less or production mode)

---

## 9. ApiPro CLI Tool

The framework includes a CLI companion tool in the project root: `api-pro`.

### Commands
- **Check version**:
  ```bash
  php api-pro version
  ```
- **Update Framework**:
  Downloads the latest release ZIP package from the official repository and securely updates the `Core/` framework directory, entry points (`index.php`, `.htaccess`), and dependencies while completely preserving your custom application code (`lib/`), configurations (`config.php`), and non-project folders:
  ```bash
  php api-pro update [version]
  ```
  *(Example: `php api-pro update 2.1.0` or simply `php api-pro update` to fetch the latest release).*


## Installation Guide

### Prerequisites
Make sure your development server meets the following requirements:
- **PHP**: Version 8.0 or higher.
- **Extensions**: `pdo_mysql`, `openssl`, `redis` (or a compatible Redis library), and optionally `zip` (for framework updates).
- **Composer**: PHP dependency manager.
- **Services**: MySQL/MariaDB and Redis.

### Option 1: Via Composer (Recommended)
Create a brand new ApiPro project from the official skeleton:
```bash
composer create-project codesignificant/api-pro my-new-api
```
This command downloads the framework skeleton, initializes the correct configurations, performs post-install mappings, and cleans up temporary setup documentation for a production-ready folder structure.

### Option 2: Direct Git Clone
Clone the repository manually:
```bash
# Clone the repository
git clone https://github.com/CodeSignificant/api-pro.git my-new-api
cd my-new-api

# Install dependencies and trigger post-install setup
composer install
```

### Post-Installation Setup
1. **Configure Environment**: Update `config.php` in the root directory with your MySQL DB, Redis, and MAILER settings.
2. **Set up Web Server routing**:
   - **Apache**: The preconfigured `.htaccess` file handles URL rewriting and routes requests to `index.php` out of the box. Ensure `mod_rewrite` is enabled.
   - **Nginx**: Set your server block configuration to rewrite unmatched requests:
     ```nginx
     location / {
         try_files $uri $uri/ /index.php?$query_string;
     }
     ```


## Release Notes & Updates

### Version 2.4.0 Release Notes

ApiPro `v2.4.0` focuses on improving request input validation, parameter type safety, and inline endpoint documentation.

- **Object-Oriented Request & RequestData Classes**:
  - Replaced the deprecated static `Node` utility class with a dynamically injected `Request` instance.
  - Implemented type-safe helper getters: `getString`, `getInt`, `getFloat`, `getBool`, `getArray`, `getObject`, `getFile`, and `getFiles`.
  - Checking for argument count automatically enforces mandatory/optional parameters. Omitting the default value validates input presence and returns a `400 Bad Request` on failure, while including a default value makes it optional.
- **Backward Compatibility & Warning Headers**:
  - Full backward compatibility for controllers using `Node::body()`, `Node::params()`, or `Node::files()`.
  - Generates an `X-ApiPro-Warning` header and flags routes in the debugger console if they continue to use the legacy `Node` class.
- **Dynamic Endpoint Documentation (`addComment`)**:
  - Supported adding README documentation to route methods using `$request->addComment("...")`.
  - Statically extracts comments and renders them within a dedicated read-me section in the API Tester UI.

### Version 2.3.0 Release Notes

ApiPro `v2.3.0` focuses on modularizing the debugger tools, introducing global utility logging, and stabilizing the CLI update engine.

- **Modular Developer Console**:
  - Relocated the monolithic `logs.html` and `test.html` assets into organized subfolders (`Core/logs/` and `Core/tester/`) to maintain a clean workspace.
  - Added an in-place highlight and find navigation system (browser-like `Ctrl+F` layout) with arrow cycle buttons and Enter/Shift+Enter keystroke navigation in the Logger console.
  - Shortened and styled the timestamp toggle into a compact, space-saving clock icon toggle button.
  - Automatically filters long absolute file directories and line number strings from PHP error logs to keep message outputs clean.
- **Global Helper Logging**:
  - Introduced the static helper class `Log` (e.g. `Log::info()`, `Log::warning()`, `Log::error()`) write-mapped directly to the error stream.
  - Implemented automatic logging of incoming request methods/paths and corresponding response status codes.
- **Payload Optimizations**:
  - Configured `DataResponse` to completely omit the `"data"` field from final JSON responses when its value is `null`.
  - Added text masking styles and autocomplete attributes to password fields to prevent browser autofill suggestions.
- **Framework CLI Updater Improvements**:
  - Overhauled `php api-pro update` to clear out outdated files before copying, eliminating old directory junk.
  - Added a multi-layered downloader that tries `curl` first before falling back to native stream wrappers.
  - Uses native PHP `ZipArchive` unpacking with a command-line `unzip` fallback.

### How to Update an Installed Instance

To update your existing ApiPro project to this latest release, execute the built-in updater command from your terminal:

```bash
php api-pro update 2.4.0
```

The updater will download the specified release, safely perform a fresh overwrite of the framework `Core/` directory, and verify your updated setup while keeping your custom `lib/` controllers/services and `config.php` files untouched.

---

## License

This project is open-source software licensed under the MIT License.

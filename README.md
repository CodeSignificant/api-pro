ApiPro

ApiPro is a lightweight, production-ready PHP API framework designed for structured service-based applications.

It provides:

Deterministic pagination (including grouped queries)

UUID-first schema design

Structured API responses

Strict file upload handling

Safe storage for generated files

Service-driven architecture

Microservice-ready foundation

Installation

Install via Composer:

composer require your-vendor/apipro


Requirements:

PHP 8.1+

MySQL / MariaDB

ext-curl (recommended)

Core Concepts
1. Structured Responses

All endpoints return a consistent response structure:

return new DataSuccess("Message", $data);
return new DataFailed("Error message");


Example response:

{
  "success": true,
  "message": "Fetched successfully",
  "data": { ... }
}

2. UUID-First Database Design

All tables use UUID as primary key:

id CHAR(36) NOT NULL DEFAULT uuid()


Benefits:

Distributed-safe

Microservice-friendly

No auto-increment conflicts

Horizontally scalable

3. Pagination (Deterministic & Group-Safe)

ApiPro provides production-grade pagination:

ProSql::FetchPaginated(
    $table,
    $columns,
    $where,
    $page,
    $orderBy,
    $pageSize
);


Supports:

JOIN queries

GROUP BY queries

Subqueries

Deterministic ordering

Custom counters

Order direction convention:

['+created_at'] // DESC
['-created_at'] // ASC


Grouped pagination is handled safely to prevent duplicate counts.

4. File Handling
Upload Files (HTTP only)
Files::save($_FILES['file'], "/storage/images");


Features:

Strict is_uploaded_file() validation

Safe directory creation

Unique naming

Size reporting

Store Generated Files (Exports, Reports)
Files::storage($absolutePath, "/exports/reports");


Used for:

Excel exports

CSV reports

Background-generated files

Upload and generated file flows are intentionally separated for security and correctness.

5. Service-Based Architecture

Each module follows a service structure:

class ProductService {

    public function fetch($node) {}
    public function create($node) {}
    public function update($node) {}
    public function setup($node) {}
}


Responsibilities:

setup() → schema creation

fetch() → paginated listing

create() → insert logic

update() → modification logic

Example Use Cases

ApiPro is suitable for:

E-commerce platforms

Variant-based product filtering

RGB color nearest search

Cart and order management

Seller onboarding systems

Invoice generation

Export automation

Logging systems

Safe Schema Setup Pattern
public function setup($node)
{
    ProSql::Query("
        CREATE TABLE IF NOT EXISTS products (
            id CHAR(36) NOT NULL DEFAULT uuid(),
            PRIMARY KEY(id),
            title VARCHAR(255) NOT NULL
        )
    ");

    return new DataSuccess("Table ready");
}


Supports:

Force recreate mode

Idempotent table creation

Production-safe migrations

Security Model

No file path leakage

Strict upload validation

No bypass of is_uploaded_file

Escaped SQL inputs

Explicit API failure handling

Suggested Project Structure
app/
 ├── Services/
 │    ├── ProductService.php
 │    ├── OrderService.php
 │    ├── SellerService.php
 │    └── LogService.php
 ├── Core/
 │    ├── ProSql.php
 │    ├── Files.php
 │    └── DataResponse.php

Best Practices

Always use FetchPaginated for listings

Use UUID everywhere

Keep upload and storage flows separate

Validate external API status responses

Use grouped pagination correctly

Roadmap

Background job support

Queue system

Token-based file downloads

Validation layer

Rate limiting

Module scaffolding

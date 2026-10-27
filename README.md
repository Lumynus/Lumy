<div align="center">

# Lumy

### **The Multi-Dialect MicroORM & Data Mapper of the Lumynus Ecosystem**

*Simple by design. Fast by default. Secure on every query.*  
**Traditional where it matters. Visionary where it accelerates.**

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.2-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Databases](https://img.shields.io/badge/Databases-MySQL%20%7C%20PostgreSQL%20%7C%20SQLite%20%7C%20SQL%20Server%20%7C%20Firebird-2563eb?style=for-the-badge)](https://lumy.lumynus.dev/)
[![Ecosystem](https://img.shields.io/badge/Ecosystem-lumynus.dev-0f172a?style=for-the-badge)](https://lumynus.dev/)
[![Docs](https://img.shields.io/badge/Docs-lumy.lumynus.dev-10b981?style=for-the-badge)](https://lumy.lumynus.dev/)
[![License](https://img.shields.io/badge/License-MIT-f59e0b?style=for-the-badge)](https://lumynus.dev/)

<p align="center">
  <a href="README_pt.md"><strong>🇧🇷 Ler em Português (README_pt.md)</strong></a> •
  <a href="https://lumy.lumynus.dev/"><strong>📚 Official Documentation (lumy.lumynus.dev)</strong></a> •
  <a href="https://lumynus.dev/"><strong>🌐 Main Website (lumynus.dev)</strong></a> •
  <a href="#-4-all-ways-to-use-lumy"><strong>🚀 Quick Start</strong></a> •
  <a href="#-9-transactions-all-usage-patterns"><strong>🔄 Transactions</strong></a> •
  <a href="examples/README.md"><strong>💻 Runnable Examples</strong></a>
</p>

</div>

---

## 🧠 What is Lumy? (MicroORM, Query Builder, or Data Gateway?)

**Lumy** is a **Hybrid Data-First MicroORM (*Array-First Data Mapper & Table Data Gateway*)** with a **native multi-dialect SQL translator** and a **built-in SQL migrations runner**.

In practice, it sits at the sweet spot between **raw PDO** (which requires repetitive, dialect-specific boilerplate SQL) and **heavyweight traditional ORMs** (like Eloquent or Doctrine, which hydrate hundreds of objects in memory, rely on excessive reflection/magic, and easily fall into *N+1 query* traps).

### Why does this architecture matter?

1. **More than a Query Builder, lighter than a classic ORM:**
   - Like a full **ORM**, Lumy provides declarative column mapping (`$fields`), automatic hidden fields (`'select' => false`), `1:1`, `1:N`, and nested relationships (`$relationships`), savepoint-aware transactions, and automatic multi-dialect `upsert`.
   - Unlike a heavy Active Record ORM, Lumy is **Array-First (Zero Hydration Overhead)**: it returns clean associative arrays (with optional automatic `snake_case ↔ camelCase` transformation), ready for JSON serialization in APIs or View rendering with minimal CPU and memory overhead.

2. **Dual-Mode Architecture (You choose how to work):**
   - **Instant Mode (`new Model('table')`)**: Query or mutate any table immediately without creating a dedicated class — ideal for scripts, pivot/auxiliary tables, microservices, and rapid prototyping.
   - **Structured Mode (`extends AbstractModel`)**: Build domain Models with column whitelist protection, SQL joins, aggregations (`GROUP BY` / `HAVING`), batched relationships, and custom domain methods.

3. **Write Once, Run Across 5 Relational Databases:**
   - Lumy transparently translates pagination (`LIMIT/OFFSET`, `OFFSET ... ROWS FETCH NEXT`, `ROWS m TO n`), `upsert()` (`ON DUPLICATE KEY UPDATE`, `ON CONFLICT DO UPDATE`, `MERGE`, `UPDATE OR INSERT`), `LIKE ESCAPE`, and `SAVEPOINT` across **MySQL/MariaDB**, **PostgreSQL**, **SQLite**, **SQL Server**, and **Firebird/InterBase**.

### Architectural Comparison

| Feature | Raw PDO / Driver | **Lumy MicroORM** | Heavy Traditional ORMs |
| :--- | :---: | :---: | :---: |
| **Memory & CPU Footprint** | Very Low | **Very Low (Array-First)** | High (Object/Proxy Hydration) |
| **Learning Curve** | Low (Manual SQL) | **Immediate (Clean, explicit API)** | Steep (Hidden magic & conventions) |
| **Zero-Class Table Access** | Manual | **Yes (`new Model('table')`)** | No (Requires Entity/Model classes) |
| **Hidden Sensitive Columns** | Manual | **Native (`'select' => false`)** | Via extra model properties |
| **Standardized Multi-Dialect Pagination (`data` / `meta`)** | No | **Yes (5 SQL dialects)** | Yes |
| **Native Multi-Dialect Upsert** | No | **Yes (5 SQL dialects)** | Partial |
| **Relationships without N+1 or broken `LIMIT`** | Manual | **Yes (Batch Loading `IN (...)`)** | Easy to trigger accidental N+1 |
| **Global `transaction()` with Custom Rollback** | No | **Yes (`transaction($tx, $rollback)`)** | No dedicated rollback callback |
| **Built-in `.sql` Migrations Runner with Lock** | No | **Built-in (`bin/migrate.php`)** | Usually requires extra packages |

---

## ✨ Key Advantages of Lumy

- ⚡ **Predictable Performance:** No hidden lazy-loading firing dozens of silent queries. Relationships are loaded only when requested via `with`, always in batches (*1 query per relation*).
- 🛡️ **Secure by Design:**
  - 100% of values in `insert`, `upsert`, `update`, `delete`, and `select` use typed *prepared statements*;
  - Strict regex validation on SQL identifiers against SQL Injection;
  - Automatic column *whitelist* when `$fields` is defined (blocks filtering or sorting by unauthorized columns);
  - Built-in protection blocking `UPDATE` and `DELETE` without a `WHERE` clause;
  - Passwords protected with `#[\SensitiveParameter]` (never leaked in logs or stack traces);
  - DSN sanitization and automatic wildcard escaping (`%`, `_`, `\`) in `LIKE` searches.
- 🔄 **Powerful & Flexible Transactions:** Global `transaction($transaction, $rollback)` function that rolls back automatically by default or executes your custom rollback/compensation callback, with full support for nested transactions (`SAVEPOINT`).
- 🔌 **Smart Connection Pool & Multi-Connection:** Work with multiple databases simultaneously (e.g., PostgreSQL as primary + SQLite/MySQL for audit logs or legacy ERP) — including cross-connection relationships between Models!

---

## 📖 Table of Contents

1. [Supported Databases](#-1-supported-databases)
2. [Installation](#-2-installation)
3. [Configuring Each Database](#-3-configuring-each-database)
4. [All Ways to Use Lumy (Instant, Structured & Custom SQL)](#-4-all-ways-to-use-lumy)
5. [CRUD Operations & Multi-Dialect Upsert](#-5-crud-operations--multi-dialect-upsert)
6. [Queries, Standardized Pagination (`data` / `meta`) & Filter Operators](#-6-queries-standardized-pagination-data--meta--filter-operators)
7. [Relationships (`1:1`, `1:N`, Nested & Cross-Connection)](#-7-relationships-11-1n-nested--cross-connection)
8. [Joins, Aliases & Aggregations (`GROUP BY` / `HAVING`)](#-8-joins-aliases--aggregations-group-by--having)
9. [Transactions (All Usage Patterns)](#-9-transactions-all-usage-patterns)
10. [Multiple Connections & Pool Management](#-10-multiple-connections--pool-management)
11. [SQL Migrations (All Ways to Run)](#-11-sql-migrations-all-ways-to-run)
12. [Security & Best Practices](#-12-security--best-practices)
13. [Runnable Examples & Official Links](#-13-runnable-examples--official-links)

---

## 🗄️ 1. Supported Databases

Lumy natively abstracts **5 relational database families**:

| Database | Accepted `driver` values | PHP Extension Used | Pagination Syntax | `upsert()` Syntax | Savepoints |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **MySQL / MariaDB** | `'mysql'`, `'mariadb'`, `'pdo_mysql'` | `ext-mysqli` (default) or `ext-pdo_mysql` (fallback/explicit) | `LIMIT ? OFFSET ?` | `INSERT ... ON DUPLICATE KEY UPDATE` | `SAVEPOINT lumy_sp_N` |
| **PostgreSQL** | `'postgresql'`, `'pgsql'`, `'postgres'` | `ext-pdo_pgsql` | `LIMIT ? OFFSET ?` | `INSERT ... ON CONFLICT (...) DO UPDATE` | `SAVEPOINT lumy_sp_N` |
| **SQLite** | `'sqlite'` | `ext-pdo_sqlite` | `LIMIT ? OFFSET ?` | `INSERT ... ON CONFLICT (...) DO UPDATE` | `SAVEPOINT lumy_sp_N` |
| **SQL Server** | `'sqlserver'`, `'sqlsrv'`, `'mssql'` | `ext-pdo_sqlsrv` | `OFFSET ? ROWS FETCH NEXT ? ROWS ONLY` | `MERGE INTO ... WITH (HOLDLOCK)` | `SAVE TRANSACTION lumy_sp_N` |
| **Firebird / InterBase** | `'firebird'`, `'ibase'`, `'pdo_firebird'` | `ext-interbase` (`ibase`) or `ext-pdo_firebird` (auto fallback on PHP 8+) | `ROWS ? TO ?` (1-based) | `UPDATE OR INSERT INTO ... MATCHING (...)` | `SAVEPOINT lumy_sp_N` |

---

## 📦 2. Installation

Requires **PHP 8.2 or higher** (fully compatible with PHP 8.2, 8.3, 8.4, and 8.5).

```bash
composer require lumynus/lumy
```

---

## ⚙️ 3. Configuring Each Database

Connections are registered in memory via `new Config(...)` or `Config::setConnection(...)`. Lumy uses *lazy connections*: the underlying database socket is only opened when the first query is executed.

### 3.1. MySQL & MariaDB (`mysql`, `mariadb`, `pdo_mysql`)
Uses `mysqli` by default with automatic `utf8mb4`. Falls back to `PDO` if `mysqli` is not installed or when `'pdo_mysql'` is specified. Supports custom port in `host` (`host:port`).

```php
use Lumynus\Lumy\Config;

new Config(
    driver: 'mysql', // 'mysql', 'mariadb', or 'pdo_mysql'
    host: '127.0.0.1:3306',
    user: 'app_user',
    password: 'secret_password',
    database: 'my_application',
    connectionIdentifier: 'default'
);
```

### 3.2. PostgreSQL (`postgresql`, `pgsql`, `postgres`)
Uses `pdo_pgsql`. Supports custom port in `host` (`127.0.0.1:5432`) and `sslmode` in `options`.

```php
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'postgresql', // 'postgresql', 'pgsql', or 'postgres'
    host: '127.0.0.1:5432',
    user: 'postgres',
    password: 'secret_password',
    database: 'my_application'
);
```

### 3.3. SQLite (`sqlite` — In-Memory or File)
Uses `pdo_sqlite`. Does not require `host`, `user`, or `password`.

```php
// In-memory (great for automated tests and prototyping)
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'sqlite',
    database: ':memory:'
);

// File-based database
Config::setConnection(
    connectionIdentifier: 'local_file',
    driver: 'sqlite',
    database: __DIR__ . '/storage/database.sqlite'
);
```

### 3.4. Microsoft SQL Server (`sqlserver`, `sqlsrv`, `mssql`)
Uses `pdo_sqlsrv`. Ports passed as `127.0.0.1:1433` are automatically converted to `Server=127.0.0.1,1433`.

```php
Config::setConnection(
    connectionIdentifier: 'erp',
    driver: 'sqlserver', // 'sqlserver', 'sqlsrv', or 'mssql'
    host: '127.0.0.1:1433',
    user: 'sa',
    password: 'StrongPassword!123',
    database: 'erp_production'
);
```

### 3.5. Firebird / InterBase (`firebird`, `ibase`, `pdo_firebird`)
Uses `ibase` when available or automatically falls back to `pdo_firebird` on PHP 8+, normalizing returned column names to lowercase and converting `host:3050` into `host/3050:database`.

```php
Config::setConnection(
    connectionIdentifier: 'firebird_db',
    driver: 'firebird', // 'firebird', 'ibase', or 'pdo_firebird'
    host: '127.0.0.1:3050', // or '' for direct local file access
    user: 'SYSDBA',
    password: 'masterkey',
    database: '/var/lib/firebird/data/system.fdb'
);
```

### 3.6. Advanced Options (TLS/SSL & PDO Attributes)
The `options` argument lets you configure SSL and driver-specific attributes:
- **PDO (`PdoDriver`)**: accepts integer `PDO::ATTR_*` constants and, on PostgreSQL, `'sslmode'` (`disable`, `allow`, `prefer`, `require`, `verify-ca`, `verify-full`).
- **MySQLi (`MysqlDriver`)**: accepts `'ssl' => ['ca' => ..., 'cert' => ..., 'key' => ...]`.

```php
Config::setConnection(
    connectionIdentifier: 'pg_secure',
    driver: 'postgresql',
    host: 'db.lumynus.dev:5432',
    user: 'app_user',
    password: 'secret_password',
    database: 'production_db',
    options: ['sslmode' => 'require']
);
```

---

## 🚀 4. All Ways to Use Lumy

Lumy provides **3 complementary ways** to interact with your database:

### Way 1: Instant Table Access with `Model` (No class creation required)
Perfect for quick operations, scripts, auxiliary tables, or when you don't want to create a class for every single table:

```php
use Lumynus\Lumy\Model;

$users = new Model('users'); // uses the 'default' connection
// For another connection: $audit = new Model('audit_logs', 'audit');

// 1. Insert a record
$id = $users->insert([
    'full_name' => 'Ana Silva',
    'email'     => 'ana@lumynus.dev',
    'status'    => 'active',
]);

// 2. Fetch a single record (returns associative array or null)
$user = $users->get(
    filters: ['id' => $id],
    camelCaseTransform: true
);
// Result: ['id' => 1, 'fullName' => 'Ana Silva', 'email' => 'ana@lumynus.dev', 'status' => 'active']

// 3. Fetch multiple records with filters and pagination (returns 'data' and 'meta')
$result = $users->select(
    filters: ['status' => 'active'],
    orderBy: 'id DESC',
    page: 1,
    perPage: 10,
    camelCaseTransform: true
);
// $result['data'] -> rows for the current page
// $result['meta'] -> ['currentPage' => 1, 'previousPage' => 1, 'perPage' => 10, 'nextPage' => 1, 'totalRecords' => 1, 'totalPages' => 1]
```

### Way 2: Subclassing `AbstractModel` (Recommended for Domain & APIs)
Allows you to map columns (`$fields`), hide sensitive columns by default (`'select' => false`), declare relationships (`$relationships`), joins (`$joins`), and encapsulate domain logic:

```php
use Lumynus\Lumy\AbstractModel;

class UserModel extends AbstractModel
{
    protected string $table = 'users';

    /**
     * Field Map (Whitelist):
     * - The key is the logical application field name (in snake_case).
     * - 'db' is the real database column (or SQL expression).
     * - 'select' controls whether the column is automatically projected in select().
     */
    protected array $fields = [
        'id'            => ['db' => 'users.id', 'select' => true],
        'full_name'     => ['db' => 'users.full_name', 'select' => true],
        'email'         => ['db' => 'users.email', 'select' => true],
        'status'        => ['db' => 'users.status', 'select' => true],
        'password_hash' => ['db' => 'users.password_hash', 'select' => false], // Hidden by default!
    ];

    public function findActiveByEmail(string $email): ?array
    {
        return $this->getOne(
            filters: [
                'email'  => $email,
                'status' => 'active',
            ],
            camelCaseTransform: true
        );
    }
}
```

### Way 3: Parameterized Custom SQL Queries (`customQuery`)
Inside any `AbstractModel` subclass, you can call the protected method `customQuery(string $query, array $params = [], string|array $types = '')` to run arbitrary SQL with prepared statements:

```php
class ReportModel extends AbstractModel
{
    protected string $table = 'orders';

    public function revenueByPeriod(string $startDate, string $endDate): array
    {
        $result = $this->customQuery(
            'SELECT status, COUNT(id) AS total_orders, SUM(amount) AS revenue
             FROM orders
             WHERE created_at BETWEEN ? AND ?
             GROUP BY status',
            [$startDate, $endDate]
        );

        return is_array($result) ? $result : [];
    }
}
```

---

## ✍️ 5. CRUD Operations & Multi-Dialect Upsert

Every Model (`Model` and `AbstractModel` subclasses) provides the following methods:

### 5.1. `insert(array $data): int`
Inserts a record via prepared statement and returns the generated ID (`DateTimeInterface` instances are automatically formatted as `Y-m-d H:i:s`).

```php
$id = $userModel->insert([
    'full_name'  => 'Carlos Eduardo',
    'email'      => 'carlos@lumynus.dev',
    'created_at' => new \DateTimeImmutable(),
]);
```

### 5.2. `upsert(array $data, array $uniqueBy): bool`
Inserts a new record or automatically updates it if a row matching `$uniqueBy` already exists. Lumy generates the native SQL for each database (`ON DUPLICATE KEY UPDATE`, `ON CONFLICT DO UPDATE`, `MERGE`, or `UPDATE OR INSERT`):

```php
$userModel->upsert(
    data: [
        'email'     => 'carlos@lumynus.dev',
        'full_name' => 'Carlos E. Santos',
        'status'    => 'active',
    ],
    uniqueBy: ['email']
);
```

### 5.3. `update(array $data, array|string $where, array $whereParams = []): int`
Updates matching records and returns the number of affected rows. Empty conditions throw an exception to protect the table against accidental full-table updates:

```php
// 1. With a filter array (safe and expressive)
$affected = $userModel->update(
    data: ['full_name' => 'Carlos Santos'],
    where: ['id' => 10, 'status' => 'active']
);

// 2. With a parameterized SQL WHERE clause
$affected = $userModel->update(
    data: ['status' => 'inactive'],
    where: 'WHERE last_login < ? AND role <> ?',
    whereParams: ['2025-01-01', 'admin']
);
```

### 5.4. `delete(array|string $where, array $whereParams = []): int`
Deletes matching records and returns the number of affected rows (also blocks empty `where` clauses):

```php
// 1. With a filter array
$deleted = $userModel->delete(['id' => 10]);

// 2. With a parameterized SQL expression
$deleted = $userModel->delete(
    '(status = ? OR status = ?) AND verified_at IS NULL',
    ['spam', 'expired']
);
```

---

## 🔍 6. Queries, Standardized Pagination (`data` / `meta`) & Filter Operators

### 6.1. Standardized `select()` Return Format

In both `Model` and any `AbstractModel` subclass, `select()` always returns a standardized structure containing `data` (the rows of the current page) and `meta` (automatically calculated pagination metadata):

```php
[
    'data' => [
        ['id' => 1, 'fullName' => 'Ana Silva', 'email' => 'ana@lumynus.dev'],
        // ...
    ],
    'meta' => [
        'currentPage'  => 1,     // Current page (default: 1)
        'previousPage' => 1,     // Previous page (stays 1 on page 1; returns 2 on page 3)
        'perPage'      => 10,    // Records per page (default when omitted: 100)
        'nextPage'     => 2,     // Next page (automatically capped at totalPages)
        'totalRecords' => 15000, // Total matching records in the database (without LIMIT)
        'totalPages'   => 1500,  // Total pages calculated: ceil(totalRecords / perPage)
    ],
]
```

> **Automatic Default Pagination (`perPage = 100` and `currentPage = 1`):**
> If you call `$model->select()` without passing pagination parameters (`page`, `perPage`, `limit`, or `offset`), Lumy automatically defaults to **page `1`** and **`100` records per page**, counts the matching records in the database (`totalRecords`), and calculates all `meta` fields for you!

### 6.2. How to Paginate in `select()` (By `page`/`perPage` or `offset`/`limit`)

You can paginate either by passing `page` and `perPage` (`offset` is automatically calculated as `($page - 1) * $perPage`) or by passing `offset` and `limit`:

```php
// 1. Without passing pagination -> automatically defaults to page: 1 and perPage: 100
$result = $userModel->select(
    filters: ['status' => 'active'],
    camelCaseTransform: true
);

// 2. Paginating by page number (page) and items per page (perPage)
$result = $userModel->select(
    filters: [
        'status'                      => ['active', 'trial'],
        'age BETWEEN'                 => [18, 65],
        'fullName LIKE || email LIKE' => 'silva',
        '!deletedAt'                  => null,
    ],
    orderBy: 'id DESC',
    page: 2,                               // Automatically calculates offset = 20!
    perPage: 20,                           // Equivalent to limit = 20
    fielders: ['id', 'fullName', 'email'], // Projects only these mapped columns
    camelCaseTransform: true,              // Converts filter keys and output keys to camelCase
    with: ['profile', 'orders']            // Eager-loads relationships in batches
);

// 3. Paginating with traditional offset and limit (currentPage is calculated automatically)
$result = $userModel->select(
    offset: 40,
    limit: 20 // currentPage will be calculated as 3!
);

// Accessing rows and metadata:
$rows = $result['data'];
$meta = $result['meta'];
```

### 6.3. Complete Filter Operators Reference (`select`, `update` & `delete`)

| Filter Array Syntax | Generated SQL | Description |
| :--- | :--- | :--- |
| `'age' => 18` or `'age =' => 18` | `age = ?` | Exact equality |
| `'!status' => 'blocked'` or `'status !=' => 'blocked'` | `status <> ?` / `status != ?` | Not equal to |
| `'age >' => 18`, `'age >=' => 18` | `age > ?`, `age >= ?` | Greater than / Greater than or equal |
| `'age <' => 65`, `'age <=' => 65` | `age < ?`, `age <= ?` | Less than / Less than or equal |
| `'name LIKE' => 'Ana'` | `name LIKE ? ESCAPE ...` (`'%Ana%'`) | Contains substring (escapes accidental `%` and `_`) |
| `'name LIKE' => 'Ana%'` | `name LIKE ? ESCAPE ...` (`'Ana%'`) | Custom wildcard pattern when `%` is provided |
| `'!name LIKE' => 'Ana'` or `'name NOT LIKE' => 'Ana'` | `name NOT LIKE ? ESCAPE ...` | Does not contain substring |
| `'status' => ['active', 'pending']` or `'status IN' => [...]` | `status IN (?, ?)` | Value in list |
| `'!status' => ['blocked', 'banned']` or `'status NOT IN' => [...]` | `status NOT IN (?, ?)` | Value not in list |
| `'age BETWEEN' => [18, 65]` | `age BETWEEN ? AND ?` | Within inclusive range |
| `'!age BETWEEN' => [18, 65]` or `'age NOT BETWEEN' => [18, 65]` | `age NOT BETWEEN ? AND ?` | Outside range |
| `'email' => null` or `'email IS' => null` | `email IS NULL` | Null check |
| `'!email' => null` or `'email IS NOT' => null` | `email IS NOT NULL` | Not null check |
| `'email \|\| username' => 'ana'` | `(email = ? OR username = ?)` | Logical `OR` grouping across columns |
| `'fullName LIKE \|\| email LIKE' => 'ana'` | `(full_name LIKE ? OR email LIKE ?)` | `OR` combined with operators and `camelCase` |

---

## 🔗 7. Relationships (`1:1`, `1:N`, Nested & Cross-Connection)

Lumy resolves relationships via *Batch Eager Loading*: it first fetches the exact page from the parent table and then loads all child records for that page using a single `WHERE foreign_key IN (...)` query per relationship. This **eliminates N+1 queries** and **prevents pagination `LIMIT` corruption** caused by `JOIN` row multiplication.

```php
class CustomerModel extends AbstractModel
{
    protected string $table = 'customers';

    protected array $fields = [
        'id'            => ['db' => 'customers.id', 'select' => true],
        'customer_name' => ['db' => 'customers.customer_name', 'select' => true],
    ];

    protected array $relationships = [
        // 1:1 Relationship (many: false -> returns associative array or null)
        'address' => [
            'model'      => CustomerAddressModel::class,
            'localKey'   => 'id',
            'foreignKey' => 'customer_id',
            'many'       => false,
        ],
        // 1:N Relationship (many: true -> returns list of associative arrays)
        'orders' => [
            'model'      => OrderModel::class,
            'localKey'   => 'id',
            'foreignKey' => 'customer_id',
            'many'       => true,
            // Optional: fetch related rows from ANOTHER database connection!
            // 'connectionIdentifier' => 'orders_db',
        ],
    ];
}
```

Loading direct and nested relationships:

```php
$customers = (new CustomerModel())->select(
    limit: 10,
    camelCaseTransform: true,
    with: [
        'address',
        'orders' => ['items'], // Loads orders and, inside each order, its items!
    ]
);
```

---

## 📊 8. Joins, Aliases & Aggregations (`GROUP BY` / `HAVING`)

For analytical reports and aggregated queries, define `$alias`, `$joins`, SQL aggregate functions in `$fields` (`COUNT`, `SUM`, `AVG`, `MIN`, `MAX`), and `$groupBy`. When you filter by an aggregate field in `filters`, Lumy automatically places that condition in `HAVING` (both in the main query and in the `totalRecords` count query):

```php
class SaleReportModel extends AbstractModel
{
    protected string $table = 'sales';
    protected string $alias = 's';

    protected array $fields = [
        'category_id'   => ['db' => 'c.id', 'select' => true],
        'category_name' => ['db' => 'c.name', 'select' => true],
        'total_orders'  => ['db' => 'COUNT(s.id)', 'select' => true],
        'total_revenue' => ['db' => 'SUM(s.amount)', 'select' => true],
    ];

    protected ?array $joins = [
        'INNER JOIN categories c ON s.category_id = c.id',
    ];

    protected array $groupBy = ['c.id', 'c.name'];
}

$report = (new SaleReportModel())->select(
    filters: ['total_orders >=' => 5], // Automatically placed in HAVING COUNT(s.id) >= ?
    orderBy: 'total_revenue DESC'
);
```

---

## 🔄 9. Transactions (All Usage Patterns)

Lumy provides the **global `transaction()` function** (as well as `\Lumynus\Lumy\transaction()`, `LumyConnection::runTransaction()`, and `$model->transaction()`):

```php
transaction(
    callable $transaction,
    ?callable $rollback = null,
    string $connectionIdentifier = 'default'
): mixed
```

### 9.1. Pattern 1: Default Automatic Rollback (`$rollback = null`)
When you omit the second parameter (`$rollback = null`), Lumy starts the transaction, executes your callback, and commits. If any exception is thrown, Lumy **automatically calls `rollBack()`** on the database and rethrows the exception:

```php
transaction(function () use ($walletModel, $logModel): void {
    $walletModel->update(['balance' => 700.00], ['id' => 1]);
    $walletModel->update(['balance' => 550.00], ['id' => 2]);
    $logModel->insert(['status' => 'SUCCESS', 'message' => 'Transfer completed']);
});
```

### 9.2. Pattern 2: Custom User-Defined Rollback (`$rollback` provided)
When you provide a `$rollback` callback, if any error occurs inside `$transaction`:
1. Lumy **immediately rolls back the pending database transaction (`rollBack()`)** so no partial changes are persisted;
2. Next, Lumy **executes your custom `$rollback` callback** (allowing you to write audit logs, run compensating actions, or return a fallback response) and returns its result:

```php
$result = transaction(
    transaction: function () use ($walletModel): array {
        $walletModel->update(['balance' => 0.00], ['id' => 1]);
        throw new \RuntimeException('Insufficient balance to complete transfer.');
    },
    rollback: function (\Throwable $e) use ($logModel): array {
        // Queries inside $transaction have already been rolled back!
        // Now we run our custom rollback logic:
        $logModel->insert([
            'status'  => 'ROLLED_BACK',
            'message' => 'Custom rollback: ' . $e->getMessage(),
        ]);

        return [
            'ok'    => false,
            'error' => $e->getMessage(),
        ];
    }
);
```

#### Accepted `$rollback` callback signatures:
Lumy automatically inspects your callback's parameter count:
- `function (): mixed` *(0 parameters)*
- `function (\Throwable $e): mixed` *(receives the thrown exception)*
- `function (\Throwable $e, DatabaseDriverInterface $connection): mixed` *(receives the exception and the connection driver)*

### 9.3. Pattern 3: Transactions on Named Connections (`$connectionIdentifier`)
To run a transaction on another connection registered in `Config` (e.g. `'erp'` or `'audit'`), pass the 3rd argument:

```php
transaction(
    transaction: function () use ($erpModel): void {
        $erpModel->insert(['order_code' => 'ORD-500', 'total' => 980.00]);
    },
    rollback: function (\Throwable $e): void {
        error_log('ERP database rollback: ' . $e->getMessage());
    },
    connectionIdentifier: 'erp'
);
```

### 9.4. Pattern 4: Directly on a Model Instance (`$model->transaction()`)
```php
$walletModel->transaction(
    function (WalletModel $model) use ($fromId, $toId): void {
        $model->update(['balance' => 700.00], ['id' => $fromId]);
        $model->update(['balance' => 550.00], ['id' => $toId]);
    },
    function (\Throwable $e, WalletModel $model): void {
        // Optional custom rollback callback receiving the exception and the Model
    }
);
```

### 9.5. Pattern 5: Nested Transactions (Automatic Savepoints)
Calling `transaction()` inside another active `transaction()` automatically creates a `SAVEPOINT` (`SAVEPOINT lumy_sp_N` or `SAVE TRANSACTION lumy_sp_N` on SQL Server). If the inner block fails, **only the inner savepoint is rolled back**, keeping the outer transaction intact:

```php
transaction(function () use ($walletModel, $logModel): void {
    $walletModel->insert(['owner' => 'Alice', 'balance' => 1000.00]);

    // Inner transaction isolated inside a SAVEPOINT:
    transaction(
        transaction: function () use ($walletModel): void {
            $walletModel->insert(['owner' => 'BonusTemp', 'balance' => 50.00]);
            throw new \RuntimeException('Expired bonus: revert only the savepoint');
        },
        rollback: function (\Throwable $e) use ($logModel): void {
            $logModel->insert(['status' => 'SAVEPOINT_ROLLED_BACK', 'message' => $e->getMessage()]);
        }
    );

    // Both Alice and the SAVEPOINT_ROLLED_BACK log are committed by the outer transaction!
});
```

### 9.6. Pattern 6: Manual Transaction Control (`beginTransaction`, `commit`, `rollBack`)
```php
try {
    $walletModel->beginTransaction();
    $walletModel->update(['balance' => 500.00], ['id' => 1]);
    $walletModel->commit();
} catch (\Throwable $e) {
    $walletModel->rollBack();
    throw $e;
}
```

---

## 🔌 10. Multiple Connections & Pool Management

`LumyConnection` maintains an in-memory pool of reusable connections throughout the PHP process lifecycle, indexed by a SHA-256 hash of the credentials and `connectionIdentifier`.

```php
use Lumynus\Lumy\Connections\LumyConnection;

// Retrieve the driver instance for a connection registered in Config
$driver = LumyConnection::connectionFor('default');

// Inspect active pooled connections
$activeCount = LumyConnection::poolSize();
$identifiers = LumyConnection::identifiers();

// Close a specific connection by identifier
LumyConnection::closeByIdentifier('audit');

// Close all connections in the pool
LumyConnection::closeAll();
```

---

## 🗂️ 11. SQL Migrations (All Ways to Run)

Lumy includes a native **SQL Migrations Runner** (`Lumynus\Lumy\Migrations` and `bin/migrate.php`) that executes pending `.sql` files in natural sort order (`001_...`, `002_...`) and tracks applied versions in the `lumy_migrations` table.

### 11.1. How does the CLI find `Config` if it can live anywhere in the project?

When you run a CLI command (`php vendor/bin/migrate.php default` or `php bin/migrate.php default`), PHP starts a brand-new process. Since `Config` stores connections in memory for that process, the CLI needs to locate and execute where your connections (`new Config(...)` or `Config::setConnection(...)`) were defined.

So that you are **never locked into a rigid folder or class structure**, `bin/migrate.php` locates your configuration in **3 automatic steps**:

1. **Step 1 — Explicit Path (2nd CLI argument):**
   If you pass your config file path as the second argument (e.g., `php vendor/bin/migrate.php default app/Core/DatabaseConfig.php`), it loads that exact file.
2. **Step 2 — Conventional Project Root Locations:**
   If you omit the 2nd argument (`php vendor/bin/migrate.php default`), the CLI automatically checks common files:
   - `bootstrap.php`, `config.php`
   - `config/database.php`, `config/lumy.php`, `config/db.php`, `config/config.php`
   - `app/Config/database.php`, `app/Config/Database.php`, `src/Config/database.php`
   - `database/config.php`, `bootstrap/database.php`, `bootstrap/app.php`
3. **Step 3 — Auto-Discovery Across Any Folder, Model, or Class in the Project:**
   If your connection is still not registered after the steps above, `bin/migrate.php` **automatically scans `.php` files in your project** (skipping `vendor`, `node_modules`, `tests`, `public/index.php`, etc.), inspects the source text before loading it to find `Config::setConnection(...)` or `new Config(...)` calls, and registers the connection — **even if it lives inside a Model or Class!**

#### Supported Ways to Declare `Config` (All Recognized by the CLI):

```php
// ✅ Form A: In any config or bootstrap file (e.g., config/database.php or src/Infra/db.php)
use Lumynus\Lumy\Config;

Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'postgresql',
    host: '127.0.0.1:5432',
    user: 'postgres',
    password: 'secret',
    database: 'my_application'
);
```

```php
// ✅ Form B: At the top of a Model or Class file (outside the class { ... } block)
namespace App\Models;

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;

new Config(driver: 'mysql', host: '127.0.0.1', user: 'root', password: '', database: 'app');

class UserModel extends AbstractModel
{
    protected string $table = 'users';
}
```

```php
// ✅ Form C: Inside __construct() of a Model or BaseModel (invoked via Reflection by the CLI)
namespace App\Models;

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;

abstract class BaseModel extends AbstractModel
{
    public function __construct(string $connectionIdentifier = 'default')
    {
        new Config(
            driver: 'mysql',
            host: '127.0.0.1',
            user: 'root',
            password: '',
            database: 'app',
            connectionIdentifier: 'default'
        );

        parent::__construct($connectionIdentifier);
    }
}
```

```php
// ✅ Form D: Inside a method of any Configuration Class (e.g., Database::init())
namespace App\Core;

use Lumynus\Lumy\Config;

class Database
{
    public static function init(): void
    {
        Config::setConnection(
            connectionIdentifier: 'default',
            driver: 'sqlite',
            database: __DIR__ . '/../../storage/app.sqlite'
        );
    }
}
```

```php
// ✅ Form E: In a file that returns a Closure / anonymous function
use Lumynus\Lumy\Config;

return static function (): void {
    Config::setConnection('default', driver: 'mysql', host: '127.0.0.1', user: 'root', password: '', database: 'app');
};
```

---

### 11.2. Preparing `.sql` Migration Files

Create your migrations directory (by default `database/migrations/` in your project root) with numbered `.sql` files:
```text
database/
└── migrations/
    ├── 001_create_users.sql
    └── 002_create_orders.sql
```

Each `.sql` file can contain multiple semicolon-separated statements as well as SQL comments (`--`, `#`, `/* ... */`):
```sql
-- database/migrations/001_create_users.sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE
);

CREATE INDEX idx_users_email ON users (email);
```

---

### 11.3. All Ways to Execute Migrations

You can run migrations either **via Terminal (CLI)** or **programmatically via PHP**:

#### Way 1: Via CLI with Auto-Discovery (without specifying where `Config` is located)
Simply pass the connection identifier (`default`). Lumy will automatically locate the file where the `default` connection `Config` is declared:
```bash
# When installed via Composer in your project (Composer binary):
php vendor/bin/migrate.php default

# Or calling the script directly:
php bin/migrate.php default
```

#### Way 2: Via CLI Pointing to Your `Config` File (2nd argument)
If you prefer to explicitly specify the file where your connections are defined, pass it as the **2nd argument**:
```bash
php vendor/bin/migrate.php default src/Infra/Database/connections.php
```

#### Way 3: Via CLI Overriding the Migrations Folder (3rd argument)
To execute migrations from a custom folder (e.g., an isolated module or audit database), pass the directory path as the **3rd argument**:
```bash
php vendor/bin/migrate.php audit config/database.php database/migrations/audit
```

> **Full CLI syntax:**
> ```bash
> php vendor/bin/migrate.php <connectionIdentifier> [path/to/config.php] [path/to/migrations-dir]
> ```
> **Output & exit codes (CI/CD and Docker friendly):**
> - Prints `Applied: 001_create_users.sql` for each newly applied file or `No pending migrations.` when already up to date;
> - Exit code `0`: success; Exit code `1`: connection or SQL execution error; Exit code `2`: invalid arguments or connection not found.

#### Way 4: Via `composer.json` Scripts
Add shortcuts in your project's `composer.json`:
```json
{
    "scripts": {
        "migrate": "php vendor/bin/migrate.php default",
        "migrate:audit": "php vendor/bin/migrate.php audit"
    }
}
```
Then run:
```bash
composer migrate
composer migrate:audit
```

#### Way 5: Programmatically in PHP (Using `migrationsPath` from `Config`)
When running inside your PHP application, `Config` is already loaded. Instantiate `Migrations` with the `connectionIdentifier` and call `run()`:
```php
use Lumynus\Lumy\Config;
use Lumynus\Lumy\Migrations;

Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'sqlite',
    database: __DIR__ . '/storage/database.sqlite',
    migrationsPath: __DIR__ . '/database/migrations'
);

$runner = new Migrations('default');
$appliedFiles = $runner->run(); // Returns list of applied filenames (e.g., ['001_create_users.sql'])
```

#### Way 6: Programmatically in PHP (Passing the Directory to the Constructor)
Ideal for automated test suites, web installers, or multi-tenant provisioning:
```php
use Lumynus\Lumy\Migrations;

$runner = new Migrations(
    connectionIdentifier: 'default',
    migrationsDirectory: __DIR__ . '/database/migrations'
);

$appliedFiles = $runner->run();
// 1st run: ['001_create_users.sql', '002_create_orders.sql']
// 2nd run: [] (idempotent — skips files already recorded in lumy_migrations)
```

---

### 11.4. Directory Resolution Order & Migration Safety Features

Lumy resolves which migrations folder to use in the following order:
1. The directory explicitly passed to `new Migrations($id, $migrationsDirectory)` (or the 3rd CLI argument);
2. The `migrationsPath` configured in `Config::setConnection(...)` for that connection;
3. The default `database/migrations/` folder in the current project root.

**Safety & Concurrency in the Runner:**
- **Guaranteed Idempotency:** Automatically creates and queries the `lumy_migrations` table using the connected database's dialect;
- **Full SQL Statement Parser:** Splits multiple statements by `;` without breaking semicolons inside strings, comments, or PostgreSQL `$$ ... $$` dollar-quoted blocks;
- **Per-File Transactions:** On SQLite, PostgreSQL, SQL Server, and Firebird, each `.sql` file runs inside an isolated transaction (if any statement fails, the entire file is rolled back);
- **Global Concurrency Lock:** Prevents multiple containers/processes from running migrations simultaneously during deployment using `GET_LOCK` (MySQL/MariaDB), `pg_try_advisory_lock` (PostgreSQL), and `sp_getapplock` (SQL Server).

---

## 🛡️ 12. Security & Best Practices

1. **Native Prepared Statements on 100% of Values:** No value in `insert`, `upsert`, `update`, `delete`, or `select` is ever interpolated into SQL strings. On MySQL, `EMULATE_PREPARES` and `MULTI_STATEMENTS` are disabled.
2. **Strict SQL Identifier Validation:** Table names, columns, and aliases are validated against `/\A[A-Za-z_][A-Za-z0-9_]*\z/`, preventing SQL Injection via array keys.
3. **Field Map Whitelist (`$fields`):** When an `AbstractModel` declares `$fields`, any attempt to filter or sort by an unmapped column throws an `InvalidArgumentException`.
4. **Credential Redaction (`#[\SensitiveParameter]`):** Database passwords never appear in PHP stack traces or exception logs.
5. **No Unfiltered `UPDATE` or `DELETE`:** Empty `WHERE` clauses are rejected by default.
6. **Automatic `LIKE` Escaping:** Accidental `%`, `_`, and `\` wildcards in user input are escaped with a dialect-specific `ESCAPE` clause.

---

## 💻 13. Runnable Examples & Official Links

Explore the [`examples/`](examples/README.md) directory to run self-contained scripts (using in-memory SQLite, with zero external database setup required):

```bash
php examples/basic-usage.php
php examples/named-connections.php
php examples/crud-and-upsert.php
php examples/filters-and-operators.php
php examples/relationships.php
php examples/joins-and-aggregations.php
php examples/transactions.php
php examples/migrations-usage.php
php examples/all-databases-config.php
```

To run the automated test suite:

```bash
php tests/example1.php
```

### Detailed Repository Guides
- 📘 [Complete Transactions Guide (`docs/Transactions.md`)](docs/Transactions.md)
- 🗄️ [Complete Databases Guide (`docs/Databases.md`)](docs/Databases.md)
- 🧩 [Models Reference (`docs/Model.md`)](docs/Model.md)
- 📦 [Migrations Reference (`docs/Migrations.md`)](docs/Migrations.md)

---

<div align="center">

**Official Lumy Documentation:** [https://lumy.lumynus.dev/](https://lumy.lumynus.dev/)  
**Lumynus Framework Ecosystem:** [https://lumynus.dev/](https://lumynus.dev/)

Crafted with a focus on simplicity, performance, and elegance by **Weleny Santos** & **Lumynus**.  
Distributed under the **MIT License**.

</div>

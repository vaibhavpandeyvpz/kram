# vaibhavpandeyvpz/kram

[![Latest Version](https://img.shields.io/packagist/v/vaibhavpandeyvpz/kram.svg?style=flat-square)](https://packagist.org/packages/vaibhavpandeyvpz/kram)
[![PHP Version](https://img.shields.io/packagist/php-v/vaibhavpandeyvpz/kram.svg?style=flat-square)](https://packagist.org/packages/vaibhavpandeyvpz/kram)
[![Build Status](https://img.shields.io/github/actions/workflow/status/vaibhavpandeyvpz/kram/tests.yml?branch=main&style=flat-square)](https://github.com/vaibhavpandeyvpz/kram/actions)
[![License](https://img.shields.io/packagist/l/vaibhavpandeyvpz/kram.svg?style=flat-square)](LICENSE)

**Kram** (क्रम) is a simple and deterministic database migration and versioning library built on top of [databoss](https://github.com/vaibhavpandeyvpz/databoss). It provides a clean, straightforward API for managing database schema changes across MySQL/MariaDB, PostgreSQL, SQLite, and Microsoft SQL Server databases.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Migration Files](#migration-files)
- [Usage](#usage)
- [Migration Versioning](#migration-versioning)
- [How It Works](#how-it-works)
- [Custom Table Name](#custom-table-name)
- [Database Support](#database-support)
- [Error Handling](#error-handling)
- [Advanced Features](#advanced-features)
- [Best Practices](#best-practices)
- [Testing](#testing)
- [API Reference](#api-reference)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## Features

- **Simple and Deterministic**: Migrations are executed in a predictable order based on version numbers
- **Multi-database Support**: Works with MySQL/MariaDB, PostgreSQL, SQLite, and Microsoft SQL Server (via databoss)
- **Flexible Migration Types**: Supports both SQL file-based and PHP class-based migrations
- **Exception-based Error Handling**: Clean exception-based error handling for migration failures
- **Version Tracking**: Automatically tracks executed migrations in a database table
- **Rollback Support**: Easily rollback migrations to a specific version or rollback the last N migrations
- **Type-safe**: Full PHP 8.2+ type declarations throughout
- **Modern PHP**: Built with enums, match expressions, readonly properties, and more

## Requirements

- PHP >= 8.2
- [vaibhavpandeyvpz/databoss](https://github.com/vaibhavpandeyvpz/databoss) ^2.1 (automatically installed as dependency)

## Installation

```bash
composer require vaibhavpandeyvpz/kram
```

## Quick Start

### Basic Setup

```php
<?php

use Databoss\Connection;
use Kram\MigrationManager;

// Create a database connection using databoss
$connection = new Connection([
    Connection::OPT_DATABASE => 'mydb',
    Connection::OPT_USERNAME => 'root',
    Connection::OPT_PASSWORD => 'password',
]);

// Create migration manager
$manager = new MigrationManager($connection, __DIR__ . '/migrations');

// Run pending migrations
$result = $manager->migrate();
if ($result->success) {
    echo $result->message . "\n";
} else {
    echo "Error: " . $result->message . "\n";
}
```

## Migration Files

Kram supports two types of migrations: SQL file-based and PHP class-based. Both types are discovered automatically from the migrations directory.

### SQL File-based Migrations

Kram supports SQL file-based migrations with separate files for up and down operations.

**File naming convention:**

- `{version}_{name}.up.sql` - Forward migration (required)
- `{version}_{name}.down.sql` - Rollback migration (optional)

**Example:**

`20240101120000_create_users.up.sql`:

```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL
);
```

**Multiple Statements:** SQL migrations can contain multiple statements separated by semicolons. Kram automatically splits and executes them in order:

```sql
-- Create users table
CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(255));

-- Create posts table
CREATE TABLE posts (id INT PRIMARY KEY, user_id INT, FOREIGN KEY (user_id) REFERENCES users(id));
```

**Comments and Strings:** Kram properly handles SQL comments (`--` and `/* */`) and string literals, so semicolons within strings won't break statement splitting.

`20240101120000_create_users.down.sql`:

```sql
DROP TABLE users;
```

**Note:** Down migrations (`.down.sql` files) are optional. If a down file doesn't exist, rollback will succeed without executing any SQL.

### PHP Class-based Migrations

You can also create PHP class-based migrations for more complex operations.

**File naming convention:**

- `{version}_{Name}.php` - PHP migration class

**Example:**

`20240101120000_CreateUsers.php`:

```php
<?php

use Databoss\ConnectionInterface;
use Kram\MigrationInterface;

class CreateUsers implements MigrationInterface
{
    public function up(ConnectionInterface $connection): void
    {
        $connection->execute("
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL
            )
        ");
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute("DROP TABLE users");
    }
}
```

**Using DDL Methods (Recommended):**

With databoss 2.1+, you can use database-agnostic DDL methods instead of raw SQL:

```php
<?php

use Databoss\ConnectionInterface;
use Kram\MigrationInterface;

class CreateUsers implements MigrationInterface
{
    public function up(ConnectionInterface $connection): void
    {
        // Create table using databoss DDL methods
        // Column types are automatically translated for each database
        $connection->create('users', [
            'id' => [
                'type' => 'INTEGER',
                'auto_increment' => true,
                'primary' => true,
            ],
            'name' => [
                'type' => 'VARCHAR(255)',
                'null' => false,
            ],
            'email' => [
                'type' => 'VARCHAR(255)',
                'null' => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        // Create a unique index
        $connection->unique('users', ['email'], 'unique_email');
    }

    public function down(ConnectionInterface $connection): void
    {
        // Drop the table
        $connection->drop('users');
    }
}
```

**Advanced DDL Operations:**

```php
public function up(ConnectionInterface $connection): void
{
    // Create table with composite primary key
    $connection->create('order_items', [
        'order_id' => ['type' => 'INTEGER', 'null' => false],
        'product_id' => ['type' => 'INTEGER', 'null' => false],
        'quantity' => ['type' => 'INTEGER', 'null' => false],
    ], ['order_id', 'product_id']);

    // Create indexes
    $connection->index('order_items', ['order_id']);
    $connection->index('order_items', ['product_id'], 'idx_product');

    // Create foreign key
    $connection->foreign('order_items', 'order_id', ['orders', 'id'], 'fk_order_items_order');
    $connection->foreign('order_items', 'product_id', ['products', 'id'], 'fk_order_items_product');
}

public function down(ConnectionInterface $connection): void
{
    // Drop foreign keys (drop table automatically removes them)
    $connection->drop('order_items');
}
```

**Benefits of DDL Methods:**

- **Database-agnostic**: Column types are automatically translated (e.g., `BOOLEAN` → `TINYINT(1)` for MySQL, `BOOLEAN` for PostgreSQL)
- **Cleaner syntax**: No need to write database-specific SQL
- **Type safety**: Better IDE support and fewer syntax errors
- **Consistent**: Same API works across MySQL, PostgreSQL, SQLite, and SQL Server

**Note:**

- For PHP migrations, the class name should match the filename (without extension), and it must implement `MigrationInterface`.
- If both SQL and PHP migrations exist for the same version, PHP migrations take precedence.
- You can mix raw SQL (`execute()`) and DDL methods in the same migration as needed.

## Usage

### Running Migrations

```php
// Run all pending migrations
$result = $manager->migrate();

if ($result->success) {
    echo "Success: " . $result->message . "\n";
    echo "Executed migrations: " . implode(', ', $result->executed) . "\n";
} else {
    echo "Error: " . $result->message . "\n";
}
```

### Rolling Back Migrations

```php
// Rollback the last migration
$result = $manager->rollback();

// Rollback the last N migrations
$result = $manager->rollbackTo(null, 3);

// Rollback to a specific version
$result = $manager->rollbackTo('20240101120000');
```

### Checking Migration Status

```php
// Get current migration status
$status = $manager->status();

echo "Executed migrations: " . count($status->executed) . "\n";
echo "Pending migrations: " . count($status->pending) . "\n";

foreach ($status->executed as $migration) {
    echo "✓ {$migration->version} - {$migration->name}\n";
}

foreach ($status->pending as $migration) {
    echo "○ {$migration->version} - {$migration->name}\n";
}

// Check for errors
if ($status->error !== null) {
    echo "Error: " . $status->error . "\n";
}
```

### Initializing the Migrations Table

The migrations tracking table is automatically created when needed, but you can also initialize it explicitly:

```php
// Initialize the migrations table (creates it if it doesn't exist)
$manager->initialize();
```

## Migration Versioning

Migration versions should be unique identifiers that sort lexicographically. Common approaches:

- **Timestamp-based**: `20240101120000` (YYYYMMDDHHMMSS format)
- **Sequential**: `0001`, `0002`, `0003`, etc.

The version is used to determine the execution order, so it's important that versions sort correctly.

## How It Works

1. **Migration Tracking**: Kram creates a `kram_migrations` table (or uses a custom name) to track executed migrations.

2. **Deterministic Execution**: Migrations are always executed in version order (ascending for migrate, descending for rollback).

3. **Migration Execution**: Migrations are executed directly without transaction wrappers. Note that DDL operations (CREATE TABLE, DROP TABLE, etc.) in MySQL auto-commit, so they cannot be rolled back. Each migration should be designed to be idempotent and safe to run.

4. **Version Detection**: Kram automatically detects which migrations have been executed by comparing migration files with the tracking table.

## Custom Table Name

You can customize the migrations tracking table name:

```php
$manager = new MigrationManager(
    $connection,
    __DIR__ . '/migrations',
    'my_migrations_table' // Custom table name
);
```

## Database Support

Kram works with all databases supported by databoss:

- **MySQL/MariaDB**: Full support
- **PostgreSQL**: Full support
- **SQLite**: Full support
- **Microsoft SQL Server**: Full support

The migrations tracking table is automatically created with the appropriate SQL syntax for each database driver.

## Error Handling

All migration operations return a `MigrationResult` object that indicates:

- `success`: Whether the operation succeeded
- `message`: Human-readable message
- `executed`: Array of executed migration versions
- `rolledBack`: Array of rolled back migration versions

If a migration fails, execution stops immediately. Any migrations that were successfully executed before the failure will remain in the tracking table. Note that DDL operations (CREATE TABLE, DROP TABLE, etc.) in MySQL auto-commit and cannot be rolled back, so each migration should be designed to be idempotent and safe to run.

**Example error handling:**

```php
$result = $manager->migrate();

if (!$result->success) {
    echo "Migration failed: " . $result->message . "\n";
    echo "Successfully executed before failure: " . implode(', ', $result->executed) . "\n";
}
```

## Advanced Features

### Migration Type Detection

You can check the type of a migration using the `MigrationType` enum:

```php
use Kram\MigrationType;

$migration = // ... get migration instance

if ($migration->type->isSql()) {
    echo "This is a SQL migration\n";
} elseif ($migration->type->isPhp()) {
    echo "This is a PHP migration\n";
}
```

### Direct Migration Execution

You can execute individual migrations directly:

```php
use Kram\Migration;
use Kram\MigrationType;

$migration = new Migration(
    '20240101120000',
    'Create Users',
    '/path/to/migration',
    MigrationType::SQL
);

// Execute up migration
$migration->up($connection);

// Execute down migration
$migration->down($connection);
```

### Using DDL Methods in PHP Migrations

With databoss 2.1+, PHP migrations can use database-agnostic DDL methods. This is especially useful when you need to support multiple database types:

**Available DDL Methods:**

- `create(?string $table = null, ?array $columns = null, ?array $primaryKey = null): bool` - Create database or table
- `drop(?string $table = null, ?string $column = null): bool` - Drop database, table, or column
- `modify(string $table, string $column, array $definition): bool` - Modify a column (MySQL, PostgreSQL, SQL Server only)
- `index(string $table, string|array $columns, ?string $indexName = null): bool` - Create an index
- `unique(string $table, string|array $columns, ?string $indexName = null): bool` - Create a unique index
- `foreign(string $table, string $column, array $references, ?string $constraintName = null): bool` - Create a foreign key
- `unindex(string $table, string|array $identifier): bool` - Drop an index

**Example:**

```php
public function up(ConnectionInterface $connection): void
{
    // Create table - column types are automatically translated
    $connection->create('products', [
        'id' => ['type' => 'INTEGER', 'auto_increment' => true, 'primary' => true],
        'name' => ['type' => 'VARCHAR(255)', 'null' => false],
        'price' => ['type' => 'DECIMAL(10,2)', 'null' => false],
        'active' => ['type' => 'BOOLEAN', 'null' => false], // Auto-translated per database
        'description' => ['type' => 'TEXT', 'null' => true],
    ]);

    // Create indexes
    $connection->index('products', ['name']);
    $connection->unique('products', ['name'], 'unique_product_name');

    // Modify column (MySQL, PostgreSQL, SQL Server only)
    // $connection->modify('products', 'name', ['type' => 'VARCHAR(500)']);
}

public function down(ConnectionInterface $connection): void
{
    $connection->drop('products');
}
```

**Column Type Translation:**

databoss automatically translates common types to database-specific equivalents:

- `BOOLEAN` → `TINYINT(1)` (MySQL), `BOOLEAN` (PostgreSQL), `INTEGER` (SQLite), `BIT` (SQL Server)
- `SERIAL`/`BIGSERIAL` → `INT AUTO_INCREMENT` (MySQL), `SERIAL`/`BIGSERIAL` (PostgreSQL), `INTEGER` (SQLite), `INT IDENTITY(1,1)` (SQL Server)
- `DATETIME` → `DATETIME` (MySQL), `TIMESTAMP` (PostgreSQL), `TEXT` (SQLite), `DATETIME2` (SQL Server)
- `JSON` → `JSON` (MySQL/PostgreSQL), `TEXT` (SQLite), `NVARCHAR(MAX)` (SQL Server)
- And many more...

See the [databoss documentation](https://github.com/vaibhavpandeyvpz/databoss) for the complete list of supported types and translations.

### Special Characters in Migration Names

Migration names can contain hyphens, underscores, and other characters. They will be normalized for display:

- `20240101120000_create-user-table.up.sql` → "Create User Table"
- `20240101120000_add_index_to_users.up.sql` → "Add Index To Users"

## Best Practices

1. **Use Timestamp Versions**: Timestamp-based versions (e.g., `20240101120000`) ensure chronological ordering and prevent conflicts.

2. **Always Provide Down Migrations**: For SQL migrations, down migrations (`.down.sql` files) are optional but recommended. For PHP migrations, always implement the `down()` method.

3. **Use DDL Methods for Multi-Database Support**: When writing PHP migrations that need to work across multiple database types, use databoss DDL methods (`create()`, `drop()`, `index()`, etc.) instead of raw SQL. These methods automatically handle database-specific differences.

4. **Test Migrations**: Test both up and down migrations before deploying to production.

5. **Keep Migrations Small**: Each migration should represent a single, logical change to the database schema.

6. **Handle Exceptions**: PHP migrations should throw exceptions to indicate failure. Exceptions are caught and handled gracefully by the migration manager.

7. **Multiple Statements**: SQL migrations can contain multiple statements. Kram automatically splits them correctly, handling comments and string literals.

8. **Multiple Operations**: PHP migrations can perform multiple database operations without needing to return values. Simply execute operations and throw exceptions on failure.

## Testing

The project includes comprehensive unit tests using PHPUnit 10 with **90%+ code coverage**. Tests run against MySQL, PostgreSQL, SQLite, and SQL Server to ensure compatibility across all supported databases.

### Running Tests

```bash
# Start database containers (MySQL, PostgreSQL, and SQL Server)
docker compose up -d

# Wait for databases to be ready, then run tests
./vendor/bin/phpunit

# Run tests with coverage
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text

# Stop database containers
docker compose down
```

**Test Coverage:**

- Tests automatically use SQLite for file-based testing and connect to MySQL and PostgreSQL containers when available
- If containers are not running, SQLite-only tests will still execute
- All real-world scenarios are covered, including error handling, edge cases, and failure scenarios

## API Reference

### MigrationManager

The main class for managing migrations.

**Methods:**

- `initialize(): bool` - Initialize the migrations tracking table
- `migrate(): MigrationResult` - Run all pending migrations
- `rollback(): MigrationResult` - Rollback the last migration
- `rollbackTo(?string $targetVersion = null, int $count = 1): MigrationResult` - Rollback to a specific version or last N migrations
- `status(): MigrationStatus` - Get current migration status

### MigrationResult

Value object returned by migration operations.

**Properties:**

- `bool $success` - Whether the operation succeeded
- `string $message` - Human-readable result message
- `array<int, string> $executed` - Array of executed migration versions
- `array<int, string> $rolledBack` - Array of rolled back migration versions

### MigrationStatus

Value object representing the current state of migrations.

**Properties:**

- `array<int, Migration> $executed` - Array of executed migrations
- `array<int, Migration> $pending` - Array of pending migrations
- `string|null $error` - Optional error message if status check failed

### MigrationType

Enum representing migration types.

**Values:**

- `MigrationType::SQL` - SQL file-based migration
- `MigrationType::PHP` - PHP class-based migration

**Methods:**

- `isSql(): bool` - Check if this is a SQL migration
- `isPhp(): bool` - Check if this is a PHP migration

## Troubleshooting

### Migration Fails Mid-Sequence

If a migration fails, execution stops immediately. The migration tracking table may show some migrations as executed before the failure. This is expected behavior - you can manually remove failed migrations from the tracking table if needed. Note that DDL operations in MySQL auto-commit and cannot be rolled back.

### PHP Migration Class Not Found

Ensure:

1. The class name matches the filename (without extension)
2. The class implements `MigrationInterface`
3. The file is in the migrations directory
4. The class is properly namespaced (if using namespaces)

### SQL Migration File Not Found

For SQL migrations:

- Ensure the `.up.sql` file exists (required)
- The `.down.sql` file is optional
- File names must follow the pattern: `{version}_{name}.up.sql`

### Version Conflicts

If you have duplicate versions:

- PHP migrations take precedence over SQL migrations
- Ensure version numbers are unique and sort correctly
- Use timestamp-based versions to avoid conflicts

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

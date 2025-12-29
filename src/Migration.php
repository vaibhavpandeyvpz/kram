<?php

declare(strict_types=1);

/*
 * This file is part of vaibhavpandeyvpz/kram package.
 *
 * (c) Vaibhav Pandey <contact@vaibhavpandey.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Kram;

use Databoss\ConnectionInterface;

/**
 * Class Migration
 *
 * Represents a single database migration with up and down operations.
 * Supports both SQL file-based and PHP class-based migrations.
 *
 * For SQL migrations, the path should be the base path (without .up.sql or .down.sql extension).
 * For PHP migrations, the path should be the full file path or the class name if already loaded.
 */
readonly class Migration
{
    /**
     * Constructor.
     *
     * @param  string  $version  Migration version identifier (typically timestamp or sequential number)
     * @param  string  $name  Migration name/description (human-readable)
     * @param  string  $path  Path to migration file (for SQL migrations) or class name (for PHP migrations)
     * @param  MigrationType  $type  Migration type (SQL or PHP)
     */
    public function __construct(
        public string $version,
        public string $name,
        public string $path,
        public MigrationType $type
    ) {}

    /**
     * Execute the migration up (forward) operation.
     *
     * @param  ConnectionInterface  $connection  Database connection
     *
     * @throws \Throwable If migration execution fails
     */
    public function up(ConnectionInterface $connection): void
    {
        match ($this->type) {
            MigrationType::SQL => $this->executeSqlMigration($connection, 'up'),
            MigrationType::PHP => $this->executePhpMigration($connection, 'up'),
        };
    }

    /**
     * Execute the migration down (rollback) operation.
     *
     * @param  ConnectionInterface  $connection  Database connection
     *
     * @throws \Throwable If migration execution fails
     */
    public function down(ConnectionInterface $connection): void
    {
        match ($this->type) {
            MigrationType::SQL => $this->executeSqlMigration($connection, 'down'),
            MigrationType::PHP => $this->executePhpMigration($connection, 'down'),
        };
    }

    /**
     * Execute SQL-based migration.
     *
     * @param  ConnectionInterface  $connection  Database connection
     * @param  string  $direction  Migration direction ('up' or 'down')
     *
     * @throws \RuntimeException If SQL file is not found or invalid, or if execution fails
     */
    private function executeSqlMigration(ConnectionInterface $connection, string $direction): void
    {
        $file = $this->getSqlFile($direction);
        if (! file_exists($file)) {
            match ($direction) {
                'down' => null, // Down migrations are optional, just return
                default => throw new \RuntimeException("SQL migration file not found: {$file}"),
            };

            return;
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Failed to read SQL migration file: {$file}");
        }

        // Split SQL by semicolons and execute each statement
        foreach ($this->splitSqlStatements($sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            if ($connection->execute($statement) === false) {
                $errorInfo = $connection->pdo()->errorInfo();
                $errorMessage = $errorInfo[2] ?? 'Unknown error';
                throw new \RuntimeException("SQL execution failed: {$errorMessage}");
            }
        }
    }

    /**
     * Execute PHP-based migration.
     *
     * @param  ConnectionInterface  $connection  Database connection
     * @param  string  $direction  Migration direction ('up' or 'down')
     *
     * @throws \RuntimeException If PHP class is not found or invalid
     * @throws \Throwable If migration execution fails
     */
    private function executePhpMigration(ConnectionInterface $connection, string $direction): void
    {
        $className = file_exists($this->path) ? $this->loadPhpClass($this->path) : $this->path;

        if (! class_exists($className)) {
            throw new \RuntimeException("PHP migration class not found: {$className}");
        }

        $migration = new $className;

        if (! ($migration instanceof MigrationInterface)) {
            throw new \RuntimeException("Migration class must implement MigrationInterface: {$className}");
        }

        match ($direction) {
            'up' => $migration->up($connection),
            'down' => $migration->down($connection),
            default => throw new \RuntimeException("Invalid migration direction: {$direction}"),
        };
    }

    /**
     * Load PHP class from file and return class name.
     *
     * @param  string  $filePath  Path to PHP file
     * @return string Class name
     *
     * @throws \RuntimeException If class cannot be extracted
     */
    private function loadPhpClass(string $filePath): string
    {
        require_once $filePath;

        $content = file_get_contents($filePath);
        if ($content === false || ! preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            throw new \RuntimeException("Could not extract class name from PHP migration file: {$filePath}");
        }

        return $matches[1];
    }

    /**
     * Get SQL file path for the given direction.
     *
     * Handles both base paths (without extension) and full paths (with .up.sql or .down.sql).
     *
     * @param  string  $direction  Migration direction ('up' or 'down')
     * @return string The SQL file path
     *
     * @throws \RuntimeException If direction is invalid
     */
    private function getSqlFile(string $direction): string
    {
        // If path already ends with .up.sql or .down.sql, use it directly for that direction
        if ($direction === 'up' && str_ends_with($this->path, '.up.sql')) {
            return $this->path;
        }
        if ($direction === 'down' && str_ends_with($this->path, '.down.sql')) {
            return $this->path;
        }

        // Otherwise, construct path from base
        $basePath = dirname($this->path);
        $baseName = pathinfo($this->path, PATHINFO_FILENAME);
        // Remove .up or .down if present in baseName
        $baseName = preg_replace('/\.(up|down)$/', '', $baseName);

        return match ($direction) {
            'up' => "{$basePath}/{$baseName}.up.sql",
            'down' => "{$basePath}/{$baseName}.down.sql",
            default => throw new \RuntimeException("Invalid migration direction: {$direction}"),
        };
    }

    /**
     * Split SQL string into individual statements.
     *
     * Handles semicolons within strings and comments correctly.
     * Supports both single-line (--) and multi-line (/* *\/) comments.
     * Properly handles string literals with escaped quotes.
     *
     * @param  string  $sql  SQL string to split
     * @return array<int, string> Array of SQL statements (may contain empty strings for comments-only lines)
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $commentType = '';

        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // Handle comments
            if (! $inString) {
                if ($char === '-' && $next === '-') {
                    $inComment = true;
                    $commentType = 'line';
                    $i++;

                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inComment = true;
                    $commentType = 'block';
                    $i++;

                    continue;
                }
                if ($inComment && $commentType === 'block' && $char === '*' && $next === '/') {
                    $inComment = false;
                    $i++;

                    continue;
                }
                if ($inComment && $commentType === 'line' && $char === "\n") {
                    $inComment = false;
                }
                if ($inComment) {
                    continue;
                }
            }

            // Handle strings
            if (! $inComment) {
                if (($char === '"' || $char === "'") && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    if (! $inString) {
                        $inString = true;
                        $stringChar = $char;
                    } elseif ($char === $stringChar) {
                        $inString = false;
                        $stringChar = '';
                    }
                }
            }

            // Handle statement termination
            if (! $inString && ! $inComment && $char === ';') {
                $statements[] = $current;
                $current = '';

                continue;
            }

            if (! $inComment) {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }
}

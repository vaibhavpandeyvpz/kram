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
use Databoss\EscapeMode;

/**
 * Class MigrationRepository
 *
 * Handles database operations for tracking migration versions.
 * Manages the migrations table and tracks which migrations have been executed.
 *
 * Supports MySQL, PostgreSQL, SQLite, and Microsoft SQL Server databases.
 * The migrations table is automatically created on first use if it doesn't exist.
 */
class MigrationRepository
{
    /**
     * Default name for the migrations tracking table.
     */
    private const TABLE_NAME = 'kram_migrations';

    /**
     * Constructor.
     *
     * @param  ConnectionInterface  $connection  Database connection
     * @param  string|null  $tableName  Optional custom table name (default: 'kram_migrations')
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ?string $tableName = null
    ) {}

    /**
     * Get the table name.
     *
     * @return string Table name (custom or default)
     */
    private function getTableName(): string
    {
        return $this->tableName ?? self::TABLE_NAME;
    }

    /**
     * Initialize the migrations table if it doesn't exist.
     *
     * @return bool True on success, false on failure
     */
    public function initialize(): bool
    {
        if ($this->tableExists()) {
            return true;
        }

        $driver = $this->connection->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = match ($driver) {
            'mysql' => $this->getMysqlTableSql(),
            'pgsql' => $this->getPostgresTableSql(),
            'sqlite' => $this->getSqliteTableSql(),
            'sqlsrv' => $this->getSqlServerTableSql(),
            default => throw new \RuntimeException("Unsupported database driver: {$driver}"),
        };

        return $this->connection->execute($sql) !== false;
    }

    /**
     * Check if a migration version has been executed.
     *
     * @param  string  $version  Migration version
     * @return bool True if migration has been executed, false otherwise
     */
    public function isExecuted(string $version): bool
    {
        return $this->connection->exists($this->getTableName(), ['version' => $version]);
    }

    /**
     * Record that a migration has been executed.
     *
     * @param  string  $version  Migration version
     * @param  string  $name  Migration name
     * @return bool True on success, false on failure
     */
    public function recordExecution(string $version, string $name): bool
    {
        return $this->connection->insert($this->getTableName(), [
            'version' => $version,
            'name' => $name,
            'executed_at' => date('Y-m-d H:i:s'),
        ]) !== false;
    }

    /**
     * Remove the record of a migration execution (for rollback).
     *
     * @param  string  $version  Migration version
     * @return bool True on success, false on failure
     */
    public function removeExecution(string $version): bool
    {
        return $this->connection->delete($this->getTableName(), ['version' => $version]) !== false;
    }

    /**
     * Get all executed migration versions.
     *
     * @return array<int, string> Array of executed migration versions
     */
    public function getExecutedVersions(): array
    {
        $migrations = $this->connection->select($this->getTableName(), 'version', [], ['version' => 'ASC']);
        if ($migrations === false) {
            return [];
        }

        return array_map(fn (object|array $m) => (string) $this->getVersion($m), $migrations);
    }

    /**
     * Get the latest executed migration version.
     *
     * @return string|null The latest version, or null if no migrations have been executed
     */
    public function getLatestVersion(): ?string
    {
        $migration = $this->connection->first($this->getTableName(), [], ['version' => 'DESC']);
        if ($migration === false) {
            return null;
        }

        return (string) $this->getVersion($migration);
    }

    /**
     * Extract version from migration record.
     *
     * Handles both object and array results from database queries.
     *
     * @param  object|array<string, mixed>  $migration  Migration record (object or associative array)
     * @return string|int Version value
     */
    private function getVersion(object|array $migration): string|int
    {
        return is_object($migration) ? $migration->version : $migration['version'];
    }

    /**
     * Check if the migrations table exists.
     *
     * @return bool True if table exists, false otherwise
     */
    private function tableExists(): bool
    {
        $driver = $this->connection->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql' => $this->checkMysqlTableExists(),
            'pgsql' => $this->checkPostgresTableExists(),
            'sqlite' => $this->checkSqliteTableExists(),
            'sqlsrv' => $this->checkSqlServerTableExists(),
            default => throw new \RuntimeException("Unsupported database driver: {$driver}"),
        };
    }

    /**
     * Check if table exists in MySQL/MariaDB.
     *
     * @return bool True if table exists
     */
    private function checkMysqlTableExists(): bool
    {
        return $this->checkTableExists(
            'SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
    }

    /**
     * Check if table exists in PostgreSQL.
     *
     * @return bool True if table exists
     */
    private function checkPostgresTableExists(): bool
    {
        return $this->checkTableExists(
            "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?"
        );
    }

    /**
     * Check if table exists in SQLite.
     *
     * @return bool True if table exists
     */
    private function checkSqliteTableExists(): bool
    {
        return $this->checkTableExists(
            "SELECT COUNT(*) as count FROM sqlite_master WHERE type = 'table' AND name = ?"
        );
    }

    /**
     * Check if table exists in Microsoft SQL Server.
     *
     * @return bool True if table exists
     */
    private function checkSqlServerTableExists(): bool
    {
        return $this->checkTableExists(
            "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = ?"
        );
    }

    /**
     * Generic table existence check.
     *
     * @param  string  $sql  SQL query to check table existence
     * @return bool True if table exists
     */
    private function checkTableExists(string $sql): bool
    {
        $result = $this->connection->query($sql, [$this->getTableName()]);
        if ($result === false || empty($result)) {
            return false;
        }

        $count = is_object($result[0]) ? $result[0]->count : $result[0]['count'];

        return (int) $count > 0;
    }

    /**
     * Get MySQL table creation SQL.
     *
     * @return string SQL statement to create migrations table
     */
    private function getMysqlTableSql(): string
    {
        $table = $this->connection->escape($this->getTableName(), EscapeMode::COLUMN_OR_TABLE);

        return "CREATE TABLE {$table} (
            version VARCHAR(255) NOT NULL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            executed_at DATETIME NOT NULL,
            INDEX idx_executed_at (executed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /**
     * Get PostgreSQL table creation SQL.
     *
     * @return string SQL statement to create migrations table
     */
    private function getPostgresTableSql(): string
    {
        $table = $this->connection->escape($this->getTableName(), EscapeMode::COLUMN_OR_TABLE);

        return "CREATE TABLE {$table} (
            version VARCHAR(255) NOT NULL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            executed_at TIMESTAMP NOT NULL
        )";
    }

    /**
     * Get SQLite table creation SQL.
     *
     * @return string SQL statement to create migrations table
     */
    private function getSqliteTableSql(): string
    {
        $table = $this->connection->escape($this->getTableName(), EscapeMode::COLUMN_OR_TABLE);

        return "CREATE TABLE {$table} (
            version VARCHAR(255) NOT NULL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            executed_at DATETIME NOT NULL
        )";
    }

    /**
     * Get Microsoft SQL Server table creation SQL.
     *
     * @return string SQL statement to create migrations table
     */
    private function getSqlServerTableSql(): string
    {
        $table = $this->connection->escape($this->getTableName(), EscapeMode::COLUMN_OR_TABLE);

        return "CREATE TABLE {$table} (
            version NVARCHAR(255) NOT NULL PRIMARY KEY,
            name NVARCHAR(255) NOT NULL,
            executed_at DATETIME2 NOT NULL
        )";
    }
}

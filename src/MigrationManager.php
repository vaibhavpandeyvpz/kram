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
 * Class MigrationManager
 *
 * Main entry point for managing database migrations.
 * Handles running migrations up and down, tracking versions, and ensuring determinism.
 */
class MigrationManager
{
    /**
     * Constructor.
     *
     * @param  ConnectionInterface  $connection  Database connection
     * @param  string  $migrationsDirectory  Directory containing migration files
     * @param  string|null  $tableName  Optional custom migrations table name
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $migrationsDirectory,
        private readonly ?string $tableName = null
    ) {}

    /**
     * Get migration repository instance.
     *
     * @return MigrationRepository Migration repository instance
     */
    private function repository(): MigrationRepository
    {
        return new MigrationRepository($this->connection, $this->tableName);
    }

    /**
     * Get migration loader instance.
     *
     * @return MigrationLoader Migration loader instance
     */
    private function loader(): MigrationLoader
    {
        return new MigrationLoader($this->migrationsDirectory);
    }

    /**
     * Initialize the migrations system (create tracking table if needed).
     *
     * @return bool True on success, false on failure
     */
    public function initialize(): bool
    {
        return $this->repository()->initialize();
    }

    /**
     * Run all pending migrations.
     *
     * @return MigrationResult Result of the migration operation
     */
    public function migrate(): MigrationResult
    {
        if (! $this->initialize()) {
            return new MigrationResult(false, 'Failed to initialize migrations table');
        }

        $migrations = $this->loader()->load();
        $executedVersions = $this->repository()->getExecutedVersions();
        $executedSet = array_flip($executedVersions);

        $pending = array_filter($migrations, fn (Migration $m) => ! isset($executedSet[$m->version]));

        if (empty($pending)) {
            return new MigrationResult(true, 'No pending migrations');
        }

        return $this->executeMigrations($pending, 'up');
    }

    /**
     * Rollback the last migration.
     *
     * @return MigrationResult Result of the rollback operation
     */
    public function rollback(): MigrationResult
    {
        return $this->rollbackTo(null, 1);
    }

    /**
     * Rollback migrations to a specific version.
     *
     * If $targetVersion is provided, all migrations with versions greater than
     * the target will be rolled back. If $targetVersion is null, the last $count
     * migrations will be rolled back.
     *
     * Migrations are rolled back in reverse order (newest first).
     *
     * @param  string|null  $targetVersion  Target version to rollback to (null = rollback last N)
     * @param  int  $count  Number of migrations to rollback (used if targetVersion is null)
     * @return MigrationResult Result of the rollback operation
     */
    public function rollbackTo(?string $targetVersion = null, int $count = 1): MigrationResult
    {
        if (! $this->initialize()) {
            return new MigrationResult(false, 'Failed to initialize migrations table');
        }

        $migrations = $this->loader()->load();
        $executedVersions = $this->repository()->getExecutedVersions();

        if (empty($executedVersions)) {
            return new MigrationResult(true, 'No migrations to rollback');
        }

        $migrationMap = [];
        foreach ($migrations as $migration) {
            $migrationMap[$migration->version] = $migration;
        }

        $versionsToRollback = match (true) {
            $targetVersion !== null => array_filter($executedVersions, fn (string $v) => $v > $targetVersion),
            default => array_slice(array_reverse($executedVersions), 0, $count),
        };

        $toRollback = array_values(array_filter(
            array_map(fn (string $v) => $migrationMap[$v] ?? null, $versionsToRollback)
        ));

        if (empty($toRollback)) {
            return new MigrationResult(true, 'No migrations to rollback');
        }

        // Sort by version descending (rollback in reverse order)
        usort($toRollback, fn (Migration $a, Migration $b) => strcmp($b->version, $a->version));

        $result = $this->executeMigrations($toRollback, 'down');
        $message = count($result->rolledBack) > 0
            ? sprintf('Rolled back %d migration(s)', count($result->rolledBack))
            : 'No migrations rolled back';

        return new MigrationResult($result->success, $result->success ? $message : $result->message, [], $result->rolledBack);
    }

    /**
     * Get the current migration status.
     *
     * @return MigrationStatus Current migration status
     */
    public function status(): MigrationStatus
    {
        if (! $this->initialize()) {
            return new MigrationStatus([], [], 'Failed to initialize migrations table');
        }

        $migrations = $this->loader()->load();
        $executedVersions = $this->repository()->getExecutedVersions();
        $executedSet = array_flip($executedVersions);

        $executed = [];
        $pending = [];

        foreach ($migrations as $migration) {
            if (isset($executedSet[$migration->version])) {
                $executed[] = $migration;
            } else {
                $pending[] = $migration;
            }
        }

        return new MigrationStatus($executed, $pending);
    }

    /**
     * Execute a list of migrations in the given direction.
     *
     * @param  array<int, Migration>  $migrations  Migrations to execute
     * @param  string  $direction  Direction ('up' or 'down')
     * @return MigrationResult Result of the operation
     */
    private function executeMigrations(array $migrations, string $direction): MigrationResult
    {
        $executed = [];
        $rolledBack = [];
        $errors = [];
        $repository = $this->repository();

        foreach ($migrations as $migration) {
            try {
                // Execute migration directly (no batch transaction)
                // DDL operations (CREATE TABLE, DROP TABLE, etc.) auto-commit in MySQL anyway,
                // so wrapping in a transaction doesn't provide atomicity benefits and causes issues.
                match ($direction) {
                    'up' => $migration->up($this->connection),
                    'down' => $migration->down($this->connection),
                    default => throw new \RuntimeException("Invalid direction: {$direction}"),
                };

                // Record migration execution
                match ($direction) {
                    'up' => $this->recordUp($repository, $migration, $executed),
                    'down' => $this->recordDown($repository, $migration, $rolledBack),
                };
            } catch (\Throwable $e) {
                $action = $direction === 'up' ? 'executing' : 'rolling back';
                $errors[] = "Error {$action} migration {$migration->version} - {$migration->name}: {$e->getMessage()}";
                break;
            }
        }

        if (! empty($errors)) {
            return new MigrationResult(false, implode("\n", $errors), $executed, $rolledBack);
        }

        $action = $direction === 'up' ? 'Executed' : 'Rolled back';
        $count = $direction === 'up' ? count($executed) : count($rolledBack);
        $message = $count > 0 ? sprintf('%s %d migration(s)', $action, $count) : "No migrations {$direction}";

        return new MigrationResult(true, $message, $executed, $rolledBack);
    }

    /**
     * Record successful up migration.
     *
     * @param  MigrationRepository  $repository  Migration repository
     * @param  Migration  $migration  Migration that was executed
     * @param  array<int, string>  $executed  Array to append executed version to (by reference)
     */
    private function recordUp(MigrationRepository $repository, Migration $migration, array &$executed): void
    {
        $repository->recordExecution($migration->version, $migration->name);
        $executed[] = $migration->version;
    }

    /**
     * Record successful down migration.
     *
     * @param  MigrationRepository  $repository  Migration repository
     * @param  Migration  $migration  Migration that was rolled back
     * @param  array<int, string>  $rolledBack  Array to append rolled back version to (by reference)
     */
    private function recordDown(MigrationRepository $repository, Migration $migration, array &$rolledBack): void
    {
        $repository->removeExecution($migration->version);
        $rolledBack[] = $migration->version;
    }
}

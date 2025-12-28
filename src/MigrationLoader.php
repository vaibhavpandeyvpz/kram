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

/**
 * Class MigrationLoader
 *
 * Loads migration files from a directory.
 * Supports both SQL file-based and PHP class-based migrations.
 *
 * SQL migrations must follow the pattern: {version}_{name}.up.sql (and optionally .down.sql)
 * PHP migrations must follow the pattern: {version}_{ClassName}.php
 *
 * If both SQL and PHP migrations exist for the same version, PHP migrations take precedence.
 */
class MigrationLoader
{
    /**
     * Regular expression for matching SQL migration files (e.g., "20240101120000_create_users.up.sql").
     */
    private const REGEX_SQL_MIGRATION = '/^(\d+)_(.+)\.(up|down)\.sql$/';

    /**
     * Regular expression for matching PHP migration files (e.g., "20240101120000_CreateUsers.php").
     */
    private const REGEX_PHP_MIGRATION = '/^(\d+)_(.+)\.php$/';

    /**
     * Constructor.
     *
     * @param  string  $directory  Directory path containing migration files
     *
     * @throws \InvalidArgumentException If directory doesn't exist or is not readable
     */
    public function __construct(
        private readonly string $directory
    ) {
        $normalized = rtrim($directory, '/\\');
        if (! is_dir($normalized)) {
            throw new \InvalidArgumentException("Migration directory does not exist: {$normalized}");
        }

        if (! is_readable($normalized)) {
            throw new \InvalidArgumentException("Migration directory is not readable: {$normalized}");
        }
    }

    /**
     * Get normalized directory path.
     *
     * @return string Normalized directory path (trailing slashes removed)
     */
    private function getDirectory(): string
    {
        return rtrim($this->directory, '/\\');
    }

    /**
     * Load all migrations from the directory.
     *
     * @return array<int, Migration> Array of Migration objects, sorted by version
     */
    public function load(): array
    {
        $migrations = [];
        $files = scandir($this->getDirectory());

        if ($files === false) {
            return [];
        }

        $sqlMigrations = [];
        $phpMigrations = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $this->getDirectory().DIRECTORY_SEPARATOR.$file;

            // Check for SQL migrations
            if (preg_match(self::REGEX_SQL_MIGRATION, $file, $matches) && str_ends_with($file, '.up.sql')) {
                $version = $matches[1];
                $name = $this->normalizeName($matches[2]);
                // Store base path without .up.sql extension (remove from filename, not full path)
                $basePath = $this->getDirectory().DIRECTORY_SEPARATOR.$matches[1].'_'.$matches[2];
                $sqlMigrations[$version] = new Migration(
                    $version,
                    $name,
                    $basePath,
                    MigrationType::SQL
                );
            }

            // Check for PHP migrations
            if (preg_match(self::REGEX_PHP_MIGRATION, $file, $matches)) {
                $version = $matches[1];
                $name = $this->normalizeName($matches[2]);
                $phpMigrations[$version] = new Migration(
                    $version,
                    $name,
                    $filePath,
                    MigrationType::PHP
                );
            }
        }

        // Merge migrations, preferring PHP over SQL if both exist for same version
        // PHP migrations overwrite SQL migrations when they have the same version
        foreach ($phpMigrations as $version => $migration) {
            unset($sqlMigrations[$version]);
        }
        $migrations = array_merge($sqlMigrations, $phpMigrations);

        // Sort by version
        ksort($migrations, SORT_STRING);

        return array_values($migrations);
    }

    /**
     * Normalize migration name by converting underscores to spaces and capitalizing words.
     *
     * Example: "create_users_table" becomes "Create Users Table"
     *
     * @param  string  $name  Raw migration name (from filename)
     * @return string Normalized migration name (human-readable)
     */
    private function normalizeName(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }
}

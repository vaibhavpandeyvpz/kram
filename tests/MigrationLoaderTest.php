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

use PHPUnit\Framework\TestCase;

/**
 * Class MigrationLoaderTest
 *
 * Test suite for MigrationLoader class.
 */
class MigrationLoaderTest extends TestCase
{
    private string $migrationsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrationsDir = sys_get_temp_dir().'/kram_test_migrations_'.uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->migrationsDir)) {
            $this->removeDirectory($this->migrationsDir);
        }
    }

    public function test_load_with_nonexistent_directory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MigrationLoader('/nonexistent/directory');
    }

    public function test_load_with_unreadable_directory(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Cannot test unreadable directory on Windows');
        }

        $unreadableDir = sys_get_temp_dir().'/kram_unreadable_'.uniqid();
        mkdir($unreadableDir, 0000, true);

        try {
            $this->expectException(\InvalidArgumentException::class);
            new MigrationLoader($unreadableDir);
        } finally {
            chmod($unreadableDir, 0755);
            rmdir($unreadableDir);
        }
    }

    public function test_load_empty_directory(): void
    {
        $loader = new MigrationLoader($this->migrationsDir);
        $migrations = $loader->load();

        $this->assertIsArray($migrations);
        $this->assertEmpty($migrations);
    }

    public function test_load_sql_migrations(): void
    {
        file_put_contents("{$this->migrationsDir}/20240101120000_create_users.up.sql", 'CREATE TABLE users');
        file_put_contents("{$this->migrationsDir}/20240101120000_create_users.down.sql", 'DROP TABLE users');
        file_put_contents("{$this->migrationsDir}/20240101120001_create_posts.up.sql", 'CREATE TABLE posts');
        file_put_contents("{$this->migrationsDir}/20240101120001_create_posts.down.sql", 'DROP TABLE posts');

        $loader = new MigrationLoader($this->migrationsDir);
        $migrations = $loader->load();

        $this->assertCount(2, $migrations);
        $this->assertEquals('20240101120000', $migrations[0]->version);
        $this->assertEquals('Create Users', $migrations[0]->name);
        $this->assertEquals(MigrationType::SQL, $migrations[0]->type);
        $this->assertEquals('20240101120001', $migrations[1]->version);
    }

    public function test_load_php_migrations(): void
    {
        $className = 'CreateUsers_'.uniqid();
        $phpCode = <<<PHP
<?php

use Databoss\ConnectionInterface;
use Kram\MigrationInterface;

class {$className} implements MigrationInterface
{
    public function up(ConnectionInterface \$connection): void
    {
        // Migration succeeds
    }

    public function down(ConnectionInterface \$connection): void
    {
        // Rollback succeeds
    }
}
PHP;
        file_put_contents("{$this->migrationsDir}/20240101120000_{$className}.php", $phpCode);

        $loader = new MigrationLoader($this->migrationsDir);
        $migrations = $loader->load();

        $this->assertCount(1, $migrations);
        $this->assertEquals('20240101120000', $migrations[0]->version);
        // Name is normalized (underscores to spaces, capitalized)
        // The name will be like "CreateUsers {uniqid}" after normalization
        $this->assertStringStartsWith('CreateUsers', $migrations[0]->name);
        $this->assertEquals(MigrationType::PHP, $migrations[0]->type);
    }

    public function test_load_sorts_by_version(): void
    {
        file_put_contents("{$this->migrationsDir}/20240101120001_second.up.sql", 'CREATE TABLE second');
        file_put_contents("{$this->migrationsDir}/20240101120001_second.down.sql", 'DROP TABLE second');
        file_put_contents("{$this->migrationsDir}/20240101120000_first.up.sql", 'CREATE TABLE first');
        file_put_contents("{$this->migrationsDir}/20240101120000_first.down.sql", 'DROP TABLE first');

        $loader = new MigrationLoader($this->migrationsDir);
        $migrations = $loader->load();

        $this->assertCount(2, $migrations);
        $this->assertEquals('20240101120000', $migrations[0]->version);
        $this->assertEquals('20240101120001', $migrations[1]->version);
    }

    public function test_load_ignores_invalid_files(): void
    {
        file_put_contents("{$this->migrationsDir}/20240101120000_create_users.up.sql", 'CREATE TABLE users');
        file_put_contents("{$this->migrationsDir}/invalid_file.txt", 'Some content');
        file_put_contents("{$this->migrationsDir}/not_a_migration.sql", 'CREATE TABLE test');

        $loader = new MigrationLoader($this->migrationsDir);
        $migrations = $loader->load();

        $this->assertCount(1, $migrations);
    }

    public function test_load_prefers_php_over_sql(): void
    {
        file_put_contents("{$this->migrationsDir}/20240101120000_create_users.up.sql", 'CREATE TABLE users');
        file_put_contents("{$this->migrationsDir}/20240101120000_create_users.down.sql", 'DROP TABLE users');

        $className = 'CreateUsers_'.uniqid();
        $phpCode = <<<PHP
<?php

use Databoss\ConnectionInterface;
use Kram\MigrationInterface;

class {$className} implements MigrationInterface
{
    public function up(ConnectionInterface \$connection): void
    {
        // Migration succeeds
    }

    public function down(ConnectionInterface \$connection): void
    {
        // Rollback succeeds
    }
}
PHP;
        file_put_contents("{$this->migrationsDir}/20240101120000_{$className}.php", $phpCode);

        $loader = new MigrationLoader($this->migrationsDir);
        $migrations = $loader->load();

        // Should only have 1 migration (PHP, not SQL)
        $this->assertCount(1, $migrations);
        $this->assertEquals('20240101120000', $migrations[0]->version);
        $this->assertEquals(MigrationType::PHP, $migrations[0]->type);
    }

    /**
     * Remove directory recursively.
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}

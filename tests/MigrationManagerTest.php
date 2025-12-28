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

use Databoss\Connection;
use Databoss\ConnectionInterface;
use Databoss\DatabaseDriver;
use PHPUnit\Framework\TestCase;

/**
 * Class MigrationManagerTest
 *
 * Test suite for MigrationManager class.
 * Tests migration execution, rollback, and status across MySQL, PostgreSQL, and SQLite.
 */
class MigrationManagerTest extends TestCase
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

    /**
     * @dataProvider provideConnection
     */
    public function test_initialize(ConnectionInterface $connection): void
    {
        $manager = new MigrationManager($connection, $this->migrationsDir);
        $this->assertTrue($manager->initialize());
        $this->assertTrue($manager->initialize()); // Should be idempotent
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_migrate_with_no_migrations(ConnectionInterface $connection): void
    {
        $manager = new MigrationManager($connection, $this->migrationsDir);
        $result = $manager->migrate();

        $this->assertTrue($result->success);
        $this->assertEquals('No pending migrations', $result->message);
        $this->assertEmpty($result->executed);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_migrate_with_sql_migrations(ConnectionInterface $connection): void
    {
        // Create SQL migration files
        $this->createSqlMigration('20240101120000', 'create_users', 'CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(255))', 'DROP TABLE users');

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $result = $manager->migrate();

        if (! $result->success) {
            $this->fail("Migration failed: {$result->message}");
        }
        $this->assertTrue($result->success);
        $this->assertStringContainsString('Executed', $result->message);
        $this->assertCount(1, $result->executed);
        $this->assertEquals('20240101120000', $result->executed[0]);

        // Verify table was created
        $tableCheck = $connection->query('SELECT 1 FROM users LIMIT 1');
        $this->assertNotFalse($tableCheck);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_migrate_with_php_migrations(ConnectionInterface $connection): void
    {
        // Create PHP migration
        $this->createPhpMigration('20240101120000', 'CreateUsers', 'CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(255))', 'DROP TABLE users');

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $result = $manager->migrate();

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->executed);

        // Verify table was created
        $tableCheck = $connection->query('SELECT 1 FROM users LIMIT 1');
        $this->assertNotFalse($tableCheck);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_migrate_multiple_migrations(ConnectionInterface $connection): void
    {
        $this->createSqlMigration('20240101120000', 'create_users', 'CREATE TABLE users (id INT PRIMARY KEY)', 'DROP TABLE users');
        $this->createSqlMigration('20240101120001', 'create_posts', 'CREATE TABLE posts (id INT PRIMARY KEY)', 'DROP TABLE posts');

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $result = $manager->migrate();

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->executed);
        $this->assertEquals('20240101120000', $result->executed[0]);
        $this->assertEquals('20240101120001', $result->executed[1]);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_migrate_executes_in_order(ConnectionInterface $connection): void
    {
        $this->createSqlMigration('20240101120001', 'create_posts', 'CREATE TABLE posts (id INT PRIMARY KEY)', 'DROP TABLE posts');
        $this->createSqlMigration('20240101120000', 'create_users', 'CREATE TABLE users (id INT PRIMARY KEY)', 'DROP TABLE users');

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $result = $manager->migrate();

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->executed);
        // Should execute in version order, not file order
        $this->assertEquals('20240101120000', $result->executed[0]);
        $this->assertEquals('20240101120001', $result->executed[1]);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_rollback_last_migration(ConnectionInterface $connection): void
    {
        $this->createSqlMigration('20240101120000', 'create_users', 'CREATE TABLE users (id INT PRIMARY KEY)', 'DROP TABLE users');
        $this->createSqlMigration('20240101120001', 'create_posts', 'CREATE TABLE posts (id INT PRIMARY KEY)', 'DROP TABLE posts');

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $manager->migrate();

        $result = $manager->rollback();

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->rolledBack);
        $this->assertEquals('20240101120001', $result->rolledBack[0]);

        // Verify posts table was dropped - query should fail
        try {
            $connection->query('SELECT 1 FROM posts LIMIT 1');
            $this->fail('Table should not exist');
        } catch (\PDOException) {
            // Expected - table doesn't exist
            $this->assertTrue(true);
        }
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_rollback_to_version(ConnectionInterface $connection): void
    {
        $this->createSqlMigration('20240101120000', 'create_users', 'CREATE TABLE users (id INT PRIMARY KEY)', 'DROP TABLE users');
        $this->createSqlMigration('20240101120001', 'create_posts', 'CREATE TABLE posts (id INT PRIMARY KEY)', 'DROP TABLE posts');
        $this->createSqlMigration('20240101120002', 'create_comments', 'CREATE TABLE comments (id INT PRIMARY KEY)', 'DROP TABLE comments');

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $manager->migrate();

        $result = $manager->rollbackTo('20240101120000');

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->rolledBack);
        // Should rollback in reverse order
        $this->assertEquals('20240101120002', $result->rolledBack[0]);
        $this->assertEquals('20240101120001', $result->rolledBack[1]);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_status(ConnectionInterface $connection): void
    {
        $this->createSqlMigration('20240101120000', 'create_users', 'CREATE TABLE users (id INT PRIMARY KEY)', 'DROP TABLE users');
        $this->createSqlMigration('20240101120001', 'create_posts', 'CREATE TABLE posts (id INT PRIMARY KEY)', 'DROP TABLE posts');

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $status = $manager->status();

        $this->assertEmpty($status->executed);
        $this->assertCount(2, $status->pending);

        $manager->migrate();
        $status = $manager->status();

        $this->assertCount(2, $status->executed);
        $this->assertEmpty($status->pending);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_custom_table_name(ConnectionInterface $connection): void
    {
        $customTable = 'custom_migrations';
        $manager = new MigrationManager($connection, $this->migrationsDir, $customTable);
        $manager->initialize();

        // Verify custom table was created
        $driver = $connection->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $tableExists = match ($driver) {
            'mysql' => $connection->query('SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$customTable]) !== false,
            'pgsql' => $connection->query("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?", [$customTable]) !== false,
            'sqlite' => $connection->query("SELECT COUNT(*) as count FROM sqlite_master WHERE type = 'table' AND name = ?", [$customTable]) !== false,
            default => false,
        };

        $this->assertTrue($tableExists);
    }

    /**
     * Create SQL migration files.
     */
    private function createSqlMigration(string $version, string $name, string $upSql, string $downSql): void
    {
        $baseName = "{$version}_{$name}";
        file_put_contents("{$this->migrationsDir}/{$baseName}.up.sql", $upSql);
        file_put_contents("{$this->migrationsDir}/{$baseName}.down.sql", $downSql);
    }

    /**
     * Create PHP migration file.
     */
    private function createPhpMigration(string $version, string $className, string $upSql, string $downSql): void
    {
        $code = <<<PHP
<?php

use Databoss\ConnectionInterface;
use Kram\MigrationInterface;

class {$className} implements MigrationInterface
{
    public function up(ConnectionInterface \$connection): bool
    {
        return \$connection->execute("{$upSql}") !== false;
    }

    public function down(ConnectionInterface \$connection): bool
    {
        return \$connection->execute("{$downSql}") !== false;
    }
}
PHP;
        file_put_contents("{$this->migrationsDir}/{$version}_{$className}.php", $code);
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

    /**
     * Provide database connections for testing.
     */
    public static function provideConnection(): array
    {
        $connections = [];

        // MySQL (skip if not available)
        try {
            $mysql = new Connection([
                Connection::OPT_DRIVER => DatabaseDriver::MYSQL->value,
                Connection::OPT_HOST => '127.0.0.1',
                Connection::OPT_DATABASE => 'testdb',
                Connection::OPT_USERNAME => 'root',
                Connection::OPT_PASSWORD => 'root',
            ]);
            $mysql->pdo(); // Test connection
            $connections[] = [$mysql];
        } catch (\Throwable) {
            // MySQL not available, skip
        }

        // PostgreSQL (skip if not available)
        try {
            $postgres = new Connection([
                Connection::OPT_DRIVER => DatabaseDriver::POSTGRES->value,
                Connection::OPT_HOST => '127.0.0.1',
                Connection::OPT_DATABASE => 'testdb',
                Connection::OPT_USERNAME => 'postgres',
                Connection::OPT_PASSWORD => 'postgres',
            ]);
            $postgres->pdo(); // Test connection
            $connections[] = [$postgres];
        } catch (\Throwable) {
            // PostgreSQL not available, skip
        }

        // SQLite (always available)
        $sqliteDb = tempnam(sys_get_temp_dir(), 'kram_test_').'.sqlite';
        $sqliteConnection = new Connection([
            Connection::OPT_DRIVER => DatabaseDriver::SQLITE->value,
            Connection::OPT_DATABASE => $sqliteDb,
        ]);

        $connections[] = [$sqliteConnection];

        return $connections;
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_rollback_with_missing_down_files(ConnectionInterface $connection): void
    {
        // Create migration with down file
        $this->createSqlMigration('20240101120000', 'create_users', 'CREATE TABLE users (id INT)', 'DROP TABLE users');

        // Create migration without down file
        $basePath = "{$this->migrationsDir}/20240101120001_create_posts";
        file_put_contents("{$basePath}.up.sql", 'CREATE TABLE posts (id INT)');
        // Don't create .down.sql file

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $manager->migrate();

        // Rollback should succeed even if down file is missing
        $result = $manager->rollback();
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->rolledBack);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_status_with_gaps_in_versions(ConnectionInterface $connection): void
    {
        // Create three migrations
        $this->createSqlMigration('20240101120000', 'create_users', 'CREATE TABLE users (id INT)', 'DROP TABLE users');
        $this->createSqlMigration('20240101120001', 'create_posts', 'CREATE TABLE posts (id INT)', 'DROP TABLE posts');
        $this->createSqlMigration('20240101120002', 'create_comments', 'CREATE TABLE comments (id INT)', 'DROP TABLE comments');

        $manager = new MigrationManager($connection, $this->migrationsDir);

        // Manually record only first and third migration (simulating gap)
        $repository = new MigrationRepository($connection);
        $repository->initialize();
        $repository->recordExecution('20240101120000', 'Create Users');
        $repository->recordExecution('20240101120002', 'Create Comments');

        $status = $manager->status();

        // Should show first and third as executed, second as pending
        $this->assertCount(2, $status->executed);
        $this->assertCount(1, $status->pending);
        $this->assertEquals('20240101120001', $status->pending[0]->version);
    }

    public function test_migration_with_special_characters_in_name(): void
    {
        $connection = new Connection([
            Connection::OPT_DRIVER => DatabaseDriver::SQLITE->value,
            Connection::OPT_DATABASE => ':memory:',
        ]);

        // Migration name with special characters (hyphens, underscores)
        $this->createSqlMigration('20240101120000', 'create-user-table', 'CREATE TABLE users (id INT)', 'DROP TABLE users');

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $result = $manager->migrate();

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->executed);
    }

    public function test_rollback_php_migration_returns_false(): void
    {
        $connection = new Connection([
            Connection::OPT_DRIVER => DatabaseDriver::SQLITE->value,
            Connection::OPT_DATABASE => ':memory:',
        ]);

        $phpCode = <<<'PHP'
<?php

use Databoss\ConnectionInterface;
use Kram\MigrationInterface;

class RollbackFailingMigration implements MigrationInterface
{
    public function up(ConnectionInterface $connection): bool
    {
        return $connection->execute('CREATE TABLE test (id INT)') !== false;
    }

    public function down(ConnectionInterface $connection): bool
    {
        return false; // Rollback fails
    }
}
PHP;
        $filePath = "{$this->migrationsDir}/20240101120000_RollbackFailingMigration.php";
        file_put_contents($filePath, $phpCode);

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $manager->migrate();

        $result = $manager->rollback();
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Failed to rollback', $result->message);
    }

    public function test_migration_manager_handles_exception(): void
    {
        $connection = new Connection([
            Connection::OPT_DRIVER => DatabaseDriver::SQLITE->value,
            Connection::OPT_DATABASE => ':memory:',
        ]);

        $phpCode = <<<'PHP'
<?php

use Databoss\ConnectionInterface;
use Kram\MigrationInterface;

class ExceptionThrowingMigration implements MigrationInterface
{
    public function up(ConnectionInterface $connection): bool
    {
        throw new \RuntimeException('Something went wrong');
    }

    public function down(ConnectionInterface $connection): bool
    {
        return true;
    }
}
PHP;
        $filePath = "{$this->migrationsDir}/20240101120000_ExceptionThrowingMigration.php";
        file_put_contents($filePath, $phpCode);

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $result = $manager->migrate();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Error executing', $result->message);
    }

    public function test_multiple_migrations_one_fails(): void
    {
        $connection = new Connection([
            Connection::OPT_DRIVER => DatabaseDriver::SQLITE->value,
            Connection::OPT_DATABASE => ':memory:',
        ]);

        // First migration succeeds
        $this->createSqlMigration('20240101120000', 'create_users', 'CREATE TABLE users (id INT)', 'DROP TABLE users');

        // Second migration fails (invalid SQL)
        $basePath = "{$this->migrationsDir}/20240101120001_invalid";
        file_put_contents("{$basePath}.up.sql", 'INVALID SQL STATEMENT;');

        // Third migration should not run
        $this->createSqlMigration('20240101120002', 'create_posts', 'CREATE TABLE posts (id INT)', 'DROP TABLE posts');

        $manager = new MigrationManager($connection, $this->migrationsDir);
        $result = $manager->migrate();

        $this->assertFalse($result->success);
        // First migration should be executed
        $this->assertCount(1, $result->executed);
        // Second and third should not be executed
    }
}

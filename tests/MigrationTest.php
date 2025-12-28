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
 * Class MigrationTest
 *
 * Test suite for Migration class.
 */
class MigrationTest extends TestCase
{
    private string $migrationsDir;

    /**
     * Clean up a table if it exists.
     */
    private function cleanupTable(ConnectionInterface $connection, string $tableName): void
    {
        try {
            $escapedTable = $connection->escape($tableName, \Databoss\EscapeMode::COLUMN_OR_TABLE);
            $connection->execute("DROP TABLE IF EXISTS {$escapedTable}");
        } catch (\Throwable) {
            // Ignore errors
        }
    }

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

    public function test_constructor(): void
    {
        $migration = new Migration('20240101120000', 'Test Migration', '/path/to/file', MigrationType::SQL);

        $this->assertEquals('20240101120000', $migration->version);
        $this->assertEquals('Test Migration', $migration->name);
        $this->assertEquals('/path/to/file', $migration->path);
        $this->assertEquals(MigrationType::SQL, $migration->type);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_sql_migration_up(ConnectionInterface $connection): void
    {
        $this->cleanupTable($connection, 'users');
        $basePath = "{$this->migrationsDir}/20240101120000_create_users";
        file_put_contents("{$basePath}.up.sql", 'CREATE TABLE users (id INT PRIMARY KEY)');

        $migration = new Migration('20240101120000', 'Create Users', $basePath, MigrationType::SQL);
        $this->assertTrue($migration->up($connection));

        // Verify table was created
        $tableCheck = $connection->query('SELECT 1 FROM users LIMIT 1');
        $this->assertNotFalse($tableCheck);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_sql_migration_down(ConnectionInterface $connection): void
    {
        $this->cleanupTable($connection, 'users');
        // Create table first
        $connection->execute('CREATE TABLE users (id INT PRIMARY KEY)');

        $basePath = "{$this->migrationsDir}/20240101120000_create_users";
        file_put_contents("{$basePath}.down.sql", 'DROP TABLE users');

        $migration = new Migration('20240101120000', 'Create Users', $basePath, MigrationType::SQL);
        $this->assertTrue($migration->down($connection));

        // Verify table was dropped - query should fail
        try {
            $connection->query('SELECT 1 FROM users LIMIT 1');
            $this->fail('Table should not exist');
        } catch (\PDOException) {
            // Expected - table doesn't exist
            $this->assertTrue(true);
        }
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_sql_migration_down_optional(ConnectionInterface $connection): void
    {
        $basePath = "{$this->migrationsDir}/20240101120000_create_users";
        // Don't create down file

        $migration = new Migration('20240101120000', 'Create Users', $basePath, MigrationType::SQL);
        // Should return true even without down file
        $this->assertTrue($migration->down($connection));
    }

    public function test_sql_migration_up_missing_file(): void
    {
        $connection = new Connection([
            Connection::OPT_DRIVER => DatabaseDriver::SQLITE->value,
            Connection::OPT_DATABASE => ':memory:',
        ]);

        $filePath = "{$this->migrationsDir}/nonexistent";
        $migration = new Migration('20240101120000', 'Test', $filePath, MigrationType::SQL);

        $this->expectException(\RuntimeException::class);
        $migration->up($connection);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_php_migration(ConnectionInterface $connection): void
    {
        $className = 'TestMigration_'.uniqid();
        $phpCode = <<<PHP
<?php

use Databoss\ConnectionInterface;
use Kram\MigrationInterface;

class {$className} implements MigrationInterface
{
    public function up(ConnectionInterface \$connection): bool
    {
        return \$connection->execute('CREATE TABLE test (id INT PRIMARY KEY)') !== false;
    }

    public function down(ConnectionInterface \$connection): bool
    {
        return \$connection->execute('DROP TABLE test') !== false;
    }
}
PHP;
        $filePath = "{$this->migrationsDir}/{$className}.php";
        file_put_contents($filePath, $phpCode);

        $migration = new Migration('20240101120000', 'Test Migration', $filePath, MigrationType::PHP);
        $this->assertTrue($migration->up($connection));

        // Verify table was created
        $tableCheck = $connection->query('SELECT 1 FROM test LIMIT 1');
        $this->assertNotFalse($tableCheck);

        $this->assertTrue($migration->down($connection));

        // Verify table was dropped - query should fail
        try {
            $connection->query('SELECT 1 FROM test LIMIT 1');
            $this->fail('Table should not exist');
        } catch (\PDOException) {
            // Expected - table doesn't exist
            $this->assertTrue(true);
        }
    }

    public function test_php_migration_invalid_class(): void
    {
        $connection = new Connection([
            Connection::OPT_DRIVER => DatabaseDriver::SQLITE->value,
            Connection::OPT_DATABASE => ':memory:',
        ]);

        // Use unique class name to avoid conflicts when test runs multiple times
        $className = 'InvalidMigrationClass_'.uniqid();
        $phpCode = "<?php\n\nclass {$className}\n{\n}\n";
        $filePath = "{$this->migrationsDir}/{$className}.php";
        file_put_contents($filePath, $phpCode);

        $migration = new Migration('20240101120000', 'Test', $filePath, MigrationType::PHP);

        $this->expectException(\RuntimeException::class);
        $migration->up($connection);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_sql_migration_multiple_statements(ConnectionInterface $connection): void
    {
        $this->cleanupTable($connection, 'users');
        $this->cleanupTable($connection, 'posts');
        $sql = <<<'SQL'
CREATE TABLE users (id INT PRIMARY KEY);
CREATE TABLE posts (id INT PRIMARY KEY);
SQL;
        $basePath = "{$this->migrationsDir}/20240101120000_create_tables";
        file_put_contents("{$basePath}.up.sql", $sql);

        $migration = new Migration('20240101120000', 'Create Tables', $basePath, MigrationType::SQL);
        $this->assertTrue($migration->up($connection));

        // Verify both tables were created
        $usersCheck = $connection->query('SELECT 1 FROM users LIMIT 1');
        $postsCheck = $connection->query('SELECT 1 FROM posts LIMIT 1');
        $this->assertNotFalse($usersCheck);
        $this->assertNotFalse($postsCheck);
    }

    /**
     * Test PHP migration that returns false (not exception).
     */
    public function test_php_migration_returns_false(): void
    {
        $connection = new Connection([
            Connection::OPT_DRIVER => DatabaseDriver::SQLITE->value,
            Connection::OPT_DATABASE => ':memory:',
        ]);

        $className = 'FailingMigration_'.uniqid();
        $phpCode = <<<PHP
<?php

use Databoss\ConnectionInterface;
use Kram\MigrationInterface;

class {$className} implements MigrationInterface
{
    public function up(ConnectionInterface \$connection): bool
    {
        return false; // Explicitly return false
    }

    public function down(ConnectionInterface \$connection): bool
    {
        return false;
    }
}
PHP;
        $filePath = "{$this->migrationsDir}/{$className}.php";
        file_put_contents($filePath, $phpCode);

        $migration = new Migration('20240101120000', 'Failing Migration', $filePath, MigrationType::PHP);
        $this->assertFalse($migration->up($connection));
    }

    /**
     * Test PHP migration that throws exception.
     */
    public function test_php_migration_throws_exception(): void
    {
        $connection = new Connection([
            Connection::OPT_DRIVER => DatabaseDriver::SQLITE->value,
            Connection::OPT_DATABASE => ':memory:',
        ]);

        $className = 'ExceptionMigration_'.uniqid();
        $phpCode = <<<PHP
<?php

use Databoss\ConnectionInterface;
use Kram\MigrationInterface;

class {$className} implements MigrationInterface
{
    public function up(ConnectionInterface \$connection): bool
    {
        throw new \RuntimeException('Migration failed intentionally');
    }

    public function down(ConnectionInterface \$connection): bool
    {
        throw new \RuntimeException('Rollback failed intentionally');
    }
}
PHP;
        $filePath = "{$this->migrationsDir}/{$className}.php";
        file_put_contents($filePath, $phpCode);

        $migration = new Migration('20240101120000', 'Exception Migration', $filePath, MigrationType::PHP);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Migration failed intentionally');
        $migration->up($connection);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_migration_with_ddl_operations(ConnectionInterface $connection): void
    {
        $this->cleanupTable($connection, 'users');
        // Clean up view if it exists
        try {
            $connection->execute('DROP VIEW IF EXISTS active_users');
        } catch (\Throwable) {
            // Ignore
        }
        $sql = <<<'SQL'
CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(255));
CREATE VIEW active_users AS SELECT * FROM users WHERE id > 0;
SQL;
        $basePath = "{$this->migrationsDir}/20240101120000_create_users_view";
        file_put_contents("{$basePath}.up.sql", $sql);
        file_put_contents("{$basePath}.down.sql", 'DROP VIEW IF EXISTS active_users; DROP TABLE users;');

        $migration = new Migration('20240101120000', 'Create Users View', $basePath, MigrationType::SQL);
        $this->assertTrue($migration->up($connection));

        // Verify view was created (SQLite specific check)
        if ($connection->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $result = $connection->query("SELECT name FROM sqlite_master WHERE type='view' AND name='active_users'");
            $this->assertNotFalse($result);
            $this->assertCount(1, $result);
        }
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_migration_with_data_manipulation(ConnectionInterface $connection): void
    {
        // Ensure table is dropped (try multiple times for PostgreSQL which may have locks)
        $maxAttempts = 3;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->cleanupTable($connection, 'users');
            // Check if table still exists
            try {
                $connection->query('SELECT 1 FROM users LIMIT 1');
                // Table still exists, wait a bit and try again
                if ($i < $maxAttempts - 1) {
                    usleep(50000); // 50ms
                    continue;
                }
            } catch (\PDOException) {
                // Table doesn't exist, we're good
                break;
            }
        }
        
        $sql = <<<'SQL'
CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(255));
INSERT INTO users (id, name) VALUES (1, 'John'), (2, 'Jane');
SQL;
        $basePath = "{$this->migrationsDir}/20240101120000_create_users_with_data";
        file_put_contents("{$basePath}.up.sql", $sql);
        file_put_contents("{$basePath}.down.sql", 'DROP TABLE users;');

        $migration = new Migration('20240101120000', 'Create Users With Data', $basePath, MigrationType::SQL);
        $this->assertTrue($migration->up($connection));

        // Verify data was inserted
        $users = $connection->query('SELECT * FROM users');
        $this->assertNotFalse($users);
        $this->assertCount(2, $users);
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

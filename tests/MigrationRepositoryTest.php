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
 * Class MigrationRepositoryTest
 *
 * Test suite for MigrationRepository class.
 */
class MigrationRepositoryTest extends TestCase
{
    /**
     * Clean up migrations table before each test.
     */
    private function cleanupMigrationsTable(ConnectionInterface $connection, ?string $tableName = null): void
    {
        $table = $tableName ?? 'kram_migrations';
        $driver = $connection->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        try {
            $escapedTable = $connection->escape($table, \Databoss\EscapeMode::COLUMN_OR_TABLE);
            match ($driver) {
                'mysql', 'pgsql' => $connection->execute("DROP TABLE IF EXISTS {$escapedTable}"),
                'sqlite' => $connection->execute("DROP TABLE IF EXISTS {$escapedTable}"),
                default => null,
            };
        } catch (\Throwable) {
            // Ignore errors - table might not exist
        }
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_initialize(ConnectionInterface $connection): void
    {
        $repository = new MigrationRepository($connection);
        $this->assertTrue($repository->initialize());
        $this->assertTrue($repository->initialize()); // Should be idempotent
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_record_and_check_execution(ConnectionInterface $connection): void
    {
        $this->cleanupMigrationsTable($connection);
        $repository = new MigrationRepository($connection);
        $repository->initialize();

        $this->assertFalse($repository->isExecuted('20240101120000'));
        $this->assertTrue($repository->recordExecution('20240101120000', 'Test Migration'));
        $this->assertTrue($repository->isExecuted('20240101120000'));
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_remove_execution(ConnectionInterface $connection): void
    {
        $this->cleanupMigrationsTable($connection);
        $repository = new MigrationRepository($connection);
        $repository->initialize();

        $repository->recordExecution('20240101120000', 'Test Migration');
        $this->assertTrue($repository->isExecuted('20240101120000'));

        $this->assertTrue($repository->removeExecution('20240101120000'));
        $this->assertFalse($repository->isExecuted('20240101120000'));
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_get_executed_versions(ConnectionInterface $connection): void
    {
        $this->cleanupMigrationsTable($connection);
        $repository = new MigrationRepository($connection);
        $repository->initialize();

        $this->assertEmpty($repository->getExecutedVersions());

        $repository->recordExecution('20240101120000', 'First Migration');
        $repository->recordExecution('20240101120001', 'Second Migration');

        $versions = $repository->getExecutedVersions();
        $this->assertCount(2, $versions);
        $this->assertEquals('20240101120000', $versions[0]);
        $this->assertEquals('20240101120001', $versions[1]);
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_get_latest_version(ConnectionInterface $connection): void
    {
        $this->cleanupMigrationsTable($connection);
        $repository = new MigrationRepository($connection);
        $repository->initialize();

        $this->assertNull($repository->getLatestVersion());

        $repository->recordExecution('20240101120000', 'First Migration');
        $this->assertEquals('20240101120000', $repository->getLatestVersion());

        $repository->recordExecution('20240101120001', 'Second Migration');
        $this->assertEquals('20240101120001', $repository->getLatestVersion());
    }

    /**
     * @dataProvider provideConnection
     */
    public function test_custom_table_name(ConnectionInterface $connection): void
    {
        $customTable = 'custom_migrations';
        $this->cleanupMigrationsTable($connection, $customTable);
        $this->cleanupMigrationsTable($connection); // Also clean default table
        $repository = new MigrationRepository($connection, $customTable);
        $repository->initialize();

        $repository->recordExecution('20240101120000', 'Test Migration');

        // Verify it's using custom table
        $this->assertTrue($repository->isExecuted('20240101120000'));

        // Verify default table doesn't have it
        $defaultRepository = new MigrationRepository($connection);
        $defaultRepository->initialize();
        $this->assertFalse($defaultRepository->isExecuted('20240101120000'));
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
    public function test_get_version_from_array(ConnectionInterface $connection): void
    {
        $this->cleanupMigrationsTable($connection);
        $repository = new MigrationRepository($connection);
        $repository->initialize();

        // Force array result by changing fetch mode
        $originalMode = $connection->pdo()->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE);
        $connection->pdo()->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $repository->recordExecution('20240101120000', 'Test');

        $versions = $repository->getExecutedVersions();
        $this->assertContains('20240101120000', $versions);

        $latest = $repository->getLatestVersion();
        $this->assertEquals('20240101120000', $latest);

        // Reset to original mode
        $connection->pdo()->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, $originalMode);
    }

    /**
     * Test that checkTableExists handles false result correctly.
     */
    public function test_check_table_exists_with_false_result(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn('sqlite');
        $connection->method('pdo')->willReturn($pdo);
        $connection->method('query')->willReturn(false); // Simulate query failure

        $repository = new MigrationRepository($connection);
        $reflection = new \ReflectionClass($repository);
        $method = $reflection->getMethod('checkTableExists');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($repository, 'SELECT 1'));
    }

    /**
     * Test that checkTableExists handles empty result correctly.
     */
    public function test_check_table_exists_with_empty_result(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn('sqlite');
        $connection->method('pdo')->willReturn($pdo);
        $connection->method('query')->willReturn([]); // Empty result

        $repository = new MigrationRepository($connection);
        $reflection = new \ReflectionClass($repository);
        $method = $reflection->getMethod('checkTableExists');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($repository, 'SELECT 1'));
    }

    /**
     * Test that checkTableExists handles count of zero correctly.
     */
    public function test_check_table_exists_with_zero_count(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn('sqlite');
        $connection->method('pdo')->willReturn($pdo);
        $connection->method('query')->willReturn([['count' => 0]]); // Count is 0

        $repository = new MigrationRepository($connection);
        $reflection = new \ReflectionClass($repository);
        $method = $reflection->getMethod('checkTableExists');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($repository, 'SELECT 1'));
    }

    /**
     * Test that checkTableExists handles object result with zero count.
     */
    public function test_check_table_exists_with_object_zero_count(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn('sqlite');
        $connection->method('pdo')->willReturn($pdo);

        $resultObj = new \stdClass;
        $resultObj->count = 0;
        $connection->method('query')->willReturn([$resultObj]);

        $repository = new MigrationRepository($connection);
        $reflection = new \ReflectionClass($repository);
        $method = $reflection->getMethod('checkTableExists');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($repository, 'SELECT 1'));
    }
}

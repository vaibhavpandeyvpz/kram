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
 * Interface MigrationInterface
 *
 * Defines the contract for PHP class-based migrations.
 * Classes implementing this interface can be used as migrations.
 */
interface MigrationInterface
{
    /**
     * Execute the migration up (forward) operation.
     *
     * @param  ConnectionInterface  $connection  Database connection
     *
     * @throws \Throwable If migration execution fails
     */
    public function up(ConnectionInterface $connection): void;

    /**
     * Execute the migration down (rollback) operation.
     *
     * @param  ConnectionInterface  $connection  Database connection
     *
     * @throws \Throwable If migration execution fails
     */
    public function down(ConnectionInterface $connection): void;
}

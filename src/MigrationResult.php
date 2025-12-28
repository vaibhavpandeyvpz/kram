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
 * Class MigrationResult
 *
 * Represents the result of a migration operation (migrate or rollback).
 * This is a value object that contains information about the operation's outcome.
 */
readonly class MigrationResult
{
    /**
     * Constructor.
     *
     * @param  bool  $success  Whether the operation was successful
     * @param  string  $message  Human-readable message describing the operation result
     * @param  array<int, string>  $executed  Array of migration versions that were executed (for migrate operations)
     * @param  array<int, string>  $rolledBack  Array of migration versions that were rolled back (for rollback operations)
     */
    public function __construct(
        public bool $success,
        public string $message,
        public array $executed = [],
        public array $rolledBack = []
    ) {}
}

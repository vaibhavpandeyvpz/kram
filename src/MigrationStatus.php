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
 * Class MigrationStatus
 *
 * Represents the current status of migrations (executed and pending).
 * This is a value object that provides a snapshot of the migration state.
 */
readonly class MigrationStatus
{
    /**
     * Constructor.
     *
     * @param  array<int, Migration>  $executed  Array of migrations that have been executed
     * @param  array<int, Migration>  $pending  Array of migrations that are pending (not yet executed)
     * @param  string|null  $error  Optional error message if status check failed
     */
    public function __construct(
        public array $executed,
        public array $pending,
        public ?string $error = null
    ) {}
}

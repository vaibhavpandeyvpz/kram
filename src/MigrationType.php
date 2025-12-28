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
 * Enum MigrationType
 *
 * Represents the type of migration (SQL file-based or PHP class-based).
 */
enum MigrationType: string
{
    /** SQL file-based migration */
    case SQL = 'sql';

    /** PHP class-based migration */
    case PHP = 'php';

    /**
     * Check if this is a SQL migration.
     *
     * @return bool True if this is a SQL migration, false otherwise
     */
    public function isSql(): bool
    {
        return $this === self::SQL;
    }

    /**
     * Check if this is a PHP migration.
     *
     * @return bool True if this is a PHP migration, false otherwise
     */
    public function isPhp(): bool
    {
        return $this === self::PHP;
    }
}

<?php

declare(strict_types=1);

namespace App\Persistence;

use InvalidArgumentException;

final class PlatformFactory
{
    /**
     * @param string $driver "pgsql" or "mysql" (MariaDB uses the MySQL driver).
     */
    public static function create(string $driver): Platform
    {
        return match (strtolower(trim($driver))) {
            'pgsql', 'postgres', 'postgresql' => new PostgresPlatform(),
            'mysql', 'mariadb' => new MySqlPlatform(),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported database driver "%s". Use pgsql or mysql. SQLite is not supported.',
                $driver,
            )),
        };
    }
}

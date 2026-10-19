<?php

declare(strict_types=1);

namespace Lumynus\Lumy\Connections;

use Lumynus\Lumy\Contracts\DatabaseDriverInterface;
use Lumynus\Lumy\Drivers\IbaseDriver;
use Lumynus\Lumy\Drivers\MysqlDriver;
use Lumynus\Lumy\Drivers\PdoDriver;

/**
 * Base class for creating and managing database connections.
 *
 * Connections are reused based on their configuration and connection identifier.
 * Extend this class to provide an application-specific connection manager.
 */
abstract class LumyConnection
{
    /**
     * Active connections indexed by a hash of their configuration.
     *
     * @var array<string, array{
     *     connection: DatabaseDriverInterface,
     *     identifier: string,
     *     credentialHash: string
     * }>
     */
    protected static array $connections = [];

    /**
    * Creates a database connection or returns a matching connection from the pool.
     *
     * Supported types are mysql, mariadb, postgresql, sqlite, sqlserver,
     * ibase, and firebird. For SQLite, $dataBase is the file path; $host is
     * used as a fallback when $dataBase is empty.
     *
     * @param string $type Database driver name.
     * @param string $host Database server host, or the SQLite path fallback.
     * @param string $user Database username; ignored by SQLite.
     * @param mixed $password Database password; ignored by SQLite.
     * @param string $dataBase Database name or SQLite file path.
     * @param bool $newConnection Whether to bypass reuse of a pooled connection.
     * @param string $connectionIdentifier Logical name used to distinguish connections.
     *
     * @return DatabaseDriverInterface The connected driver instance.
     *
     * @throws \InvalidArgumentException If the driver is unsupported or SQLite has no path.
     * @throws \Throwable If the underlying driver cannot establish a connection.
     */
    public function connect(
        string $type,
        string $host,
        string $user,
        #[\SensitiveParameter]
        mixed $password,
        string $dataBase,
        bool $newConnection = false,
        string $connectionIdentifier = 'default'
    ): DatabaseDriverInterface {
        [$type, $host, $user, $password, $dataBase] = self::normalizeConfiguration(
            $type,
            $host,
            $user,
            $password,
            $dataBase
        );

        $key = self::makeKey(
            $type,
            $host,
            $user,
            $dataBase,
            $connectionIdentifier
        );
        $credentialHash = hash('sha256', serialize($password));

        if (!$newConnection && isset(self::$connections[$key])) {
            if (self::$connections[$key]['credentialHash'] === $credentialHash) {
                return self::$connections[$key]['connection'];
            }

            unset(self::$connections[$key]);
        }

        $connection = match ($type) {
            'mysql', 'mariadb' => extension_loaded('mysqli')
                ? new MysqlDriver($host, $user, $password, $dataBase)
                : new PdoDriver('mysql', $host, $user, $password, $dataBase),
            'pdo_mysql' => new PdoDriver(
                'mysql',
                $host,
                $user,
                $password,
                $dataBase
            ),
            'postgresql', 'sqlite', 'sqlserver' => new PdoDriver(
                $type,
                $host,
                $user,
                $password,
                $dataBase
            ),
            'ibase', 'firebird' => function_exists('ibase_connect')
                ? new IbaseDriver($host, $user, $password, $dataBase)
                : new PdoDriver('firebird', $host, $user, $password, $dataBase),
            'pdo_firebird' => new PdoDriver(
                'firebird',
                $host,
                $user,
                $password,
                $dataBase
            ),
            default => throw new \InvalidArgumentException(
                "Unsupported database type: {$type}"
            ),
        };

        self::$connections[$key] = [
            'connection' => $connection,
            'identifier' => $connectionIdentifier,
            'credentialHash' => $credentialHash,
        ];

        return $connection;
    }

    /**
     * Normalizes driver-specific configuration before pooling and connection.
     *
     * SQLite treats the database name as its file path and does not use host
     * credentials. The host remains a fallback for the previous call format.
     *
     * @param string $type Database driver name.
     * @param string $host Database server host or SQLite path fallback.
     * @param string $user Database username.
     * @param mixed $password Database password.
     * @param string $dataBase Database name or SQLite file path.
     *
     * @return array{string, string, string, mixed, string} Normalized values in the same argument order.
     *
     * @throws \InvalidArgumentException If SQLite or Firebird has no database path.
     */
    private static function normalizeConfiguration(
        string $type,
        string $host,
        string $user,
        #[\SensitiveParameter]
        mixed $password,
        string $dataBase
    ): array {
        $type = match (strtolower(trim($type))) {
            'pgsql', 'postgres' => 'postgresql',
            'sqlsrv', 'mssql' => 'sqlserver',
            default => strtolower(trim($type)),
        };

        if ($type === 'sqlite') {
            $dataBase = $dataBase !== '' ? $dataBase : $host;
            if ($dataBase === '') {
                throw new \InvalidArgumentException(
                    'SQLite requires a database file path.'
                );
            }

            $host = '';
            $user = '';
            $password = null;
        } elseif (in_array($type, ['ibase', 'firebird', 'pdo_firebird'], true)) {
            if ($dataBase === '' && $host !== '') {
                $dataBase = $host;
                $host = '';
            }
            if ($dataBase === '') {
                throw new \InvalidArgumentException(
                    'Firebird/InterBase requires a database name or file path.'
                );
            }
        }

        return [$type, $host, $user, $password, $dataBase];
    }

    /**
     * Builds a stable pool key without changing the case of connection values.
     *
     * @param string $type Normalized database driver name.
     * @param string $host Database server host.
     * @param string $user Database username.
     * @param string $dataBase Database name or SQLite file path.
     * @param string $connectionIdentifier Logical connection name.
     *
     * @return string SHA-256 hash used as the connection pool key.
     */
    protected static function makeKey(
        string $type,
        string $host,
        string $user,
        string $dataBase,
        string $connectionIdentifier
    ): string {
        return hash('sha256', serialize([
            strtolower($type),
            $host,
            $user,
            $dataBase,
            $connectionIdentifier,
        ]));
    }

    /**
     * Removes a connection from the pool using its configuration.
     *
     * For SQLite, provide the file path as $dataBase or use $host as the
     * fallback path. The identifier must match the one used by connect().
     *
     * @param string $type Database driver name.
     * @param string $host Database server host or SQLite path fallback.
     * @param string $user Database username; ignored by SQLite.
     * @param string $dataBase Database name or SQLite file path.
     * @param string $connectionIdentifier Logical connection name.
     *
     * @return bool True if a matching pool entry was removed; otherwise false.
     */
    public static function closeConnection(
        string $type,
        string $host,
        string $user,
        string $dataBase,
        string $connectionIdentifier
    ): bool {
        [$type, $host, $user, , $dataBase] = self::normalizeConfiguration(
            $type,
            $host,
            $user,
            null,
            $dataBase
        );

        $key = self::makeKey(
            $type,
            $host,
            $user,
            $dataBase,
            $connectionIdentifier
        );

        if (!isset(self::$connections[$key])) {
            return false;
        }

        unset(self::$connections[$key]);

        return true;
    }

    /**
     * Removes all pooled connections with the given logical identifier.
     *
     * @param string $connectionIdentifier Identifier supplied to connect().
     *
     * @return int Number of pool entries removed.
     */
    public static function closeByIdentifier(string $connectionIdentifier): int
    {
        $closed = 0;

        foreach (self::$connections as $key => $data) {
            if ($data['identifier'] === $connectionIdentifier) {
                unset(self::$connections[$key]);
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Removes a connection from the pool by object identity.
     *
     * @param object $connection Driver instance returned by connect().
     *
     * @return bool True if the instance was found and removed; otherwise false.
     */
    public static function closeInstance(object $connection): bool
    {
        foreach (self::$connections as $key => $data) {
            if ($data['connection'] === $connection) {
                unset(self::$connections[$key]);

                return true;
            }
        }

        return false;
    }

    /**
     * Removes every connection from the pool.
     *
     * Driver resources are released when their objects are destroyed, which
     * may happen later if callers still hold references to them.
     *
     * @return void
     */
    public static function closeAll(): void
    {
        self::$connections = [];
    }

    /**
     * Returns the number of connections currently stored in the pool.
     *
     * @return int Number of pooled connections.
     */
    public static function poolSize(): int
    {
        return count(self::$connections);
    }

    /**
     * Returns the unique logical identifiers currently in the pool.
     *
     * @return list<string> Unique connection identifiers.
     */
    public static function identifiers(): array
    {
        return array_values(array_unique(
            array_column(self::$connections, 'identifier')
        ));
    }
}
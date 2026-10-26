<?php

declare(strict_types=1);

namespace Lumynus\Lumy;

/**
 * Stores database configurations in memory for the current PHP process.
 *
 * Configurations are indexed by connection identifier and are available to
 * every model through the static accessors. Instantiating this class registers
 * a configuration for the current PHP process.
 */
final class Config
{
    /**
     * @var array<string, array{
     *     driver: string,
     *     host: string,
     *     user: string,
     *     password: mixed,
     *     database: string,
     *     newConnection: bool,
     *     migrationsPath: string|null,
     *     options: array
     * }>
     */
    private static array $configurations = [];

    /**
     * Registers a database configuration in shared memory.
     *
     * @param string $driver Database driver name.
     * @param string $host Database host, or SQLite path fallback.
     * @param string $user Database username.
     * @param mixed $password Database password.
     * @param string $database Database name or SQLite file path.
     * @param bool $newConnection Whether to bypass connection-pool reuse.
     * @param string $connectionIdentifier Identifier models use to select this configuration.
     * @param string|null $migrationsPath Directory containing this connection's SQL migrations.
     * @param array $options Extra driver options: PDO attribute constants (PDO::ATTR_*)
     *        for PdoDriver, and an "ssl" array (key, cert, ca, capath, cipher) for
     *        MysqlDriver. PostgreSQL also accepts the string key "sslmode".
     */
    public function __construct(
        string $driver = 'mysql',
        string $host = 'localhost',
        string $user = 'root',
        #[\SensitiveParameter]
        mixed $password = '',
        string $database = 'lumy',
        bool $newConnection = false,
        string $connectionIdentifier = 'default',
        ?string $migrationsPath = null,
        array $options = []
    ) {
        self::setConnection(
            $connectionIdentifier,
            $driver,
            $host,
            $user,
            $password,
            $database,
            $newConnection,
            $migrationsPath,
            $options
        );
    }

    /**
     * Registers or replaces a named database configuration.
     *
     * @param string $connectionIdentifier Configuration name used by models.
     * @param string $driver Database driver name.
     * @param string $host Database host, or SQLite path fallback.
     * @param string $user Database username.
     * @param mixed $password Database password.
     * @param string $database Database name or SQLite file path.
     * @param bool $newConnection Whether to bypass connection-pool reuse.
     * @param string|null $migrationsPath Directory containing this connection's SQL migrations.
     * @param array $options Extra driver options; see the constructor for the accepted shapes.
     */
    public static function setConnection(
        string $connectionIdentifier,
        string $driver = 'mysql',
        string $host = 'localhost',
        string $user = 'root',
        #[\SensitiveParameter]
        mixed $password = '',
        string $database = 'lumy',
        bool $newConnection = false,
        ?string $migrationsPath = null,
        array $options = []
    ): void {
        self::$configurations[$connectionIdentifier] = [
            'driver' => $driver,
            'host' => $host,
            'user' => $user,
            'password' => $password,
            'database' => $database,
            'newConnection' => $newConnection,
            'migrationsPath' => $migrationsPath,
            'options' => $options,
        ];
    }

    /**
     * Returns a named database configuration.
     *
     * @param string $connectionIdentifier Configuration name; defaults to "default".
     *
     * @return array{driver: string, host: string, user: string, password: mixed, database: string, newConnection: bool, migrationsPath: string|null, options: array}
     *
     * @throws \InvalidArgumentException If the identifier has not been configured.
     */
    public static function getConnection(string $connectionIdentifier = 'default'): array
    {
        if (!isset(self::$configurations[$connectionIdentifier])) {
            throw new \InvalidArgumentException(
                "Database configuration '{$connectionIdentifier}' is not defined."
            );
        }

        return self::$configurations[$connectionIdentifier];
    }

    /**
     * Checks whether a named database configuration exists.
     *
     * @param string $connectionIdentifier Configuration name.
     */
    public static function hasConnection(string $connectionIdentifier = 'default'): bool
    {
        return isset(self::$configurations[$connectionIdentifier]);
    }

    /** Removes all configurations from memory. */
    public static function clear(): void
    {
        self::$configurations = [];
    }
}

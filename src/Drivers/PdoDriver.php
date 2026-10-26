<?php

declare(strict_types=1);

namespace Lumynus\Lumy\Drivers;

use Lumynus\Lumy\Contracts\DatabaseDriverInterface;

/**
 * PDO driver for SQLite, PostgreSQL, Microsoft SQL Server, and MySQL databases.
 *
 * The matching PDO extension (pdo_sqlite, pdo_pgsql, pdo_sqlsrv, or pdo_mysql)
 * must be enabled in the PHP installation.
 */
final class PdoDriver implements DatabaseDriverInterface
{
    /** PDO driver type selected for this connection. */
    private string $type;

    /** Active PDO connection. */
    private \PDO $pdo;

    /** Most recently executed statement, used for affected-row inspection. */
    private ?\PDOStatement $stmt = null;

    /** Current transaction nesting depth; 0 means no active transaction. */
    private int $transactionDepth = 0;

    /** Original constructor arguments kept so the connection can be reopened. */
    private array $connectionArgs;

    /** Version string reported by a MySQL server, sniffed once on demand. */
    private ?string $serverVersion = null;

    /** Whether the MySQL server version has already been queried. */
    private bool $versionSniffed = false;

    /**
     * Opens a PDO connection for a supported database type.
     *
     * @param string $type Database type: sqlite, postgresql, sqlserver, mysql, or firebird.
     * @param string $host Database server host; unused by SQLite.
     * @param string $user Database username; unused by SQLite.
     * @param mixed $password Database password; unused by SQLite.
     * @param string $database The database name or SQLite/Firebird file path.
     * @param array $options Extra PDO attributes (PDO::ATTR_* integer keys). For
     *        PostgreSQL the string key "sslmode" is also accepted and must hold
     *        one of: disable, allow, prefer, require, verify-ca, verify-full.
     *
     * @throws \InvalidArgumentException If the database type is unsupported.
     * @throws \PDOException If the PDO connection cannot be established.
     */
    public function __construct(
        string $type,
        string $host,
        string $user,
        #[\SensitiveParameter]
        mixed $password,
        string $database,
        array $options = []
    ) {
        $this->type = match (strtolower(trim($type))) {
            'sqlite' => 'sqlite',
            'postgresql', 'pgsql', 'postgres' => 'postgresql',
            'sqlserver', 'sqlsrv', 'mssql' => 'sqlserver',
            'mysql', 'mariadb', 'pdo_mysql' => 'mysql',
            'firebird', 'ibase', 'pdo_firebird' => 'firebird',
            default => throw new \InvalidArgumentException(
                "Unsupported PDO database type: {$type}"
            ),
        };

        $this->connectionArgs = compact('type', 'host', 'user', 'password', 'database', 'options');
        $this->openConnection();
    }

    private function openConnection(): void
    {
        ['type' => $type, 'host' => $host, 'user' => $user, 'password' => $password, 'database' => $database, 'options' => $options]
            = $this->connectionArgs;

        $dsn = self::buildDsn($this->type, $host, $database, $options);
        $pdoOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        if ($this->type === 'firebird') {
            $pdoOptions[\PDO::ATTR_CASE] = \PDO::CASE_LOWER;
        }
        if ($this->type === 'mysql') {
            // Real server-side prepared statements: required so a raw WHERE
            // expression can never turn into stacked (multi-statement) queries.
            $pdoOptions[\PDO::ATTR_EMULATE_PREPARES] = false;
            if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                $pdoOptions[\PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
            }
        }

        // Only integer keys are valid PDO::ATTR_* options; string keys such as
        // "sslmode" are consumed while building the DSN and never reach PDO.
        $userOptions = array_filter(
            $options,
            static fn (mixed $key): bool => is_int($key),
            ARRAY_FILTER_USE_KEY
        );

        $this->pdo = new \PDO(
            $dsn,
            $this->type === 'sqlite' ? null : $user,
            $this->type === 'sqlite' || $password === null ? null : (string) $password,
            $pdoOptions + $userOptions
        );
        $this->transactionDepth = 0;
        $this->stmt = null;
    }

    /**
     * @param array $options
     * @throws \InvalidArgumentException If host or database contain characters
     *         capable of injecting additional DSN parameters.
     */
    private static function buildDsn(string $type, string $host, string $database, array $options = []): string
    {
        if ($type === 'sqlite') {
            if ($database === '') {
                throw new \InvalidArgumentException('SQLite requires a database file path.');
            }

            return "sqlite:{$database}";
        }

        self::assertSafeDsnValue($host, 'host');
        self::assertSafeDsnValue($database, 'database');

        if ($type === 'firebird') {
            $target = $host !== '' ? "{$host}:{$database}" : $database;

            return "firebird:dbname={$target};charset=UTF8";
        }

        [$hostname, $port] = self::splitHostPort($host);

        return match ($type) {
            'postgresql' => 'pgsql:host=' . $hostname
                . ($port !== null ? ";port={$port}" : '')
                . ";dbname={$database}"
                . self::pgsqlSslModeParam($options),
            'mysql' => 'mysql:host=' . $hostname
                . ($port !== null ? ";port={$port}" : '')
                . ";dbname={$database};charset=utf8mb4",
            'sqlserver' => 'sqlsrv:Server=' . $hostname
                . ($port !== null ? ",{$port}" : '')
                . ";Database={$database}",
            default => throw new \InvalidArgumentException(
                "Unsupported PDO database type: {$type}"
            ),
        };
    }

    /** Rejects characters that could inject extra DSN parameters. */
    private static function assertSafeDsnValue(string $value, string $label): void
    {
        if (preg_match('/[;\x00\r\n]/', $value) === 1) {
            throw new \InvalidArgumentException(
                "The {$label} contains characters that are not allowed in a DSN."
            );
        }
    }

    /** Builds the ";sslmode=..." fragment from the whitelisted option value. */
    private static function pgsqlSslModeParam(array $options): string
    {
        if (!isset($options['sslmode'])) {
            return '';
        }

        $mode = (string) $options['sslmode'];
        $allowed = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
        if (!in_array($mode, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Unsupported PostgreSQL sslmode: {$mode}."
            );
        }

        return ";sslmode={$mode}";
    }

    /** @return array{string, int|null} */
    private static function splitHostPort(string $host): array
    {
        $host = trim($host);
        if (preg_match('/\A\[([^\]]+)\]:(\d+)\z/', $host, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }
        if (substr_count($host, ':') === 1 && preg_match('/\A([^:]+):(\d+)\z/', $host, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }

        return [$host, null];
    }

    /**
     * Inserts or updates using the database's native upsert statement.
     *
     * @param string $table Table name.
     * @param array<string, mixed> $data Column values.
     * @param list<string> $uniqueBy Unique-key columns.
     */
    public function upsert(string $table, array $data, array $uniqueBy): bool
    {
        [$columns, $uniqueBy] = self::validateUpsert($table, $data, $uniqueBy);
        $updates = array_values(array_diff($columns, $uniqueBy));
        $values = array_values($data);

        if ($this->type === 'sqlserver') {
            $quotedTable = "[{$table}]";
            $quotedColumns = array_map(static fn (string $column): string => "[{$column}]", $columns);
            $sourceColumns = implode(', ', $quotedColumns);
            $sourceValues = implode(', ', array_fill(0, count($columns), '?'));
            $conditions = implode(' AND ', array_map(
                static fn (string $column): string => "target.[{$column}] = source.[{$column}]",
                $uniqueBy
            ));
            $sql = "MERGE {$quotedTable} WITH (HOLDLOCK) AS target "
                . "USING (VALUES ({$sourceValues})) AS source ({$sourceColumns}) "
                . "ON {$conditions} ";

            if ($updates !== []) {
                $assignments = implode(', ', array_map(
                    static fn (string $column): string => "target.[{$column}] = source.[{$column}]",
                    $updates
                ));
                $sql .= "WHEN MATCHED THEN UPDATE SET {$assignments} ";
            }

            $insertColumns = implode(', ', $quotedColumns);
            $insertValues = implode(', ', array_map(
                static fn (string $column): string => "source.[{$column}]",
                $columns
            ));
            $sql .= "WHEN NOT MATCHED THEN INSERT ({$insertColumns}) "
                . "VALUES ({$insertValues});";
        } elseif ($this->type === 'mysql') {
            $columnList = implode(', ', $columns);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            if ($updates === []) {
                $sql = "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders}) "
                    . "ON DUPLICATE KEY UPDATE {$uniqueBy[0]} = {$uniqueBy[0]}";
            } elseif ($this->supportsUpsertRowAlias()) {
                $assignments = implode(', ', array_map(
                    static fn (string $column): string => "{$column} = new.{$column}",
                    $updates
                ));
                $sql = "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders}) AS new "
                    . "ON DUPLICATE KEY UPDATE {$assignments}";
            } else {
                $assignments = implode(', ', array_map(
                    static fn (string $column): string => "{$column} = VALUES({$column})",
                    $updates
                ));
                $sql = "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders}) "
                    . "ON DUPLICATE KEY UPDATE {$assignments}";
            }
        } elseif ($this->type === 'firebird') {
            $columnList = implode(', ', $columns);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $matching = implode(', ', $uniqueBy);
            $sql = "UPDATE OR INSERT INTO {$table} ({$columnList}) "
                . "VALUES ({$placeholders}) MATCHING ({$matching})";
        } else {
            $columnList = implode(', ', $columns);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $conflictColumns = implode(', ', $uniqueBy);
            $sql = "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders}) "
                . "ON CONFLICT ({$conflictColumns}) ";

            if ($updates === []) {
                $sql .= 'DO NOTHING';
            } else {
                $assignments = implode(', ', array_map(
                    static fn (string $column): string => "{$column} = excluded.{$column}",
                    $updates
                ));
                $sql .= "DO UPDATE SET {$assignments}";
            }
        }

        return $this->query($sql, $values) === true;
    }

    /**
     * Whether the MySQL server supports the row alias form of upsert
     * (MySQL >= 8.0.19; MariaDB always uses the legacy VALUES() form).
     */
    private function supportsUpsertRowAlias(): bool
    {
        if (!$this->versionSniffed) {
            $this->versionSniffed = true;
            try {
                $this->serverVersion = (string) $this->pdo
                    ->query('SELECT VERSION()')
                    ->fetchColumn();
            } catch (\Throwable) {
                $this->serverVersion = null;
            }
        }

        if ($this->serverVersion === null || stripos($this->serverVersion, 'mariadb') !== false) {
            return false;
        }

        return version_compare(explode('-', $this->serverVersion)[0], '8.0.19', '>=');
    }

    /** @return array{list<string>, list<string>} */
    private static function validateUpsert(string $table, array $data, array $uniqueBy): array
    {
        $columns = array_keys($data);
        foreach ([$table, ...$columns, ...$uniqueBy] as $identifier) {
            if (!is_string($identifier) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier)) {
                throw new \InvalidArgumentException('Invalid upsert SQL identifier.');
            }
        }

        if ($data === [] || $uniqueBy === [] || array_diff($uniqueBy, $columns) !== []) {
            throw new \InvalidArgumentException('Upsert requires data and unique columns included in that data.');
        }

        return [array_values($columns), array_values($uniqueBy)];
    }

    /**
     * Prepares and executes a statement using PDO parameter binding.
     *
     * The $types argument is accepted for interface compatibility and ignored;
     * PDO determines parameter types from the supplied values.
     *
     * A lost server connection is retried once when no transaction is active.
     *
     * @param string $query SQL statement with PDO placeholders.
     * @param array $params Values bound to the statement placeholders.
     * @param string|array $types Unused by this PDO implementation.
     *
     * @return array|bool Associative result rows, or true when the statement returns no rows.
     *
     * @throws \PDOException If preparing or executing the statement fails.
     */
    public function query(
        string $query,
        array $params = [],
        string|array $types = ''
    ): array|bool {
        try {
            return $this->executeStatement($query, $params);
        } catch (\PDOException $exception) {
            if ($this->transactionDepth === 0 && self::isLostConnectionError($exception)) {
                $this->openConnection();

                return $this->executeStatement($query, $params);
            }

            throw $exception;
        }
    }

    private function executeStatement(string $query, array $params): array|bool
    {
        $this->stmt = $this->pdo->prepare($query);

        $position = 1;
        foreach ($params as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            $paramType = match (true) {
                $value === null => \PDO::PARAM_NULL,
                is_bool($value) => \PDO::PARAM_BOOL,
                is_int($value) => \PDO::PARAM_INT,
                default => \PDO::PARAM_STR,
            };

            if (is_string($key)) {
                $namedKey = str_starts_with($key, ':') ? $key : ':' . $key;
                if (preg_match('/' . preg_quote($namedKey, '/') . '\b/', $query) === 1) {
                    $this->stmt->bindValue($namedKey, $value, $paramType);
                    continue;
                }

                throw new \InvalidArgumentException(
                    "Named parameter {$namedKey} was not found in the query."
                );
            }

            $this->stmt->bindValue($position, $value, $paramType);
            $position++;
        }

        $this->stmt->execute();

        return $this->stmt->columnCount() > 0
            ? $this->stmt->fetchAll()
            : true;
    }

    /** Whether the exception reports a connection that dropped or was closed. */
    private static function isLostConnectionError(\PDOException $exception): bool
    {
        $sqlstate = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if (in_array($sqlstate, ['08S01', '08003', '08006', '57P01'], true)) {
            return true;
        }

        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($driverCode, [2006, 2013, 10054, 10053], true);
    }

    /**
     * Starts a PDO transaction or, when one is already active, a savepoint.
     *
     * Nested begin/commit/rollBack pairs map to SAVEPOINT, RELEASE SAVEPOINT
     * and ROLLBACK TO SAVEPOINT (SAVE TRANSACTION on SQL Server, which has no
     * release statement).
     */
    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
            $this->transactionDepth = 1;

            return;
        }

        $name = 'lumy_sp_' . $this->transactionDepth;
        $this->pdo->exec(
            $this->type === 'sqlserver'
                ? 'SAVE TRANSACTION ' . $name
                : 'SAVEPOINT ' . $name
        );
        $this->transactionDepth++;
    }

    /** Commits the active transaction or releases the innermost savepoint. */
    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            throw new \RuntimeException('There is no active transaction to commit.');
        }

        if ($this->transactionDepth === 1) {
            $this->pdo->commit();
            $this->transactionDepth = 0;

            return;
        }

        $this->transactionDepth--;
        if ($this->type !== 'sqlserver') {
            $this->pdo->exec('RELEASE SAVEPOINT lumy_sp_' . $this->transactionDepth);
        }
    }

    /** Rolls back the active transaction or to the innermost savepoint. */
    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            throw new \RuntimeException('There is no active transaction to roll back.');
        }

        if ($this->transactionDepth === 1) {
            $this->pdo->rollBack();
            $this->transactionDepth = 0;

            return;
        }

        $this->transactionDepth--;
        $name = 'lumy_sp_' . $this->transactionDepth;
        $this->pdo->exec(
            $this->type === 'sqlserver'
                ? 'ROLLBACK TRANSACTION ' . $name
                : 'ROLLBACK TO SAVEPOINT ' . $name
        );
        if ($this->type !== 'sqlserver') {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $name);
        }
    }

    /**
     * Returns the identifier generated by the most recent INSERT.
     *
     * @return int Generated identifier cast to an integer, or 0 if unavailable.
     */
    public function getInsertId(): int
    {
        try {
            return (int) $this->pdo->lastInsertId();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getDriverName(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Returns the affected-row count of the most recently executed statement.
     *
     * @return int Number of affected rows, or 0 before a statement is executed.
     */
    public function getAffectedRows(): int
    {
        return $this->stmt?->rowCount() ?? 0;
    }

    /**
     * Returns a server connection identifier where the PDO driver supports it.
     *
     * SQLite has no server thread identifier. Query or driver errors are
     * suppressed and reported as null.
     *
     * @return int|null Connection identifier, or null when unsupported/unavailable.
     */
    public function getThreadId(): ?int
    {
        try {
            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            return match ($driver) {
                'mysql' => (int) $this->pdo
                    ->query('SELECT CONNECTION_ID()')
                    ->fetchColumn(),
                'pgsql' => (int) $this->pdo
                    ->query('SELECT pg_backend_pid()')
                    ->fetchColumn(),
                'sqlsrv' => (int) $this->pdo
                    ->query('SELECT @@SPID')
                    ->fetchColumn(),
                'firebird' => (int) $this->pdo
                    ->query('SELECT CURRENT_CONNECTION FROM RDB$DATABASE')
                    ->fetchColumn(),
                'sqlite' => null,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }
}

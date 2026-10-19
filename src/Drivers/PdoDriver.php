<?php

declare(strict_types=1);

namespace Lumynus\Lumy\Drivers;

use Lumynus\Lumy\Contracts\DatabaseDriverInterface;

/**
 * PDO driver for SQLite, PostgreSQL, and Microsoft SQL Server databases.
 *
 * The matching PDO extension (pdo_sqlite, pdo_pgsql, or pdo_sqlsrv) must be
 * enabled in the PHP installation.
 */
final class PdoDriver implements DatabaseDriverInterface
{
    /** PDO driver type selected for this connection. */
    private string $type;

    /** Active PDO connection. */
    private \PDO $pdo;

    /** Most recently executed statement, used for affected-row inspection. */
    private ?\PDOStatement $stmt = null;

    /**
     * Opens a PDO connection for a supported database type.
     *
     * @param string $type Database type: sqlite, postgresql, sqlserver, mysql, or firebird.
     * @param string $host Database server host; unused by SQLite.
     * @param string $user Database username; unused by SQLite.
     * @param mixed $password Database password; unused by SQLite.
     * @param string $database The database name or SQLite/Firebird file path.
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
        string $database
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

        $dsn = self::buildDsn($this->type, $host, $database);
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        if ($this->type === 'firebird') {
            $options[\PDO::ATTR_CASE] = \PDO::CASE_LOWER;
        }

        $this->pdo = new \PDO(
            $dsn,
            $this->type === 'sqlite' ? null : $user,
            $this->type === 'sqlite' || $password === null ? null : (string) $password,
            $options
        );
    }

    private static function buildDsn(string $type, string $host, string $database): string
    {
        if ($type === 'sqlite') {
            if ($database === '') {
                throw new \InvalidArgumentException('SQLite requires a database file path.');
            }

            return "sqlite:{$database}";
        }

        if ($type === 'firebird') {
            $target = $host !== '' ? "{$host}:{$database}" : $database;

            return "firebird:dbname={$target};charset=UTF8";
        }

        [$hostname, $port] = self::splitHostPort($host);

        return match ($type) {
            'postgresql' => 'pgsql:host=' . $hostname
                . ($port !== null ? ";port={$port}" : '')
                . ";dbname={$database}",
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
            if ($updates === []) {
                $assignments = "{$uniqueBy[0]} = {$uniqueBy[0]}";
            } else {
                $assignments = implode(', ', array_map(
                    static fn (string $column): string => "{$column} = VALUES({$column})",
                    $updates
                ));
            }
            $columnList = implode(', ', $columns);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders}) "
                . "ON DUPLICATE KEY UPDATE {$assignments}";
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
        $this->stmt = $this->pdo->prepare($query);

        $position = 1;
        foreach ($params as $key => $value) {
            $paramType = match (true) {
                $value === null => \PDO::PARAM_NULL,
                is_bool($value) => \PDO::PARAM_BOOL,
                is_int($value) => \PDO::PARAM_INT,
                default => \PDO::PARAM_STR,
            };

            if (is_string($key)) {
                $namedKey = str_starts_with($key, ':') ? $key : ':' . $key;
                if (str_contains($query, $namedKey)) {
                    $this->stmt->bindValue($namedKey, $value, $paramType);
                    continue;
                }
            }

            $this->stmt->bindValue($position, $value, $paramType);
            $position++;
        }

        $this->stmt->execute();

        return $this->stmt->columnCount() > 0
            ? $this->stmt->fetchAll()
            : true;
    }

    /** Starts a PDO transaction. */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /** Commits the active PDO transaction. */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /** Rolls back the active PDO transaction. */
    public function rollBack(): void
    {
        $this->pdo->rollBack();
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
<?php

declare(strict_types=1);

namespace Lumynus\Lumy\Drivers;

use Lumynus\Lumy\Contracts\DatabaseDriverInterface;

/**
 * MySQL and MariaDB driver implemented with the MySQLi extension.
 *
 * The MySQLi extension must be enabled in the PHP installation.
 */
final class MysqlDriver implements DatabaseDriverInterface
{
    /** Active MySQLi connection. */
    private \mysqli $mysql;

    /** Most recently prepared statement, used for affected-row inspection. */
    private ?\mysqli_stmt $stmt = null;

    /**
     * Opens a MySQLi connection.
     *
     * @param string $host Database server host.
     * @param string $user Database username.
     * @param mixed $password Database password.
     * @param string $database Database name.
     *
     * @throws \mysqli_sql_exception If the connection cannot be established.
     */
    public function __construct(
        string $host,
        string $user,
        #[\SensitiveParameter]
        mixed $password,
        string $database
    ) {
        [$hostname, $port] = self::splitHostPort($host);
        $pass = $password === null ? null : (string) $password;
        $this->mysql = $port !== null
            ? new \mysqli($hostname, $user, $pass, $database, $port)
            : new \mysqli($hostname, $user, $pass, $database);

        if ($this->mysql->connect_errno !== 0) {
            throw new \RuntimeException((string) $this->mysql->connect_error);
        }

        $this->mysql->set_charset('utf8mb4');
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
     * Executes a prepared statement and fetches rows when it returns a result set.
     *
     * @param string $query SQL statement with MySQLi placeholders.
     * @param array $params Positional values to bind to the statement.
     * @param string|array $types MySQLi bind types, such as "sis"; array values are concatenated.
     *
     * @return array|bool Associative result rows, or true when the statement returns no rows.
     *
     * @throws \RuntimeException If the statement cannot be prepared.
     * @throws \mysqli_sql_exception If binding or execution fails.
     */
    public function query(
        string $query,
        array $params = [],
        string|array $types = ''
    ): array|bool {
        $this->stmt = $this->mysql->prepare($query);
        if (!$this->stmt) {
            throw new \RuntimeException($this->mysql->error);
        }

        if ($params !== []) {
            $bindParams = array_values($params);
            $bindTypes = is_array($types) ? implode('', $types) : $types;

            if ($bindTypes === '') {
                $inferredTypes = '';
                foreach ($bindParams as $index => $value) {
                    if (is_bool($value)) {
                        $bindParams[$index] = $value ? 1 : 0;
                        $inferredTypes .= 'i';
                    } elseif (is_int($value)) {
                        $inferredTypes .= 'i';
                    } elseif (is_float($value)) {
                        $inferredTypes .= 'd';
                    } else {
                        $inferredTypes .= 's';
                    }
                }
                $bindTypes = $inferredTypes;
            }

            $this->stmt->bind_param($bindTypes, ...$bindParams);
        }

        if (!$this->stmt->execute()) {
            throw new \RuntimeException($this->stmt->error);
        }

        if ($this->stmt->field_count === 0) {
            return true;
        }

        $result = $this->stmt->get_result();
        if ($result === false) {
            throw new \RuntimeException($this->stmt->error ?: $this->mysql->error);
        }

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Inserts or updates using MySQL's ON DUPLICATE KEY UPDATE syntax.
     *
     * MySQL detects conflicts through any unique index on the table; $uniqueBy
     * determines which input columns are left unchanged during an update.
     *
     * @param string $table Table name.
     * @param array<string, mixed> $data Column values.
     * @param list<string> $uniqueBy Unique-key columns.
     */
    public function upsert(string $table, array $data, array $uniqueBy): bool
    {
        [$columns, $uniqueBy] = self::validateUpsert($table, $data, $uniqueBy);
        $updates = array_values(array_diff($columns, $uniqueBy));
        if ($updates === []) {
            $updates = [$uniqueBy[0]];
            $assignments = "{$updates[0]} = {$updates[0]}";
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

        return $this->query($sql, array_values($data)) === true;
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

    /** Starts a MySQL transaction. */
    public function beginTransaction(): void
    {
        if (!$this->mysql->begin_transaction()) {
            throw new \RuntimeException($this->mysql->error);
        }
    }

    /** Commits the active MySQL transaction. */
    public function commit(): void
    {
        if (!$this->mysql->commit()) {
            throw new \RuntimeException($this->mysql->error);
        }
    }

    /** Rolls back the active MySQL transaction. */
    public function rollBack(): void
    {
        if (!$this->mysql->rollback()) {
            throw new \RuntimeException($this->mysql->error);
        }
    }

    /**
     * Returns the identifier generated by the most recent INSERT.
     *
     * @return int Generated insert identifier, or 0 if none is available.
     */
    public function getInsertId(): int
    {
        return (int) $this->mysql->insert_id;
    }

    public function getDriverName(): string
    {
        return 'mysql';
    }

    /**
     * Returns the affected-row count of the most recent prepared statement.
     *
     * @return int Number of affected rows, or 0 before a statement is executed.
     */
    public function getAffectedRows(): int
    {
        return (int) ($this->stmt?->affected_rows ?? 0);
    }

    /**
     * Returns the MySQL server thread identifier for this connection.
     *
     * @return int|null Thread identifier when available.
     */
    public function getThreadId(): ?int
    {
        return $this->mysql->thread_id;
    }

    /** Closes the underlying MySQLi connection when this driver is destroyed. */
    public function __destruct()
    {
        try {
            $this->mysql->close();
        } catch (\Throwable) {
        }
    }
}
<?php

declare(strict_types=1);

namespace Lumynus\Lumy\Drivers;

use Lumynus\Lumy\Contracts\DatabaseDriverInterface;

/**
 * Firebird/InterBase driver implemented with the legacy Ibase extension.
 *
 * The PHP Ibase extension must be installed and enabled.
 */
final class IbaseDriver implements DatabaseDriverInterface
{
    /** Native Ibase connection handle. */
    private $connection;

    /** Active native transaction handle, when a transaction has started. */
    private $transaction = null;

    /** Current transaction nesting depth; 0 means no active transaction. */
    private int $transactionDepth = 0;

    /**
     * Opens a Firebird/InterBase connection.
     *
     * @param string $host Database server host.
     * @param string $user Database username.
     * @param mixed $password Database password.
     * @param string $database Database name or server-side database path.
     */
    public function __construct(
        string $host,
        string $user,
        #[\SensitiveParameter]
        mixed $password,
        string $database
    ) {
        if (!function_exists('ibase_connect')) {
            throw new \RuntimeException(
                'The PHP InterBase/Firebird (ibase) extension is not installed or enabled.'
            );
        }

        [$hostname, $port] = self::splitHostPort($host);
        $firebirdHost = $port !== null ? "{$hostname}/{$port}" : $hostname;
        $target = $firebirdHost !== '' ? "{$firebirdHost}:{$database}" : $database;

        $this->connection = \ibase_connect($target, $user, (string) ($password ?? ''));
        if ($this->connection === false) {
            throw new \RuntimeException(
                (string) \ibase_errmsg() ?: 'Unable to connect to Firebird/InterBase database.'
            );
        }
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
     * Executes a statement and fetches rows when the statement returns a result.
     *
     * The $types argument is accepted for interface compatibility and ignored.
     *
     * @param string $query SQL statement.
     * @param array $params Positional values for a prepared statement.
     * @param string|array $types Unused by this Ibase implementation.
     *
     * @return array|bool Associative result rows, or true when the statement returns no rows.
     */
    public function query(
        string $query,
        array $params = [],
        string|array $types = ''
    ): array|bool {
        foreach ($params as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $params[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        $target = $this->transaction ?: $this->connection;
        if ($params === []) {
            $result = \ibase_query($target, $query);
        } else {
            $prepared = \ibase_prepare($target, $query);
            if ($prepared === false) {
                throw new \RuntimeException((string) \ibase_errmsg());
            }
            $result = \ibase_execute($prepared, ...array_values($params));
        }

        if ($result === false) {
            throw new \RuntimeException((string) \ibase_errmsg());
        }

        if (is_resource($result)) {
            $rows = [];
            while ($row = \ibase_fetch_assoc($result)) {
                $rows[] = array_change_key_case($row, CASE_LOWER);
            }
            \ibase_free_result($result);

            return $rows;
        }

        return true;
    }

    /**
     * Inserts or updates using the Ibase UPDATE OR INSERT syntax.
     *
     * @param string $table Table name.
     * @param array<string, mixed> $data Column values.
     * @param list<string> $uniqueBy Unique-key columns used by MATCHING.
     */
    public function upsert(string $table, array $data, array $uniqueBy): bool
    {
        [$columns, $uniqueBy] = self::validateUpsert($table, $data, $uniqueBy);
        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $matching = implode(', ', $uniqueBy);
        $sql = "UPDATE OR INSERT INTO {$table} ({$columnList}) "
            . "VALUES ({$placeholders}) MATCHING ({$matching})";

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

    /** Starts an Ibase transaction or savepoint for subsequent queries. */
    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $transaction = \ibase_trans($this->connection);
            if ($transaction === false) {
                throw new \RuntimeException((string) \ibase_errmsg());
            }
            $this->transaction = $transaction;
            $this->transactionDepth = 1;

            return;
        }

        \ibase_query($this->transaction, 'SAVEPOINT lumy_sp_' . $this->transactionDepth);
        $this->transactionDepth++;
    }

    /** Commits the active Ibase transaction or releases the innermost savepoint. */
    public function commit(): void
    {
        if ($this->transactionDepth === 0 || $this->transaction === null) {
            throw new \RuntimeException('There is no active transaction to commit.');
        }

        if ($this->transactionDepth === 1) {
            $committed = \ibase_commit($this->transaction);
            $this->transaction = null;
            $this->transactionDepth = 0;
            if ($committed === false) {
                throw new \RuntimeException((string) \ibase_errmsg());
            }

            return;
        }

        $this->transactionDepth--;
        \ibase_query($this->transaction, 'RELEASE SAVEPOINT lumy_sp_' . $this->transactionDepth);
    }

    /** Rolls back the active Ibase transaction or to the innermost savepoint. */
    public function rollBack(): void
    {
        if ($this->transactionDepth === 0 || $this->transaction === null) {
            return;
        }

        if ($this->transactionDepth === 1) {
            $rolledBack = \ibase_rollback($this->transaction);
            $this->transaction = null;
            $this->transactionDepth = 0;
            if ($rolledBack === false) {
                throw new \RuntimeException((string) \ibase_errmsg());
            }

            return;
        }

        $this->transactionDepth--;
        $name = 'lumy_sp_' . $this->transactionDepth;
        \ibase_query($this->transaction, 'ROLLBACK TO SAVEPOINT ' . $name);
        \ibase_query($this->transaction, 'RELEASE SAVEPOINT ' . $name);
    }

    /**
     * Returns the generated insert identifier.
     *
     * Generator lookup is not implemented for this driver.
     *
     * @return int Always returns 0 until generator support is implemented.
     */
    public function getInsertId(): int
    {
        return 0;
    }

    public function getDriverName(): string
    {
        return 'firebird';
    }

    /**
     * Returns the number of rows affected on the Ibase connection.
     *
     * @return int Number of affected rows reported by the extension.
     */
    public function getAffectedRows(): int
    {
        $target = $this->transaction ?: $this->connection;

        return (int) \ibase_affected_rows($target);
    }

    /**
     * Returns the connection identifier when available.
     *
     * @return int|null Connection identifier, or null on failure.
     */
    public function getThreadId(): ?int
    {
        try {
            if (function_exists('ibase_db_info') && defined('IBASE_STMT_ID')) {
                $info = \ibase_db_info($this->connection, constant('IBASE_STMT_ID'));
                if ($info !== false) {
                    return (int) $info;
                }
            }

            $rows = $this->query('SELECT CURRENT_CONNECTION AS conn_id FROM RDB$DATABASE');
            if (is_array($rows) && isset($rows[0]['conn_id'])) {
                return (int) $rows[0]['conn_id'];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** Closes the native Ibase connection when this driver is destroyed. */
    public function __destruct()
    {
        try {
            if ($this->connection) {
                \ibase_close($this->connection);
            }
        } catch (\Throwable) {
        }
    }
}

<?php

declare(strict_types=1);

namespace Lumynus\Lumy\Drivers;

use Lumynus\Lumy\Contracts\DatabaseDriverInterface;

/**
 * Firebird/InterBase driver implemented with the legacy Ibase extension API.
 *
 * The PHP Ibase extension must be installed and enabled.
 */
final class IbaseDriver implements DatabaseDriverInterface
{
    /** Native Ibase connection handle. */
    private $connection;

    /** Active native transaction handle, when a transaction has started. */
    private $transaction = null;

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
        mixed $password,
        string $database
    ) {
        $this->connection = \ibase_connect("{$host}:{$database}", $user, $password);
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
     * @return array|bool Associative result rows, or true for statements without rows.
     */
    public function query(
        string $query,
        array $params = [],
        string|array $types = ''
    ): array|bool {
        $target = $this->transaction ?: $this->connection;
        $result = $params === []
            ? \ibase_query($target, $query)
            : \ibase_execute(\ibase_prepare($target, $query), ...$params);

        if (is_resource($result)) {
            $rows = [];
            while ($row = \ibase_fetch_assoc($result)) {
                $rows[] = $row;
            }

            return $rows;
        }

        return true;
    }

    /** Starts an Ibase transaction for subsequent queries. */
    public function beginTransaction(): void
    {
        $this->transaction = \ibase_trans($this->connection);
    }

    /** Commits the active Ibase transaction and clears its handle. */
    public function commit(): void
    {
        \ibase_commit($this->transaction);
        $this->transaction = null;
    }

    /** Rolls back the active Ibase transaction and clears its handle. */
    public function rollBack(): void
    {
        \ibase_rollback($this->transaction);
        $this->transaction = null;
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

    /**
     * Returns the number of rows affected on the Ibase connection.
     *
     * @return int Number of affected rows reported by the extension.
     */
    public function getAffectedRows(): int
    {
        return \ibase_affected_rows($this->connection);
    }

    /**
     * Returns the integer value reported by IBASE_STMT_ID, if available.
     *
     * @return int|null Value returned by ibase_db_info, or null on failure.
     */
    public function getThreadId(): ?int
    {
        $info = \ibase_db_info($this->connection, IBASE_STMT_ID);

        return $info !== false ? (int) $info : null;
    }

    /** Closes the native Ibase connection when this driver is destroyed. */
    public function __destruct()
    {
        if ($this->connection) {
            \ibase_close($this->connection);
        }
    }
}
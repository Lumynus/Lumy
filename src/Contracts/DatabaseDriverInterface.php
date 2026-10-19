<?php

declare(strict_types=1);

namespace Lumynus\Lumy\Contracts;

/**
 * Common API exposed by all database drivers.
 *
 * Query methods return fetched rows for statements with a result set and true
 * for statements that do not return rows.
 */
interface DatabaseDriverInterface
{
    /**
     * Prepares and executes a SQL statement.
     *
     * @param string $query SQL statement, optionally containing placeholders.
     * @param array $params Values bound to the statement placeholders.
     * @param string|array $types Optional MySQLi parameter types; other drivers may ignore this value.
     *
     * @return array|bool Fetched rows, or true when the statement has no result set.
     */
    public function query(
        string $query,
        array $params = [],
        string|array $types = ''
    ): array|bool;

    /** Starts a database transaction. */
    public function beginTransaction(): void;

    /** Commits the active database transaction. */
    public function commit(): void;

    /** Rolls back the active database transaction. */
    public function rollBack(): void;

    /**
     * Returns the last generated insert identifier.
     *
     * @return int Insert identifier, or 0 when the driver cannot provide one.
     */
    public function getInsertId(): int;

    /**
     * Returns the affected row count for the most recently executed statement.
     *
     * @return int Number of affected rows, or 0 if no statement has been executed.
     */
    public function getAffectedRows(): int;

    /**
     * Returns the database server's connection identifier when available.
     *
     * @return int|null Connection identifier, or null when unsupported or unavailable.
     */
    public function getThreadId(): ?int;
}
<?php

declare(strict_types=1);

namespace Lumynus\Lumy\Contracts;

/**
 * Common API exposed by all database drivers.
 *
 * Query methods return fetched rows when a statement produces a result set, or
 * true when it does not.
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
    * @return array|bool The fetched rows, or true when the statement has no result set.
     */
    public function query(
        string $query,
        array $params = [],
        string|array $types = ''
    ): array|bool;

    /**
     * Inserts a row or updates it when a unique key already exists.
     *
     * @param string $table Table name.
     * @param array<string, mixed> $data Column values to insert or update.
     * @param list<string> $uniqueBy Columns covered by a unique constraint.
     * @return bool True when the statement executes successfully.
     */
    public function upsert(string $table, array $data, array $uniqueBy): bool;

    /** Returns the SQL dialect used by this connection. */
    public function getDriverName(): string;

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
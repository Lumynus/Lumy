<?php

declare(strict_types=1);

namespace Lumynus\Lumy;

use Lumynus\Lumy\Connections\LumyConnection;
use Lumynus\Lumy\Contracts\DatabaseDriverInterface;

/**
 * Base model that provides table operations to application model subclasses.
 *
 * Set the protected $table property in a subclass to inherit insert, upsert,
 * update, and delete without reimplementing their SQL handling.
 */
abstract class AbstractLumy extends LumyConnection
{
    /** Table used by the inherited CRUD methods. */
    protected string $table = '';

    /** @var DatabaseDriverInterface|null Active connection for this model. */
    private ?DatabaseDriverInterface $modelConnection = null;

    /**
     * Initializes the model with a configured database connection.
     *
     * @param string $connectionIdentifier Configuration name registered in Config.
     */
    public function __construct(protected string $connectionIdentifier = 'default')
    {
        if ($this->table !== '') {
            self::validateColumns([$this->table]);
        }

        $this->modelConnection = $this->getConnection();
    }

    /**
     * Inserts a row and returns the generated ID when supported by the driver.
     *
     * @param array<string, mixed> $data Column values to insert.
     */
    public function insert(array $data): int
    {
        $table = $this->getTableName();
        $columns = self::validateColumns(array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";

        $this->customQuery($sql, array_values($data));

        return $this->getConnection()->getInsertId();
    }

    /**
     * Inserts a row or updates it when the selected unique key already exists.
     *
     * @param array<string, mixed> $data Column values to insert or update.
     * @param list<string> $uniqueBy Columns covered by a unique constraint.
     */
    public function upsert(array $data, array $uniqueBy): bool
    {
        $table = $this->getTableName();
        if ($data === [] || $uniqueBy === []) {
            throw new \InvalidArgumentException('Upsert data and unique columns are required.');
        }

        $columns = self::validateColumns(array_keys($data));
        $uniqueBy = self::validateColumns(array_values($uniqueBy));
        if (array_diff($uniqueBy, $columns) !== []) {
            throw new \InvalidArgumentException(
                'Every unique column must be present in the upsert data.'
            );
        }

        return $this->getConnection()->upsert($table, $data, $uniqueBy);
    }

    /**
     * Updates matching rows and returns the number of affected rows.
     *
     * @param array<string, mixed> $data Column values to update.
     * @param array<string, mixed>|string $where Conditions array or custom SQL expression.
     * @param array $whereParams Parameters for a custom SQL expression.
     */
    public function update(array $data, array|string $where, array $whereParams = []): int
    {
        $table = $this->getTableName();
        if ($data === []) {
            throw new \InvalidArgumentException('Update data cannot be empty.');
        }

        $columns = self::validateColumns(array_keys($data));
        [$whereSql, $whereValues] = $this->resolveWhere($where, $whereParams);
        $assignments = implode(', ', array_map(
            static fn (string $column): string => "{$column} = ?",
            $columns
        ));
        $sql = "UPDATE {$table} SET {$assignments} WHERE {$whereSql}";

        $this->customQuery($sql, [...array_values($data), ...$whereValues]);

        return $this->getConnection()->getAffectedRows();
    }

    /**
     * Deletes matching rows and returns the number of affected rows.
     *
     * @param array<string, mixed>|string $where Conditions array or custom SQL expression.
     * @param array $whereParams Parameters for a custom SQL expression.
     */
    public function delete(array|string $where, array $whereParams = []): int
    {
        $table = $this->getTableName();
        [$whereSql, $params] = $this->resolveWhere($where, $whereParams);
        $this->customQuery("DELETE FROM {$table} WHERE {$whereSql}", $params);

        return $this->getConnection()->getAffectedRows();
    }

    /** Executes a custom query using this model's configured connection. */
    protected function customQuery(
        string $query,
        array $params = [],
        string|array $types = ''
    ): array|bool {
        return $this->getConnection()->query($query, $params, $types);
    }

    /** Returns this model's configured database connection. */
    protected function getConnection(): DatabaseDriverInterface
    {
        if ($this->modelConnection !== null) {
            return $this->modelConnection;
        }

        $configuration = Config::getConnection($this->connectionIdentifier);
        $this->modelConnection = $this->connect(
            $configuration['driver'],
            $configuration['host'],
            $configuration['user'],
            $configuration['password'],
            $configuration['database'],
            $configuration['newConnection'],
            $this->connectionIdentifier
        );

        return $this->modelConnection;
    }

    private function getTableName(): string
    {
        if ($this->table === '') {
            throw new \InvalidArgumentException('A table name must be set in the model.');
        }

        return self::validateColumns([$this->table])[0];
    }

    /** @param array<array-key, mixed> $columns @return list<string> */
    private static function validateColumns(array $columns): array
    {
        if ($columns === []) {
            throw new \InvalidArgumentException('At least one column is required.');
        }

        foreach ($columns as $column) {
            if (!is_string($column) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $column)) {
                throw new \InvalidArgumentException('Invalid SQL column identifier.');
            }
        }

        return array_values($columns);
    }

    /** @return array{string, list<mixed>} */
    private function resolveWhere(array|string $where, array $params): array
    {
        if (is_array($where)) {
            if ($params !== []) {
                throw new \InvalidArgumentException(
                    'Separate where parameters are only valid with a custom SQL expression.'
                );
            }

            return $this->buildWhere($where);
        }

        $where = trim($where);
        $where = preg_replace('/\AWHERE\b\s*/i', '', $where) ?? $where;
        if (trim($where) === '') {
            throw new \InvalidArgumentException('A WHERE expression is required.');
        }

        return [$where, array_values($params)];
    }

    /** @param array<string, mixed> $conditions @return array{string, list<mixed>} */
    private function buildWhere(array $conditions): array
    {
        if ($conditions === []) {
            throw new \InvalidArgumentException('WHERE conditions cannot be empty.');
        }

        $parts = [];
        $params = [];
        foreach ($conditions as $columns => $value) {
            if (!is_string($columns)) {
                throw new \InvalidArgumentException('WHERE column names must be strings.');
            }

            $columnNames = preg_split('/\s*\|\|\s*/', trim($columns));
            if ($columnNames === false || $columnNames === []) {
                throw new \InvalidArgumentException('Invalid WHERE condition.');
            }

            $columnConditions = [];
            foreach ($columnNames as $columnExpression) {
                [$column, $operator] = self::parseWhereColumn(trim($columnExpression));
                if ($value === null) {
                    if ($operator === '=') {
                        $columnConditions[] = "{$column} IS NULL";
                        continue;
                    }
                    if ($operator === '<>' || $operator === '!=') {
                        $columnConditions[] = "{$column} IS NOT NULL";
                        continue;
                    }

                    throw new \InvalidArgumentException(
                        "Operator {$operator} cannot be used with null."
                    );
                }

                if (is_array($value)) {
                    if ($value === [] || in_array(null, $value, true)) {
                        throw new \InvalidArgumentException(
                            'IN and BETWEEN conditions require non-empty values without null.'
                        );
                    }
                    foreach ($value as $item) {
                        if (!is_scalar($item)) {
                            throw new \InvalidArgumentException('WHERE values must be scalar.');
                        }
                    }

                    if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
                        if (count($value) !== 2) {
                            throw new \InvalidArgumentException(
                                "{$operator} requires exactly two values."
                            );
                        }
                        $columnConditions[] = "{$column} {$operator} ? AND ?";
                        $params = [...$params, ...array_values($value)];
                        continue;
                    }

                    if ($operator !== '=' && $operator !== '<>' && $operator !== '!=') {
                        throw new \InvalidArgumentException(
                            "Operator {$operator} cannot be used with a list."
                        );
                    }
                    $inOperator = $operator === '=' ? 'IN' : 'NOT IN';
                    $placeholders = implode(', ', array_fill(0, count($value), '?'));
                    $columnConditions[] = "{$column} {$inOperator} ({$placeholders})";
                    $params = [...$params, ...array_values($value)];
                    continue;
                }

                if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
                    throw new \InvalidArgumentException(
                        "{$operator} requires exactly two values."
                    );
                }
                $columnConditions[] = "{$column} {$operator} ?";
                $params[] = $value;
            }

            $parts[] = count($columnConditions) > 1
                ? '(' . implode(' OR ', $columnConditions) . ')'
                : $columnConditions[0];
        }

        return [implode(' AND ', $parts), $params];
    }

    /** @return array{string, string} */
    private static function parseWhereColumn(string $expression): array
    {
        $operator = '=';
        $column = $expression;
        if (preg_match('/\A(.+?)\s+(NOT BETWEEN|BETWEEN|<=|>=|<>|!=|=|<|>)\z/i', $expression, $matches)) {
            $column = trim($matches[1]);
            $operator = strtoupper($matches[2]);
        }

        if (str_starts_with($column, '!')) {
            $column = trim(substr($column, 1));
            $operator = match ($operator) {
                '=' => '<>',
                'BETWEEN' => 'NOT BETWEEN',
                default => throw new \InvalidArgumentException(
                    'Prefix ! can only negate equality or BETWEEN.'
                ),
            };
        }

        return [self::validateColumns([$column])[0], $operator];
    }
}
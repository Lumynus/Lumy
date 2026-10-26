<?php

declare(strict_types=1);

namespace Lumynus\Lumy;

use Lumynus\Lumy\Contracts\DatabaseDriverInterface;

/** Base class for application models with field mapping, joins, queries, and relationships. */
abstract class AbstractModel extends AbstractLumy
{
	protected string $alias = '';

	/** @var array<string, array{db: string, select?: bool}> */
	protected array $fields = [];

	/** @var array<int, string>|null Trusted SQL JOIN clauses defined by the model. */
	protected ?array $joins = null;

	/** @var list<string> Logical field names or qualified columns used in GROUP BY. */
	protected array $groupBy = [];

	/**
	 * @var array<string, array{
	 *     model: class-string<AbstractModel>,
	 *     localKey: string,
	 *     foreignKey: string,
	 *     many?: bool,
	 *     connectionIdentifier?: string
	 * }>
	 */
	protected array $relationships = [];

	/**
	 * Executes a SELECT and returns its rows.
	 *
	 * @param array<string, mixed> $filters Structured equality/operator filters.
	 * @param string|array $orderBy Logical field and direction, e.g. "createdAt DESC".
	* @param int $offset The number of result rows to skip.
	* @param int|null $limit The maximum number of rows to return; null disables the limit.
	* @param array<string>|null $fielders Logical fields to return; null selects fields marked with select=true.
	* @param bool $camelCaseTransform Whether to convert input field names from camelCase to snake_case and result keys back.
	* @param array $with Relationships to load, optionally including nested relationships.
	 * @return array<int, array<string, mixed>>
	 */
	public function select(
		array $filters = [],
		string|array $orderBy = '',
		int $offset = 0,
		?int $limit = 10,
		?array $fielders = null,
		bool $camelCaseTransform = false,
		array $with = []
	): array {
		return $this->runSelect(
			$filters,
			$orderBy,
			$offset,
			$limit,
			$fielders,
			$camelCaseTransform,
			$with
		);
	}

	/**
	 * Executes a selection and returns the first matching row, or null.
	 *
	 * @param array<string, mixed> $filters
	 * @param array<string>|null $fielders
	 * @param array $with
	 * @return array<string, mixed>|null
	 */
	protected function getOne(
		array $filters = [],
		?array $fielders = null,
		bool $camelCaseTransform = false,
		array $with = []
	): ?array {
		return $this->runSelect(
			$filters,
			'',
			0,
			1,
			$fielders,
			$camelCaseTransform,
			$with
		)[0] ?? null;
	}

	/**
	 * Runs a SELECT and optionally retains internal keys for relation loading.
	 *
	 * @param array<string, mixed> $filters
	 * @param string|array $orderBy
	 * @param array<string>|null $fielders
	 * @param array $with
	 * @param array<string, string> $extraSelect Logical field => internal alias.
	 * @param list<string> $preserveExtra Internal aliases that a parent loader needs.
	 * @return array<int, array<string, mixed>>
	 */
	private function runSelect(
		array $filters,
		string|array $orderBy,
		int $offset,
		?int $limit,
		?array $fielders,
		bool $camelCaseTransform,
		array $with,
		array $extraSelect = [],
		array $preserveExtra = []
	): array {
		$table = $this->getModelTable();
		if ($offset < 0 || ($limit !== null && $limit < 0)) {
			throw new \InvalidArgumentException('Offset and limit cannot be negative.');
		}
		if ($limit === 0) {
			return [];
		}
		if ($limit === null && $offset > 0) {
			throw new \InvalidArgumentException('Offset requires a limit.');
		}

		$requestedRelations = $this->normalizeWith($with);
		foreach ($requestedRelations as $name => $_nested) {
			$relationship = $this->relationships[$name] ?? null;
			if (!is_array($relationship)) {
				throw new \InvalidArgumentException("Unknown relationship '{$name}'.");
			}
			$localKey = $relationship['localKey'] ?? 'id';
			$extraSelect[$localKey] ??= '__lumy_relation_' . count($extraSelect);
		}

		$select = $this->buildProjection($fielders, $camelCaseTransform, $extraSelect);
		$from = $table . ($this->alias !== '' ? ' ' . $this->validateIdentifier($this->alias) : '');
		$joins = $this->joins === null ? '' : implode(' ', $this->joins);
		[$whereSql, $params] = $this->buildSelectWhere($filters, $camelCaseTransform);
		$groupBy = $this->buildGroupBy($camelCaseTransform);
		$orderBySql = $this->buildOrderBy($orderBy, $camelCaseTransform, $limit !== null);

		$sql = "SELECT {$select} FROM {$from}";
		if ($joins !== '') {
			$sql .= " {$joins}";
		}
		if ($whereSql !== '') {
			$sql .= " WHERE {$whereSql}";
		}
		if ($groupBy !== '') {
			$sql .= " GROUP BY {$groupBy}";
		}
		if ($orderBySql !== '') {
			$sql .= " ORDER BY {$orderBySql}";
		}
		$sql .= $this->buildPagination($offset, $limit, $orderBySql);

		$rows = $this->customQuery($sql, $params);
		if (!is_array($rows)) {
			return [];
		}

		if ($requestedRelations !== []) {
			$this->loadRelationships(
				$rows,
				$requestedRelations,
				$camelCaseTransform,
				$extraSelect
			);
		}

		foreach ($rows as &$row) {
			foreach ($extraSelect as $internalAlias) {
				if (!in_array($internalAlias, $preserveExtra, true)) {
					unset($row[$internalAlias]);
				}
			}
			if ($camelCaseTransform) {
				$preservedValues = [];
				foreach ($preserveExtra as $internalAlias) {
					if (array_key_exists($internalAlias, $row)) {
						$preservedValues[$internalAlias] = $row[$internalAlias];
						unset($row[$internalAlias]);
					}
				}
				$row = $this->transformKeysToCamelCase($row);
				foreach ($preservedValues as $internalAlias => $value) {
					$row[$internalAlias] = $value;
				}
			}
		}
		unset($row);

		return $rows;
	}

	/** @param array<string>|null $fielders @param array<string, string> $extraSelect */
	private function buildProjection(?array $fielders, bool $camelCaseTransform, array $extraSelect): string
	{
		if ($this->fields === [] && $fielders === null) {
			$select = $this->alias !== ''
				? $this->validateIdentifier($this->alias) . '.*'
				: '*';
		} else {
			$requested = $fielders;
			if ($requested === null) {
				$requested = [];
				foreach ($this->fields as $name => $definition) {
					if (($definition['select'] ?? false) === true) {
						$requested[] = $name;
					}
				}
			}
			if ($requested === []) {
				throw new \InvalidArgumentException('At least one selectable field is required.');
			}

			$projections = [];
			foreach ($requested as $field) {
				if (!is_string($field)) {
					throw new \InvalidArgumentException('Field names must be strings.');
				}
				$logical = $this->normalizeFieldName($field, $camelCaseTransform);
				$databaseField = $this->resolveField($logical, false);
				$alias = $this->validateIdentifier($logical);
				$projections[$alias] = "{$databaseField} AS {$alias}";
			}
			$select = implode(', ', $projections);
		}

		foreach ($extraSelect as $logical => $internalAlias) {
			$databaseField = $this->resolveField(
				$this->normalizeFieldName($logical, $camelCaseTransform),
				false
			);
			if ($this->fields === [] && $this->alias !== '' && !str_contains($databaseField, '.')) {
				$databaseField = $this->validateIdentifier($this->alias) . '.' . $databaseField;
			}
			$select .= ", {$databaseField} AS {$internalAlias}";
		}

		return $select;
	}

	/** @param array<string, mixed> $filters @return array{string, list<mixed>} */
	private function buildSelectWhere(array $filters, bool $camelCaseTransform): array
	{
		$parts = [];
		$params = [];
		foreach ($filters as $expression => $value) {
			if (!is_string($expression)) {
				throw new \InvalidArgumentException('Filter keys must be strings.');
			}

			$orParts = [];
			foreach (preg_split('/\s*\|\|\s*/', $expression) ?: [] as $columnExpression) {
				[$logical, $operator] = $this->parseFilterExpression(trim($columnExpression));
				$column = $this->resolveField(
					$this->normalizeFieldName($logical, $camelCaseTransform),
					true
				);

				if ($value === null) {
					if ($operator === '=' || $operator === 'IS') {
						$orParts[] = "{$column} IS NULL";
						continue;
					}
					if (in_array($operator, ['!=', '<>', 'IS NOT'], true)) {
						$orParts[] = "{$column} IS NOT NULL";
						continue;
					}
					throw new \InvalidArgumentException("Operator {$operator} cannot be used with null.");
				}

				if (is_array($value)) {
					if ($value === [] || in_array(null, $value, true)) {
						throw new \InvalidArgumentException('Filter lists cannot be empty or contain null.');
					}
					foreach ($value as $item) {
						if (!is_scalar($item)) {
							throw new \InvalidArgumentException('Filter values must be scalar values.');
						}
					}
					if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
						if (count($value) !== 2) {
							throw new \InvalidArgumentException("{$operator} requires two values.");
						}
						$orParts[] = "{$column} {$operator} ? AND ?";
						$params = [...$params, ...array_values($value)];
						continue;
					}
					if (!in_array($operator, ['=', '!=', '<>', 'IN', 'NOT IN'], true)) {
						throw new \InvalidArgumentException("Operator {$operator} cannot be used with a list.");
					}
					$inOperator = in_array($operator, ['!=', '<>', 'NOT IN'], true) ? 'NOT IN' : 'IN';
					$marks = implode(', ', array_fill(0, count($value), '?'));
					$orParts[] = "{$column} {$inOperator} ({$marks})";
					$params = [...$params, ...array_values($value)];
					continue;
				}

				if (!is_scalar($value)) {
					throw new \InvalidArgumentException('Filter values must be scalar values.');
				}

				if (in_array($operator, ['BETWEEN', 'NOT BETWEEN', 'IN', 'NOT IN'], true)) {
					throw new \InvalidArgumentException("{$operator} requires an array value.");
				}
				if (in_array($operator, ['IS', 'IS NOT'], true)) {
					throw new \InvalidArgumentException("Operator {$operator} can only be used with null.");
				}
				$orParts[] = "{$column} {$operator} ?";
				if (in_array($operator, ['LIKE', 'NOT LIKE'], true)) {
					$strVal = (string) $value;
					$isPattern = str_contains($strVal, '%');
					$orParts[array_key_last($orParts)] .= $this->likeEscapeClause();
					$params[] = $isPattern
						? self::escapeLikeBackslashes($strVal)
						: '%' . self::escapeLikeLiterals($strVal) . '%';
				} else {
					$params[] = $value;
				}
			}

			$parts[] = count($orParts) > 1 ? '(' . implode(' OR ', $orParts) . ')' : $orParts[0];
		}

		return [implode(' AND ', $parts), $params];
	}

	/** @return array{string, string} */
	private function parseFilterExpression(string $expression): array
	{
		$operator = '=';
		$field = $expression;
		if (preg_match('/\A(.+?)\s+(NOT BETWEEN|NOT IN|NOT LIKE|BETWEEN|IN|LIKE|IS NOT|IS|<=|>=|<>|!=|=|<|>)\z/i', $expression, $matches)) {
			$field = trim($matches[1]);
			$operator = strtoupper($matches[2]);
		} elseif (preg_match('/\A(NOT BETWEEN|NOT IN|NOT LIKE|BETWEEN|IN|LIKE|IS NOT|IS|<=|>=|<>|!=|=|<|>)\s+(.+)\z/i', $expression, $matches)) {
			$operator = strtoupper($matches[1]);
			$field = trim($matches[2]);
		}

		if (str_starts_with($field, '!')) {
			$field = trim(substr($field, 1));
			$operator = match ($operator) {
				'=' => '<>',
				'BETWEEN' => 'NOT BETWEEN',
				'IN' => 'NOT IN',
				'LIKE' => 'NOT LIKE',
				default => throw new \InvalidArgumentException('Unsupported negated filter operator.'),
			};
		}

		if (!in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'IS', 'IS NOT'], true)) {
			throw new \InvalidArgumentException("Unsupported filter operator: {$operator}");
		}

		return [$field, $operator];
	}

	private function resolveField(
		string $field,
		bool $allowQualified,
		bool $allowUnmappedQualified = false
	): string {
		if (isset($this->fields[$field]['db'])) {
			return $this->fields[$field]['db'];
		}

		if ($this->fields !== []) {
			if (
				$allowQualified
				&& preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)+\z/', $field)
			) {
				if ($allowUnmappedQualified) {
					return $field;
				}
				foreach ($this->fields as $definition) {
					if (($definition['db'] ?? null) === $field) {
						return $field;
					}
				}
			}

			throw new \InvalidArgumentException("Field '{$field}' is not defined in the model map.");
		}

		$pattern = $allowQualified
			? '/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/'
			: '/\A[A-Za-z_][A-Za-z0-9_]*\z/';
		if (!preg_match($pattern, $field)) {
			throw new \InvalidArgumentException("Invalid field identifier: {$field}");
		}

		return $field;
	}

	private function normalizeFieldName(string $field, bool $camelCaseTransform): string
	{
		return $camelCaseTransform ? self::camelToSnake($field) : $field;
	}

	private static function camelToSnake(string $value): string
	{
		return strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value));
	}

	private function buildGroupBy(bool $camelCaseTransform): string
	{
		$fields = [];
		foreach ($this->groupBy as $field) {
			$name = $this->normalizeFieldName($field, $camelCaseTransform);
			$fields[] = $this->resolveField($name, true, true);
		}

		return implode(', ', $fields);
	}

	private function buildOrderBy(string|array $orderBy, bool $camelCaseTransform, bool $paginate): string
	{
		$items = [];
		if (is_array($orderBy)) {
			foreach ($orderBy as $field => $direction) {
				$items[] = is_int($field) ? (string) $direction : "{$field} {$direction}";
			}
		} elseif (trim($orderBy) !== '') {
			$items = explode(',', $orderBy);
		}

		$orders = [];
		foreach ($items as $item) {
			if (!preg_match('/\A(.+?)(?:\s+(ASC|DESC))?\z/i', trim($item), $matches)) {
				throw new \InvalidArgumentException("Invalid orderBy expression: {$item}");
			}
			$field = $this->normalizeFieldName(trim($matches[1]), $camelCaseTransform);
			$direction = strtoupper($matches[2] ?? 'ASC');
			if (!in_array($direction, ['ASC', 'DESC'], true)) {
				throw new \InvalidArgumentException('orderBy direction must be ASC or DESC.');
			}
			$orders[] = $this->resolveField($field, true) . ' ' . $direction;
		}

		if ($paginate && $orders === []) {
			if ($this->groupBy !== []) {
				$orders[] = $this->resolveField(
					$this->normalizeFieldName($this->groupBy[0], $camelCaseTransform),
					true,
					true
				) . ' ASC';
			} elseif ($this->fields !== []) {
				$stableField = isset($this->fields['id'])
					? 'id'
					: (string) array_key_first($this->fields);
				$orders[] = $this->resolveField(
					$this->normalizeFieldName($stableField, $camelCaseTransform),
					true
				) . ' ASC';
			} else {
				// No mapped fields and no groupBy: keep pagination deterministic on
				// every dialect. ORDER BY 1 references the first projected column;
				// SQL Server needs the scalar-subquery form for OFFSET/FETCH.
				$orders[] = $this->getConnection()->getDriverName() === 'sqlsrv'
					? '(SELECT NULL)'
					: '1';
			}
		}

		return implode(', ', $orders);
	}

	private function buildPagination(int $offset, ?int $limit, string $orderBy): string
	{
		if ($limit === null) {
			return '';
		}

		$driver = $this->getConnection()->getDriverName();
		if (in_array($driver, ['sqlsrv', 'firebird'], true)) {
			if ($driver === 'firebird') {
				return ' ROWS ' . ($offset + 1) . ' TO ' . ($offset + $limit);
			}

			return " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
		}

		return " LIMIT {$limit} OFFSET {$offset}";
	}

	/** @return array<string, array> */
	private function normalizeWith(array $with): array
	{
		$normalized = [];
		foreach ($with as $key => $value) {
			if (is_int($key)) {
				if (!is_string($value)) {
					throw new \InvalidArgumentException('Relationship names must be strings.');
				}
				$normalized[$value] = [];
				continue;
			}

			if (!is_array($value)) {
				throw new \InvalidArgumentException('Nested relationships must be an array.');
			}
			$normalized[$key] = $value;
		}

		return $normalized;
	}

	/**
	 * Loads configured relationships in batched queries after the parent page is selected.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<string, array> $requested
	 * @param array<string, string> $parentExtra
	 */
	private function loadRelationships(array &$rows, array $requested, bool $camelCaseTransform, array $parentExtra): void
	{
		foreach ($requested as $name => $nested) {
			$definition = $this->relationships[$name];
			$modelClass = $definition['model'] ?? null;
			$localKey = $definition['localKey'] ?? 'id';
			$foreignKey = $definition['foreignKey'] ?? null;
			if (!is_string($modelClass) || !is_subclass_of($modelClass, self::class) || !is_string($foreignKey)) {
				throw new \InvalidArgumentException("Invalid relationship definition '{$name}'.");
			}

			$parentAlias = $parentExtra[$localKey] ?? null;
			if ($parentAlias === null) {
				throw new \LogicException("Missing internal key for relationship '{$name}'.");
			}

			$ids = array_values(array_unique(array_filter(
				array_column($rows, $parentAlias),
				static fn (mixed $id): bool => $id !== null
			)));
			if ($ids === []) {
				foreach ($rows as &$row) {
					$row[$name] = ($definition['many'] ?? true) ? [] : null;
				}
				unset($row);
				continue;
			}

			$connectionIdentifier = $definition['connectionIdentifier'] ?? $this->connectionIdentifier;
			$related = new $modelClass($connectionIdentifier);
			$relatedAlias = '__lumy_foreign_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name);
			$relatedRows = $related->runSelect(
				[$foreignKey => $ids],
				'',
				0,
				null,
				null,
				$camelCaseTransform,
				$nested,
				[$foreignKey => $relatedAlias],
				[$relatedAlias]
			);

			$relatedByKey = [];
			foreach ($relatedRows as $relatedRow) {
				$key = (string) ($relatedRow[$relatedAlias] ?? '');
				unset($relatedRow[$relatedAlias]);
				$relatedByKey[$key][] = $relatedRow;
			}

			foreach ($rows as &$row) {
				$key = (string) ($row[$parentAlias] ?? '');
				$matches = $relatedByKey[$key] ?? [];
				$row[$name] = ($definition['many'] ?? true)
					? $matches
					: ($matches[0] ?? null);
			}
			unset($row);
		}
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function transformKeysToCamelCase(array $row): array
	{
		$transformed = [];
		foreach ($row as $key => $value) {
			$camelKey = preg_replace_callback(
				'/_([a-z0-9])/',
				static fn (array $matches): string => strtoupper($matches[1]),
				(string) $key
			);
			$transformed[$camelKey ?? $key] = $value;
		}

		return $transformed;
	}

	private function getModelTable(): string
	{
		if ($this->table === '') {
			throw new \InvalidArgumentException('A table name must be set in the model.');
		}

		return $this->validateIdentifier($this->table);
	}

	private function validateIdentifier(string $identifier): string
	{
		if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier)) {
			throw new \InvalidArgumentException("Invalid SQL identifier: {$identifier}");
		}

		return $identifier;
	}
}

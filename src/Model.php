<?php

declare(strict_types=1);

namespace Lumynus\Lumy;

final class Model extends AbstractModel
{
	public function __construct(string $tableName, string $connectionIdentifier = 'default')
	{
		$this->table = $tableName;
		parent::__construct($connectionIdentifier);
	}

	/**
	 * Returns one matching row, or null when no row matches.
	 *
	* @param array<string, mixed> $filters The filters used to find the row.
	* @param array<string>|null $fielders The fields to return.
	* @param array $with The relationships to load.
	 * @return array<string, mixed>|null
	 */
	public function get(
		array $filters = [],
		bool $camelCaseTransform = false,
		?array $fielders = null,
		array $with = []
	): ?array {
		return $this->getOne($filters, $fielders, $camelCaseTransform, $with);
	}
}

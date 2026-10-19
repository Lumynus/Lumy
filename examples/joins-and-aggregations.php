<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;

new Config(
    driver: 'sqlite',
    database: ':memory:',
    connectionIdentifier: 'default'
);

class CategoryModel extends AbstractModel
{
    protected string $table = 'categories';
}

class SaleReportModel extends AbstractModel
{
    protected string $table = 'sales';
    protected string $alias = 's';

    protected array $fields = [
        'category_id' => ['db' => 'c.id', 'select' => true],
        'category_name' => ['db' => 'c.name', 'select' => true],
        'total_orders' => ['db' => 'COUNT(s.id)', 'select' => true],
        'total_revenue' => ['db' => 'SUM(s.amount)', 'select' => true],
    ];

    protected ?array $joins = [
        'INNER JOIN categories c ON s.category_id = c.id',
    ];

    protected array $groupBy = ['c.id', 'c.name'];

    public function seed(): void
    {
        $this->customQuery('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->customQuery('CREATE TABLE sales (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER NOT NULL, amount REAL NOT NULL)');

        $catModel = new CategoryModel();
        $hardwareId = $catModel->insert(['name' => 'Hardware']);
        $softwareId = $catModel->insert(['name' => 'Software']);

        $this->insert(['category_id' => $hardwareId, 'amount' => 1500.00]);
        $this->insert(['category_id' => $hardwareId, 'amount' => 2300.50]);
        $this->insert(['category_id' => $softwareId, 'amount' => 499.90]);
    }
}

$report = new SaleReportModel();
$report->seed();

echo "=== Relatorio Agregado por Categoria (JOIN + GROUP BY + COUNT/SUM) ===" . PHP_EOL;
var_export($report->select(
    orderBy: 'category_id ASC',
    camelCaseTransform: true
));
echo PHP_EOL;


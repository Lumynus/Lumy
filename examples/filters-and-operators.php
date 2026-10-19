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

class EmployeeModel extends AbstractModel
{
    protected string $table = 'employees';

    protected array $fields = [
        'id' => ['db' => 'employees.id', 'select' => true],
        'full_name' => ['db' => 'employees.full_name', 'select' => true],
        'department' => ['db' => 'employees.department', 'select' => true],
        'role_name' => ['db' => 'employees.role_name', 'select' => true],
        'salary' => ['db' => 'employees.salary', 'select' => true],
        'manager_email' => ['db' => 'employees.manager_email', 'select' => true],
    ];

    public function seed(): void
    {
        $this->customQuery(
            'CREATE TABLE employees (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT NOT NULL,
                department TEXT NOT NULL,
                role_name TEXT NOT NULL,
                salary REAL NOT NULL,
                manager_email TEXT NULL
            )'
        );

        $rows = [
            ['full_name' => 'Alice Souza', 'department' => 'Engineering', 'role_name' => 'Tech Lead', 'salary' => 18500, 'manager_email' => null],
            ['full_name' => 'Bruno Lima', 'department' => 'Engineering', 'role_name' => 'Backend Dev', 'salary' => 11000, 'manager_email' => 'alice@empresa.com'],
            ['full_name' => 'Carla Mendes', 'department' => 'Product', 'role_name' => 'Product Manager', 'salary' => 15000, 'manager_email' => null],
            ['full_name' => 'Diego Rocha', 'department' => 'Security', 'role_name' => 'SecOps Analyst', 'salary' => 13500, 'manager_email' => 'alice@empresa.com'],
            ['full_name' => 'Elena Martins', 'department' => 'Sales', 'role_name' => 'Account Exec', 'salary' => 7500, 'manager_email' => 'carla@empresa.com'],
        ];

        foreach ($rows as $row) {
            $this->insert($row);
        }
    }
}

$employees = new EmployeeModel();
$employees->seed();

echo "=== 1. BETWEEN e ordenacao ===" . PHP_EOL;
var_export($employees->select(
    filters: ['salary BETWEEN' => [10000, 16000]],
    orderBy: 'salary DESC',
    fielders: ['fullName', 'department', 'salary'],
    camelCaseTransform: true
));
echo PHP_EOL . PHP_EOL;

echo "=== 2. IN e negacao com ! (NOT IN) ===" . PHP_EOL;
var_export($employees->select(
    filters: [
        'department' => ['Engineering', 'Security'],
        '!roleName' => ['Tech Lead'],
    ],
    fielders: ['fullName', 'department', 'roleName'],
    camelCaseTransform: true
));
echo PHP_EOL . PHP_EOL;

echo "=== 3. Condicao OR com || e LIKE ===" . PHP_EOL;
var_export($employees->select(
    filters: ['fullName LIKE || roleName LIKE' => 'Lead'],
    fielders: ['fullName', 'roleName'],
    camelCaseTransform: true
));
echo PHP_EOL . PHP_EOL;

echo "=== 4. Testando IS NULL e IS NOT NULL ===" . PHP_EOL;
var_export($employees->select(
    filters: ['managerEmail' => null],
    fielders: ['fullName', 'roleName', 'managerEmail'],
    camelCaseTransform: true
));
echo PHP_EOL;


<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;
use Lumynus\Lumy\Model;

new Config(
    driver: 'sqlite',
    host: '',
    user: '',
    password: null,
    database: ':memory:',
    connectionIdentifier: 'default'
);

class TblModel extends AbstractModel
{
    protected string $table = 'tbl';

    public function createTable(): void
    {
        $this->customQuery(
            'CREATE TABLE tbl (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                quantity INTEGER NOT NULL
            )'
        );
    }

    public function all(): array|bool
    {
        return $this->customQuery("SELECT * FROM {$this->table}");
    }
}

$model = new TblModel();
$model->createTable();
$insertedId = $model->insert(['name' => 'Teclado', 'quantity' => 5]);
$model->insert(['name' => 'Mouse', 'quantity' => 8]);

echo "Select paginado:" . PHP_EOL;
var_export($model->select(
    orderBy: 'id DESC',
    offset: 0,
    limit: 10,
    fielders: ['id', 'name', 'quantity'],
    camelCaseTransform: true
));

echo PHP_EOL . PHP_EOL . "Get por Model:" . PHP_EOL;
$dynamicModel = new Model('tbl');
var_export($dynamicModel->get(['id' => $insertedId], camelCaseTransform: true));
echo PHP_EOL;
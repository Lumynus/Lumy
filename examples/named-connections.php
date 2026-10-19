<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;

Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'sqlite',
    database: ':memory:'
);

Config::setConnection(
    connectionIdentifier: 'audit',
    driver: 'sqlite',
    database: ':memory:'
);

class MainModel extends AbstractModel
{
    protected string $table = 'tbl';

    public function createTable(): void
    {
        $this->customQuery('CREATE TABLE tbl (id INTEGER PRIMARY KEY, name TEXT)');
    }

    public function add(string $name): void
    {
        $this->insert(['name' => $name]);
    }

    public function all(): array|bool
    {
        return $this->select(orderBy: 'id ASC', limit: null);
    }
}

class AuditModel extends AbstractModel
{
    protected string $table = 'tbl';

    public function __construct()
    {
        parent::__construct('audit');
    }

    public function createTable(): void
    {
        $this->customQuery('CREATE TABLE tbl (id INTEGER PRIMARY KEY, event TEXT)');
    }

    public function add(string $event): void
    {
        $this->insert(['event' => $event]);
    }

    public function all(): array|bool
    {
        return $this->select(orderBy: 'id ASC', limit: null);
    }
}

$main = new MainModel();
$audit = new AuditModel();

$main->createTable();
$audit->createTable();

$main->add('Registro principal');
$audit->add('Evento de auditoria');

echo "Conexao default:" . PHP_EOL;
var_export($main->all());

echo PHP_EOL . PHP_EOL . "Conexao audit:" . PHP_EOL;
var_export($audit->all());
echo PHP_EOL;
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\Config;
use Lumynus\Lumy\Model;

/**
 * Demonstra como registrar configuracoes para todos os bancos de dados
 * suportados pelo Lumy MicroORM.
 *
 * Nota: Config::setConnection() apenas registra a configuracao em memoria;
 * a conexao real so e aberta quando um Model/Migrations e instanciado.
 */

// 1. SQLite (em memoria ou caminho de arquivo)
Config::setConnection(
    connectionIdentifier: 'sqlite_mem',
    driver: 'sqlite',
    database: ':memory:'
);

Config::setConnection(
    connectionIdentifier: 'sqlite_file',
    driver: 'sqlite',
    database: __DIR__ . '/storage/database.sqlite'
);

// 2. MySQL / MariaDB (usa mysqli nativo por padrao, ou pdo_mysql como fallback/explicito)
Config::setConnection(
    connectionIdentifier: 'mysql_main',
    driver: 'mysql', // tambem aceita 'mariadb' ou 'pdo_mysql'
    host: '127.0.0.1:3306',
    user: 'app_user',
    password: getenv('MYSQL_PASSWORD') ?: 'secret',
    database: 'lumy_app'
);

// 3. PostgreSQL (via PDO pgsql)
Config::setConnection(
    connectionIdentifier: 'postgres_analytics',
    driver: 'postgresql', // tambem aceita 'pgsql' ou 'postgres'
    host: '127.0.0.1:5432',
    user: 'postgres',
    password: getenv('PGSQL_PASSWORD') ?: 'secret',
    database: 'lumy_analytics'
);

// 4. Microsoft SQL Server (via PDO sqlsrv)
Config::setConnection(
    connectionIdentifier: 'sqlserver_erp',
    driver: 'sqlserver', // tambem aceita 'sqlsrv' ou 'mssql'
    host: '127.0.0.1:1433',
    user: 'sa',
    password: getenv('SQLSRV_PASSWORD') ?: 'StrongPass!123',
    database: 'lumy_erp'
);

// 5. Firebird / InterBase (usa ext-interbase se disponivel ou pdo_firebird automaticamente)
Config::setConnection(
    connectionIdentifier: 'firebird_legacy',
    driver: 'firebird', // tambem aceita 'ibase' ou 'pdo_firebird'
    host: '127.0.0.1',
    user: 'SYSDBA',
    password: getenv('FIREBIRD_PASSWORD') ?: 'masterkey',
    database: '/var/lib/firebird/data/lumy.fdb'
);

echo "=== Configuracoes registradas com sucesso ===" . PHP_EOL;
foreach (['sqlite_mem', 'sqlite_file', 'mysql_main', 'postgres_analytics', 'sqlserver_erp', 'firebird_legacy'] as $id) {
    $cfg = Config::getConnection($id);
    echo sprintf(
        "- [%s] driver=%s | host=%s | database=%s" . PHP_EOL,
        $id,
        $cfg['driver'],
        $cfg['host'] !== '' ? $cfg['host'] : '(n/a)',
        $cfg['database']
    );
}

// Executando operacao real na conexao SQLite em memoria para validar o fluxo
$logs = new Model('system_logs', 'sqlite_mem');
$ref = new ReflectionMethod($logs, 'customQuery');
$ref->setAccessible(true);
$ref->invoke($logs, 'CREATE TABLE system_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL)');

$logs->insert(['message' => 'Conexao SQLite testada com sucesso!']);
echo PHP_EOL . "=== Teste pratico na conexao 'sqlite_mem' ===" . PHP_EOL;
var_export($logs->select());
echo PHP_EOL;


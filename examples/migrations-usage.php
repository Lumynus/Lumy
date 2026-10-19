<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\Config;
use Lumynus\Lumy\Migrations;
use Lumynus\Lumy\Model;

$tempMigrationsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lumy_demo_migrations_' . getmypid();
if (!is_dir($tempMigrationsDir)) {
    mkdir($tempMigrationsDir, 0777, true);
}

file_put_contents(
    $tempMigrationsDir . DIRECTORY_SEPARATOR . '001_create_tasks.sql',
    <<<'SQL'
    -- Criacao da tabela de tarefas
    CREATE TABLE tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        done INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_tasks_done ON tasks (done);
    SQL
);

file_put_contents(
    $tempMigrationsDir . DIRECTORY_SEPARATOR . '002_seed_tasks.sql',
    <<<'SQL'
    INSERT INTO tasks (title, done) VALUES ('Configurar banco de dados', 1);
    INSERT INTO tasks (title, done) VALUES ('Executar migrations SQL', 1);
    SQL
);

try {
    new Config(
        driver: 'sqlite',
        database: ':memory:',
        connectionIdentifier: 'default',
        migrationsPath: $tempMigrationsDir
    );

    $migrations = new Migrations('default');

    echo "=== 1. Primeira execucao das migrations ===" . PHP_EOL;
    $appliedFirst = $migrations->run();
    var_export($appliedFirst);
    echo PHP_EOL . PHP_EOL;

    echo "=== 2. Segunda execucao (idempotente - nada pendente) ===" . PHP_EOL;
    $appliedSecond = $migrations->run();
    var_export($appliedSecond);
    echo PHP_EOL . PHP_EOL;

    echo "=== 3. Registros criados pelas migrations ===" . PHP_EOL;
    $tasks = new Model('tasks');
    var_export($tasks->select(orderBy: 'id ASC'));
    echo PHP_EOL;
} finally {
    foreach (glob($tempMigrationsDir . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tempMigrationsDir);
}


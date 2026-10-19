<?php

declare(strict_types=1);

use Lumynus\Lumy\Migrations;

$projectRoot = dirname(__DIR__);
$connectionIdentifier = trim((string) ($argv[1] ?? ''));
$bootstrapFile = $argv[2] ?? $projectRoot . DIRECTORY_SEPARATOR . 'bootstrap.php';
$migrationsDirectory = $argv[3] ?? null;

if ($connectionIdentifier === '') {
    fwrite(STDERR, "Usage: php bin/migrate.php <connectionIdentifier> [bootstrap.php] [migrations-directory]" . PHP_EOL);
    exit(2);
}

if (!is_file($bootstrapFile)) {
    fwrite(STDERR, "Configuration bootstrap file not found: {$bootstrapFile}" . PHP_EOL);
    fwrite(STDERR, "Pass a PHP file that loads Composer and registers the Config connection as the second argument." . PHP_EOL);
    exit(2);
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $bootstrapFile;

try {
    $runner = new Migrations($connectionIdentifier, $migrationsDirectory);
    $executed = $runner->run();

    if ($executed === []) {
        fwrite(STDOUT, 'No pending migrations.' . PHP_EOL);
        exit(0);
    }

    foreach ($executed as $migration) {
        fwrite(STDOUT, "Applied: {$migration}" . PHP_EOL);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

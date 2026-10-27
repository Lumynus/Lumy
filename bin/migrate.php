<?php

declare(strict_types=1);

use Lumynus\Lumy\Config;
use Lumynus\Lumy\Migrations;

$libraryRoot = dirname(__DIR__);
$workingRoot = getcwd() ?: $libraryRoot;

$autoloadCandidates = [
    $workingRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
    dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'autoload.php',
    $libraryRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
];

$autoloadLoaded = false;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    fwrite(STDERR, "Composer autoload.php not found. Run 'composer install' or 'composer dump-autoload'." . PHP_EOL);
    exit(2);
}

$connectionIdentifier = trim((string) ($argv[1] ?? ''));
$explicitBootstrap = isset($argv[2]) && trim((string) $argv[2]) !== '' ? trim((string) $argv[2]) : null;
$migrationsDirectory = isset($argv[3]) && trim((string) $argv[3]) !== '' ? trim((string) $argv[3]) : null;

if ($connectionIdentifier === '') {
    fwrite(STDERR, "Usage: php bin/migrate.php <connectionIdentifier> [config-or-bootstrap.php] [migrations-directory]" . PHP_EOL);
    exit(2);
}

$configCallPattern = '/\bConfig\s*::\s*setConnection\b|\bnew\s+\\\\?(?:Lumynus\\\\Lumy\\\\)?Config\s*\(/';

/**
 * Loads a PHP file and registers its Lumy Config whether defined at file scope,
 * inside a returned closure, or inside a Model/Class constructor or method.
 */
$loadConfigFile = static function (string $filePath) use ($connectionIdentifier, $configCallPattern): void {
    $realTarget = realpath($filePath);
    if ($realTarget === false) {
        return;
    }

    $result = require_once $realTarget;
    if (is_callable($result)) {
        try {
            $result();
        } catch (Throwable) {
        }
    }

    if (Config::hasConnection($connectionIdentifier)) {
        return;
    }

    $lines = @file($realTarget);
    if (!is_array($lines)) {
        return;
    }

    foreach (get_declared_classes() as $className) {
        $reflection = new ReflectionClass($className);
        if ($reflection->getFileName() !== $realTarget) {
            continue;
        }

        foreach ($reflection->getMethods() as $method) {
            if ($method->getFileName() !== $realTarget || $method->isAbstract()) {
                continue;
            }
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();
            if ($startLine === false || $endLine === false) {
                continue;
            }

            $methodBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
            if (!preg_match($configCallPattern, $methodBody)) {
                continue;
            }

            try {
                if ($method->isStatic()) {
                    $method->invoke(null);
                } elseif (!$reflection->isAbstract()) {
                    $instance = $reflection->newInstanceWithoutConstructor();
                    $method->invoke($instance);
                } elseif ( count($reflection->getAbstractMethods()) === 0) {
                    $anon = eval("return new class extends \\{$className} { public function __construct() {} };");
                    $method->invoke($anon);
                }
            } catch (Throwable) {
                // Ignore downstream errors after Config registration (e.g. missing table in base model)
            }

            if (Config::hasConnection($connectionIdentifier)) {
                return;
            }
        }
    }
};

if ($explicitBootstrap !== null) {
    if (!is_file($explicitBootstrap)) {
        fwrite(STDERR, "Configuration bootstrap file not found: {$explicitBootstrap}" . PHP_EOL);
        exit(2);
    }

    $loadConfigFile($explicitBootstrap);
} else {
    // 1. Check standard configuration and bootstrap locations in the project
    $conventionalFiles = [
        $workingRoot . DIRECTORY_SEPARATOR . 'bootstrap.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'lumy.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'db.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'config.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'database.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Database.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'database.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'config.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'database.php',
        $workingRoot . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
    ];

    foreach ($conventionalFiles as $candidate) {
        if (is_file($candidate)) {
            $loadConfigFile($candidate);
            if (Config::hasConnection($connectionIdentifier)) {
                break;
            }
        }
    }

    // 2. Auto-discover any PHP file in the project (including Models/Classes) that registers a Lumy Config
    if (!Config::hasConnection($connectionIdentifier) && is_dir($workingRoot)) {
        $excludedDirs = [
            'vendor',
            'node_modules',
            '.git',
            '.idea',
            '.vscode',
            'storage',
            'cache',
            'tmp',
            'logs',
            'tests',
            'examples',
            'bin',
            'public',
        ];

        $directoryIterator = new RecursiveDirectoryIterator(
            $workingRoot,
            FilesystemIterator::SKIP_DOTS
        );
        $filterIterator = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (SplFileInfo $current) use ($excludedDirs): bool {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), $excludedDirs, true);
                }

                return strtolower($current->getExtension()) === 'php'
                    && strtolower($current->getFilename()) !== 'index.php';
            }
        );

        /** @var SplFileInfo $fileInfo */
        foreach (new RecursiveIteratorIterator($filterIterator) as $fileInfo) {
            $path = $fileInfo->getRealPath();
            if ($path === false) {
                continue;
            }

            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if (preg_match($configCallPattern, $contents)) {
                $loadConfigFile($path);
                if (Config::hasConnection($connectionIdentifier)) {
                    break;
                }
            }
        }
    }
}

if (!Config::hasConnection($connectionIdentifier)) {
    fwrite(
        STDERR,
        "Database configuration '{$connectionIdentifier}' was not found." . PHP_EOL
        . "Make sure 'Config::setConnection(\"{$connectionIdentifier}\", ...)' or 'new Config(..., connectionIdentifier: \"{$connectionIdentifier}\")' is registered, "
        . "or pass the path to your config file as the 2nd argument:" . PHP_EOL
        . "  php bin/migrate.php {$connectionIdentifier} path/to/your-config.php" . PHP_EOL
    );
    exit(2);
}

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

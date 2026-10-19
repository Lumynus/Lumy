<?php

declare(strict_types=1);

namespace Lumynus\Lumy;

use Throwable;

/** Executes pending SQL migration files for a configured connection. */
final class Migrations extends AbstractLumy
{
    private string $migrationsDirectory;

    public function __construct(
        string $connectionIdentifier = 'default',
        ?string $migrationsDirectory = null
    ) {
        $configuration = Config::getConnection($connectionIdentifier);
        $this->migrationsDirectory = $migrationsDirectory
            ?? $configuration['migrationsPath']
            ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        parent::__construct($connectionIdentifier);
    }

    /**
     * Executes pending .sql files in natural filename order.
     *
     * A migration is recorded only after all its statements succeed.
     *
     * @return list<string> Names of the migration files executed by this call.
     */
    public function run(): array
    {
        if (!is_dir($this->migrationsDirectory)) {
            throw new \InvalidArgumentException(
                "Migration directory does not exist: {$this->migrationsDirectory}"
            );
        }

        $files = glob(rtrim($this->migrationsDirectory, '\\/') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        $this->ensureHistoryTable();
        $appliedRows = $this->customQuery('SELECT migration FROM lumy_migrations');
        $applied = [];
        foreach (is_array($appliedRows) ? $appliedRows : [] as $row) {
            $name = $row['migration'] ?? $row['MIGRATION'] ?? null;
            if (is_string($name)) {
                $applied[$name] = true;
            }
        }

        $executed = [];
        $driver = $this->getConnection()->getDriverName();
        $useTransaction = $driver !== 'mysql';

        foreach ($files as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException("Unable to read migration file: {$name}");
            }

            $statements = $this->splitStatements($contents);
            if ($useTransaction) {
                $this->getConnection()->beginTransaction();
            }

            try {
                foreach ($statements as $statement) {
                    $this->customQuery($statement);
                }

                $this->customQuery(
                    'INSERT INTO lumy_migrations (migration) VALUES (?)',
                    [$name]
                );

                if ($useTransaction) {
                    $this->getConnection()->commit();
                }
            } catch (Throwable $exception) {
                if ($useTransaction) {
                    try {
                        $this->getConnection()->rollBack();
                    } catch (Throwable) {
                    }
                }

                throw new \RuntimeException(
                    "Migration '{$name}' failed: {$exception->getMessage()}",
                    0,
                    $exception
                );
            }

            $executed[] = $name;
        }

        return $executed;
    }

    private function ensureHistoryTable(): void
    {
        $driver = $this->getConnection()->getDriverName();

        if ($driver === 'sqlsrv') {
            $this->customQuery(
                "IF OBJECT_ID(N'lumy_migrations', N'U') IS NULL "
                . 'CREATE TABLE lumy_migrations ('
                . 'migration NVARCHAR(255) NOT NULL PRIMARY KEY, '
                . 'executed_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME())'
            );
            return;
        }

        if ($driver === 'firebird') {
            $exists = $this->customQuery(
                'SELECT 1 FROM RDB$RELATIONS WHERE RDB$RELATION_NAME = ?',
                ['LUMY_MIGRATIONS']
            );
            if (is_array($exists) && $exists !== []) {
                return;
            }

            $this->customQuery(
                'CREATE TABLE lumy_migrations ('
                . 'migration VARCHAR(255) NOT NULL PRIMARY KEY, '
                . 'executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL)'
            );
            return;
        }

        $this->customQuery(
            'CREATE TABLE IF NOT EXISTS lumy_migrations ('
            . 'migration VARCHAR(255) NOT NULL PRIMARY KEY, '
            . 'executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)'
        );
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $statement = '';
        $quoteEnd = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($position = 0; $position < $length; $position++) {
            $character = $sql[$position];
            $nextCharacter = $sql[$position + 1] ?? '';

            if ($lineComment) {
                $statement .= $character;
                if ($character === "\n") {
                    $lineComment = false;
                }
                continue;
            }

            if ($blockComment) {
                $statement .= $character;
                if ($character === '*' && $nextCharacter === '/') {
                    $statement .= $nextCharacter;
                    $position++;
                    $blockComment = false;
                }
                continue;
            }

            if ($quoteEnd !== null) {
                $statement .= $character;
                if ($character === '\\' && $quoteEnd === "'" && $nextCharacter !== '') {
                    $statement .= $nextCharacter;
                    $position++;
                    continue;
                }
                if ($character === $quoteEnd) {
                    if ($nextCharacter === $quoteEnd) {
                        $statement .= $nextCharacter;
                        $position++;
                    } else {
                        $quoteEnd = null;
                    }
                }
                continue;
            }

            if (($character === '-' && $nextCharacter === '-') || $character === '#') {
                $statement .= $character;
                if ($character === '-') {
                    $statement .= $nextCharacter;
                    $position++;
                }
                $lineComment = true;
                continue;
            }

            if ($character === '/' && $nextCharacter === '*') {
                $statement .= '/*';
                $position++;
                $blockComment = true;
                continue;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quoteEnd = $character;
                $statement .= $character;
                continue;
            }

            if ($character === '[') {
                $quoteEnd = ']';
                $statement .= $character;
                continue;
            }

            if ($character === ';') {
                $this->appendStatement($statements, $statement);
                $statement = '';
                continue;
            }

            $statement .= $character;
        }

        if ($quoteEnd !== null || $blockComment) {
            throw new \InvalidArgumentException('Migration contains an unterminated SQL quote or comment.');
        }

        $this->appendStatement($statements, $statement);

        return $statements;
    }

    /** @param list<string> $statements */
    private function appendStatement(array &$statements, string $statement): void
    {
        $statement = trim($statement);
        if ($statement === '') {
            return;
        }

        $withoutComments = preg_replace(
            ['~/\\*.*?\\*/~s', '/--[^\\r\\n]*/', '/#[^\\r\\n]*/'],
            '',
            $statement
        );
        if (trim($withoutComments ?? $statement) !== '') {
            $statements[] = $statement;
        }
    }
}

<?php

declare(strict_types=1);

namespace Lumynus\Lumy;

use Throwable;

/** Runs pending SQL migration files for a configured connection. */
final class Migrations extends AbstractLumy
{
    private string $migrationsDirectory;

    /** Whether a server-side migration lock is currently held. */
    private bool $lockHeld = false;

    /** Seconds spent waiting for the advisory lock before failing. */
    private const LOCK_TIMEOUT_SECONDS = 60;

    public function __construct(
        string $connectionIdentifier = 'default',
        ?string $migrationsDirectory = null
    ) {
        $configuration = Config::getConnection($connectionIdentifier);
        $cwdMigrations = (getcwd() ?: '') . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $defaultDirectory = is_dir($cwdMigrations)
            ? $cwdMigrations
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        $this->migrationsDirectory = $migrationsDirectory
            ?? $configuration['migrationsPath']
            ?? $defaultDirectory;

        parent::__construct($connectionIdentifier);
    }

    /**
     * Runs pending .sql files in natural filename order.
     *
     * A migration is recorded only after all its statements succeed.
     * On MySQL, PostgreSQL and SQL Server a server-wide advisory lock is held
     * for the whole run so concurrent workers cannot apply the same files.
     * SQLite and Firebird have no advisory-lock facility; the per-file
     * transaction and the primary-key history table cover those dialects.
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

        $this->acquireLock();
        try {
            return $this->runLocked();
        } finally {
            $this->releaseLock();
        }
    }

    /** @return list<string> */
    private function runLocked(): array
    {
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
                'SELECT 1 FROM RDB$RELATIONS WHERE TRIM(RDB$RELATION_NAME) = ?',
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
        $dollarQuoteTag = null;
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

            if ($dollarQuoteTag !== null) {
                $tagLength = strlen($dollarQuoteTag);
                if (substr($sql, $position, $tagLength) === $dollarQuoteTag) {
                    $statement .= $dollarQuoteTag;
                    $position += $tagLength - 1;
                    $dollarQuoteTag = null;
                    continue;
                }
                $statement .= $character;
                continue;
            }

            if ($quoteEnd !== null) {
                $statement .= $character;
                if ($character === '\\' && ($quoteEnd === "'" || $quoteEnd === '"') && $nextCharacter !== '') {
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

            if ($character === '$') {
                $remaining = substr($sql, $position);
                if (preg_match('/\A(\$[A-Za-z_][A-Za-z0-9_]*\$|\$\$)/', $remaining, $matches)) {
                    $dollarQuoteTag = $matches[1];
                    $statement .= $dollarQuoteTag;
                    $position += strlen($dollarQuoteTag) - 1;
                    continue;
                }
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

        if ($quoteEnd !== null || $dollarQuoteTag !== null || $blockComment) {
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

    /**
     * Acquires a cross-process advisory lock for this migrations directory,
     * waiting up to LOCK_TIMEOUT_SECONDS. No-op on SQLite and Firebird.
     *
     * @throws \RuntimeException If the lock cannot be acquired in time.
     */
    private function acquireLock(): void
    {
        $driver = $this->getConnection()->getDriverName();
        $name = $this->lockName();

        switch ($driver) {
            case 'mysql':
                $rows = $this->customQuery('SELECT GET_LOCK(?, ?) AS acquired', [
                    $name,
                    self::LOCK_TIMEOUT_SECONDS,
                ]);
                $acquired = is_array($rows) ? (int) ($rows[0]['acquired'] ?? 0) : 0;
                if ($acquired !== 1) {
                    throw new \RuntimeException(
                        "Could not acquire migration lock '{$name}' within "
                        . self::LOCK_TIMEOUT_SECONDS . ' seconds.'
                    );
                }
                $this->lockHeld = true;
                return;

            case 'pgsql':
                for ($attempt = 0; $attempt < self::LOCK_TIMEOUT_SECONDS; $attempt++) {
                    $rows = $this->customQuery(
                        'SELECT pg_try_advisory_lock(hashtext(?)) AS acquired',
                        [$name]
                    );
                    $acquired = is_array($rows) && (bool) ($rows[0]['acquired'] ?? false);
                    if ($acquired) {
                        $this->lockHeld = true;
                        return;
                    }
                    sleep(1);
                }
                throw new \RuntimeException(
                    "Could not acquire migration lock '{$name}' within "
                    . self::LOCK_TIMEOUT_SECONDS . ' seconds.'
                );

            case 'sqlsrv':
                $rows = $this->customQuery(
                    'DECLARE @r INT; '
                    . "EXEC @r = sp_getapplock @Resource = ?, @LockMode = 'Exclusive', "
                    . "@LockOwner = 'Session', @LockTimeout = ?; "
                    . 'SELECT acquired = @r',
                    [$name, self::LOCK_TIMEOUT_SECONDS * 1000]
                );
                $acquired = is_array($rows) ? (int) ($rows[0]['acquired'] ?? -1) : -1;
                if ($acquired < 0) {
                    throw new \RuntimeException(
                        "Could not acquire migration lock '{$name}' within "
                        . self::LOCK_TIMEOUT_SECONDS . ' seconds.'
                    );
                }
                $this->lockHeld = true;
                return;

            default:
                // SQLite serializes writers and Firebird has no advisory-lock
                // facility; the history-table primary key guards both.
                return;
        }
    }

    /** Releases the advisory lock previously acquired; silent when none is held. */
    private function releaseLock(): void
    {
        if (!$this->lockHeld) {
            return;
        }
        $this->lockHeld = false;

        $driver = $this->getConnection()->getDriverName();
        $name = $this->lockName();

        try {
            switch ($driver) {
                case 'mysql':
                    $this->customQuery('SELECT RELEASE_LOCK(?)', [$name]);
                    return;
                case 'pgsql':
                    $this->customQuery('SELECT pg_advisory_unlock(hashtext(?))', [$name]);
                    return;
                case 'sqlsrv':
                    $this->customQuery(
                        "EXEC sp_releaseapplock @Resource = ?, @LockOwner = 'Session'",
                        [$name]
                    );
                    return;
            }
        } catch (Throwable) {
            // The session owning the lock may already be closed; the server
            // releases session locks on disconnect.
        }
    }

    /**
     * Deterministic server-wide lock name, unique per migrations directory so
     * unrelated projects on the same server do not serialize each other.
     */
    private function lockName(): string
    {
        return 'lumy_migrations_' . substr(hash('sha256', $this->migrationsDirectory), 0, 32);
    }
}

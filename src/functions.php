<?php

declare(strict_types=1);

namespace Lumynus\Lumy {
    use Lumynus\Lumy\Connections\LumyConnection;

    if (!\function_exists(__NAMESPACE__ . '\\transaction')) {
        /**
         * Executes a callback inside a database transaction.
         *
         * When $rollback is null, the transaction is rolled back automatically
         * and the thrown exception is rethrown. When a custom $rollback
         * callback is provided, the uncommitted database transaction is rolled
         * back and the user-defined $rollback callback is executed.
         *
         * @param callable $transaction Callback containing the transactional queries.
         * @param callable|null $rollback Optional custom rollback handler invoked on failure.
         * @param string $connectionIdentifier Connection name registered in Config.
         */
        function transaction(
            callable $transaction,
            ?callable $rollback = null,
            string $connectionIdentifier = 'default'
        ): mixed {
            return LumyConnection::runTransaction($transaction, $rollback, $connectionIdentifier);
        }
    }
}

namespace {
    use Lumynus\Lumy\Connections\LumyConnection;

    if (!\function_exists('transaction')) {
        /**
         * Global helper that executes a callback inside a database transaction.
         *
         * When $rollback is null, the transaction is rolled back automatically
         * and the thrown exception is rethrown. When a custom $rollback
         * callback is provided, the uncommitted database transaction is rolled
         * back and the user-defined $rollback callback is executed.
         *
         * @param callable $transaction Callback containing the transactional queries.
         * @param callable|null $rollback Optional custom rollback handler invoked on failure.
         * @param string $connectionIdentifier Connection name registered in Config.
         */
        function transaction(
            callable $transaction,
            ?callable $rollback = null,
            string $connectionIdentifier = 'default'
        ): mixed {
            return LumyConnection::runTransaction($transaction, $rollback, $connectionIdentifier);
        }
    }
}


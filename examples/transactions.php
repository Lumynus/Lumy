<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;
use Lumynus\Lumy\Model;

new Config(
    driver: 'sqlite',
    database: ':memory:',
    connectionIdentifier: 'default'
);

class WalletModel extends AbstractModel
{
    protected string $table = 'wallets';

    public function setup(): void
    {
        $this->customQuery(
            'CREATE TABLE wallets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner TEXT NOT NULL,
                balance REAL NOT NULL
            )'
        );
        $this->customQuery(
            'CREATE TABLE transfer_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                status TEXT NOT NULL,
                message TEXT NOT NULL
            )'
        );
    }

    public function transfer(int $fromId, int $toId, float $amount): void
    {
        $sender = $this->select(filters: ['id' => $fromId], limit: 1)['data'][0] ?? null;
        $receiver = $this->select(filters: ['id' => $toId], limit: 1)['data'][0] ?? null;

        if ($sender === null || $receiver === null) {
            throw new RuntimeException('Carteira nao encontrada.');
        }

        // debita primeiro e valida depois para provar que o rollback desfaz a query parcial
        $this->update(['balance' => (float) $sender['balance'] - $amount], ['id' => $fromId]);

        if ((float) $sender['balance'] < $amount) {
            throw new RuntimeException('Saldo insuficiente para transferencia.');
        }

        $this->update(['balance' => (float) $receiver['balance'] + $amount], ['id' => $toId]);
    }
}

$wallets = new WalletModel();
$logs = new Model('transfer_logs');
$wallets->setup();

$aliceId = $wallets->insert(['owner' => 'Alice', 'balance' => 1000.00]);
$bobId = $wallets->insert(['owner' => 'Bob', 'balance' => 250.00]);

// 1. Funcao global transaction() com COMMIT automatico
transaction(function () use ($wallets, $logs, $aliceId, $bobId): void {
    $wallets->transfer($aliceId, $bobId, 300.00);
    $logs->insert(['status' => 'SUCCESS', 'message' => 'Transferencia de R$ 300,00 concluida']);
});

echo "=== 1. Saldos apos transferencia de R$ 300,00 via transaction() (COMMIT) ===" . PHP_EOL;
var_export($wallets->select(orderBy: 'id ASC'));
echo PHP_EOL . PHP_EOL;

// 2. Funcao global transaction() SEM callback de rollback (faz rollback padrao e propaga excecao)
try {
    transaction(function () use ($wallets, $aliceId, $bobId): void {
        $wallets->transfer($aliceId, $bobId, 5000.00);
    });
} catch (RuntimeException $e) {
    echo "=== 2. Sem callback de rollback (rollback padrao automatico): {$e->getMessage()} ===" . PHP_EOL;
}

// 3. Funcao global transaction() COM callback de rollback definido pelo usuario
$fallbackResult = transaction(
    function () use ($wallets, $aliceId, $bobId): array {
        $wallets->transfer($aliceId, $bobId, 9999.00);
        return ['ok' => true];
    },
    function (Throwable $exception) use ($logs): array {
        // O rollback desfaz a alteracao de saldo e executa o que o usuario definiu aqui:
        $logs->insert([
            'status' => 'ROLLED_BACK',
            'message' => 'Rollback customizado: ' . $exception->getMessage(),
        ]);

        return [
            'ok' => false,
            'handledBy' => 'custom_rollback',
            'reason' => $exception->getMessage(),
        ];
    }
);

echo PHP_EOL . "=== 3. Resultado retornado pelo callback de rollback customizado ===" . PHP_EOL;
var_export($fallbackResult);
echo PHP_EOL . PHP_EOL;

echo "=== 4. Saldos intactos e historico de logs persistido ===" . PHP_EOL;
var_export([
    'wallets' => $wallets->select(orderBy: 'id ASC'),
    'logs' => $logs->select(orderBy: 'id ASC'),
]);
echo PHP_EOL;

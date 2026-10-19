<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;

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
    }

    public function transfer(int $fromId, int $toId, float $amount): void
    {
        $this->transaction(function (WalletModel $model) use ($fromId, $toId, $amount): void {
            $sender = $model->select(filters: ['id' => $fromId], limit: 1)[0] ?? null;
            $receiver = $model->select(filters: ['id' => $toId], limit: 1)[0] ?? null;

            if ($sender === null || $receiver === null) {
                throw new RuntimeException('Carteira nao encontrada.');
            }
            if ((float) $sender['balance'] < $amount) {
                throw new RuntimeException('Saldo insuficiente para transferencia.');
            }

            $model->update(['balance' => (float) $sender['balance'] - $amount], ['id' => $fromId]);
            $model->update(['balance' => (float) $receiver['balance'] + $amount], ['id' => $toId]);
        });
    }
}

$wallets = new WalletModel();
$wallets->setup();

$aliceId = $wallets->insert(['owner' => 'Alice', 'balance' => 1000.00]);
$bobId = $wallets->insert(['owner' => 'Bob', 'balance' => 250.00]);

// 1. Transferencia bem-sucedida (COMMIT automatico)
$wallets->transfer($aliceId, $bobId, 300.00);

echo "=== 1. Saldos apos transferencia de R$ 300,00 (COMMIT) ===" . PHP_EOL;
var_export($wallets->select(orderBy: 'id ASC'));
echo PHP_EOL . PHP_EOL;

// 2. Transferencia com saldo insuficiente (ROLLBACK automatico)
try {
    $wallets->transfer($aliceId, $bobId, 5000.00);
} catch (RuntimeException $e) {
    echo "=== 2. Excecao capturada (ROLLBACK executado): {$e->getMessage()} ===" . PHP_EOL;
}

var_export($wallets->select(orderBy: 'id ASC'));
echo PHP_EOL;


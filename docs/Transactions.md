# Guia Completo de Transações (`transaction`)

O **Lumy MicroORM** oferece suporte completo a transações de banco de dados em todos os 5 dialetos suportados (**SQLite**, **MySQL/MariaDB**, **PostgreSQL**, **SQL Server** e **Firebird/InterBase**), incluindo **transações aninhadas via Savepoints**.

Você pode gerenciar transações de **5 formas diferentes**, de acordo com a necessidade da sua aplicação:

1. **Função Global `transaction($transaction)`** — Rollback automático padrão;
2. **Função Global `transaction($transaction, $rollback)`** — Rollback customizado definido pelo usuário;
3. **Função Global `transaction($transaction, $rollback, $connectionIdentifier)`** — Transações em conexões nomeadas (múltiplos bancos);
4. **Método na Model `$model->transaction($transaction, $rollback)`** — Escopo direto na instância da Model;
5. **Controle Manual (`beginTransaction`, `commit`, `rollBack`)** — Controle imperativo passo a passo.

---

## Assinatura da Função Global `transaction()`

Carregada automaticamente pelo Composer (`src/functions.php`), a função está disponível tanto no namespace global (`\transaction`) quanto no namespace da biblioteca (`\Lumynus\Lumy\transaction`):

```php
transaction(
    callable $transaction,
    ?callable $rollback = null,
    string $connectionIdentifier = 'default'
): mixed
```

- **`$transaction`**: Função/closure contendo as queries que devem rodar dentro da transação. Pode receber opcionalmente `(DatabaseDriverInterface $connection)`. Qualquer valor retornado por `$transaction` será devolvido por `transaction()` após o `commit`.
- **`$rollback`** *(opcional, padrão `null`)*:
  - Quando **`null` (padrão)**: se qualquer erro/exceção ocorrer dentro de `$transaction`, o Lumy executa o `rollBack()` automaticamente no banco de dados e relança a exceção original.
  - Quando **informado (`callable`)**: se qualquer erro/exceção ocorrer dentro de `$transaction`, o Lumy primeiro desfaz a transação pendente no banco (`rollBack()`) e, em seguida, executa o código que você definiu no callback `$rollback`, retornando o resultado dele.
- **`$connectionIdentifier`** *(opcional, padrão `'default'`)*: Identificador da conexão registrada em `Config`. Todas as Models que usam esse mesmo identificador compartilham automaticamente a transação aberta.

---

## 1. Forma 1: Rollback Padrão Automático (`$rollback = null`)

Use quando você quer que o banco desfaça tudo automaticamente em caso de falha e deixe a exceção subir para a camada superior (ex.: um *exception handler* HTTP):

```php
use Lumynus\Lumy\Model;

$wallets = new Model('wallets');
$logs    = new Model('transfer_logs');

$transferData = transaction(function () use ($wallets, $logs): array {
    // 1. Debita da carteira de origem
    $wallets->update(['balance' => 700.00], ['id' => 1]);

    // 2. Credita na carteira de destino
    $wallets->update(['balance' => 550.00], ['id' => 2]);

    // 3. Registra log da transferência
    $logId = $logs->insert([
        'status'  => 'SUCCESS',
        'message' => 'Transferência de R$ 300,00 concluída',
    ]);

    return ['logId' => $logId, 'status' => 'committed'];
});
```

Se qualquer query falhar ou uma exceção for lançada no meio do bloco, o `rollBack()` é executado automaticamente e nenhuma alteração fica gravada no banco.

---

## 2. Forma 2: Rollback Customizado pelo Usuário (`$rollback` definido)

Use quando você deseja definir o que deve acontecer após o rollback da transação — por exemplo, gravar um registro de auditoria/falha no banco, executar uma ação compensatória ou retornar uma resposta amigável sem estourar exceção:

```php
$resultado = transaction(
    transaction: function () use ($wallets): array {
        $wallets->update(['balance' => 0.00], ['id' => 1]);

        // Simula regra de negócio reprovada
        throw new \RuntimeException('Saldo insuficiente para concluir a transferência.');
    },
    rollback: function (\Throwable $exception) use ($logs): array {
        // Neste ponto, o UPDATE acima JÁ FOI DESFEITO no banco de dados!
        // A conexão já está limpa e pronta para executar queries de auditoria/compensação:
        $logs->insert([
            'status'  => 'ROLLED_BACK',
            'message' => 'Rollback customizado: ' . $exception->getMessage(),
        ]);

        return [
            'ok'     => false,
            'reason' => $exception->getMessage(),
        ];
    }
);

// $resultado === ['ok' => false, 'reason' => 'Saldo insuficiente para concluir a transferência.']
```

### Variações de Assinatura Aceitas pelo Callback `$rollback`

O Lumy inspeciona automaticamente a quantidade de parâmetros da sua função `$rollback` via `ReflectionFunction`:

```php
// 2.1. Sem parâmetros (quando você não precisa dos detalhes do erro)
transaction(
    fn () => $wallets->insert(['owner' => 'Teste', 'balance' => 100]),
    fn () => ['ok' => false, 'message' => 'Operação cancelada e revertida.']
);

// 2.2. Recebendo apenas a exceção (\Throwable)
transaction(
    function () use ($wallets) {
        $wallets->insert(['owner' => 'Teste', 'balance' => 100]);
        throw new \DomainException('Limite diário excedido');
    },
    function (\Throwable $e): array {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
);

// 2.3. Recebendo a exceção e o driver da conexão (\Throwable, DatabaseDriverInterface)
use Lumynus\Lumy\Contracts\DatabaseDriverInterface;

transaction(
    function (DatabaseDriverInterface $conn) {
        $conn->query('INSERT INTO wallets (owner, balance) VALUES (?, ?)', ['Teste', 100]);
        throw new \RuntimeException('Falha na validação externa');
    },
    function (\Throwable $e, DatabaseDriverInterface $conn): void {
        $conn->query(
            'INSERT INTO transfer_logs (status, message) VALUES (?, ?)',
            ['ERROR', $e->getMessage()]
        );
    }
);
```

> **Dica:** Se você quiser executar uma lógica customizada no `$rollback` **e ainda assim** propagar a exceção para cima, basta fazer `throw $e;` no final do seu callback `$rollback`.

---

## 3. Forma 3: Transações em Conexões Nomeadas (`$connectionIdentifier`)

Quando sua aplicação trabalha com múltiplos bancos de dados (ex.: `'default'`, `'audit'`, `'erp'`), passe o identificador da conexão no 3º argumento:

```php
use Lumynus\Lumy\Model;

$erpOrders = new Model('orders', 'erp');
$erpStock  = new Model('stock', 'erp');

transaction(
    transaction: function () use ($erpOrders, $erpStock): void {
        $erpOrders->insert(['product_id' => 10, 'qty' => 2]);
        $erpStock->update(['qty' => 8], ['product_id' => 10]);
    },
    rollback: function (\Throwable $e): void {
        error_log('Erro na transação do ERP: ' . $e->getMessage());
    },
    connectionIdentifier: 'erp'
);
```

Você também pode chamar via namespace ou diretamente na classe `LumyConnection`:

```php
use function Lumynus\Lumy\transaction;
use Lumynus\Lumy\Connections\LumyConnection;

// Via função no namespace Lumynus\Lumy
\Lumynus\Lumy\transaction($callback, $rollback, 'erp');

// Via método estático em LumyConnection
LumyConnection::runTransaction($callback, $rollback, 'erp');
```

---

## 4. Forma 4: Método `$model->transaction()` na Própria Model

Todas as classes que estendem `AbstractLumy` / `AbstractModel` (incluindo `Model`) possuem o método de instância `transaction(callable $callback, ?callable $rollback = null)`.

Nesse formato, o primeiro parâmetro passado para `$callback` (e o segundo parâmetro passado para `$rollback`) é a própria instância da `$model`:

```php
// 4.1. Com rollback padrão automático
$walletModel->transaction(function (WalletModel $model): void {
    $model->update(['balance' => 700.00], ['id' => 1]);
    $model->update(['balance' => 550.00], ['id' => 2]);
});

// 4.2. Com callback de rollback customizado
$resultado = $walletModel->transaction(
    function (WalletModel $model): bool {
        $model->update(['balance' => 0.00], ['id' => 1]);
        throw new \RuntimeException('Operação não autorizada');
    },
    function (\Throwable $e, WalletModel $model) use ($logModel): bool {
        $logModel->insert([
            'status'  => 'ROLLED_BACK',
            'message' => $e->getMessage(),
        ]);
        return false;
    }
);
```

---

## 5. Forma 5: Transações Aninhadas (Savepoints)

O Lumy gerencia automaticamente o aninhamento de transações por meio de contador de profundidade (`$transactionDepth`) e `SAVEPOINT`. Se uma transação interna falhar, **apenas o savepoint interno é desfeito**, mantendo a transação externa ativa e íntegra:

```php
transaction(function () use ($wallets, $logs): void {
    // Escopo externo (nível 1 - BEGIN TRANSACTION)
    $wallets->insert(['owner' => 'Charlie', 'balance' => 500.00]);

    // Escopo interno (nível 2 - SAVEPOINT lumy_sp_1)
    transaction(
        transaction: function () use ($wallets): void {
            $wallets->insert(['owner' => 'TentativaBonus', 'balance' => 9999.00]);
            throw new \RuntimeException('Bônus inválido, reverter apenas o savepoint');
        },
        rollback: function (\Throwable $e) use ($logs): void {
            // O insert 'TentativaBonus' já sofreu ROLLBACK TO SAVEPOINT lumy_sp_1!
            // Podemos registrar o aviso sem perder o cadastro de 'Charlie':
            $logs->insert([
                'status'  => 'PARTIAL_ROLLBACK',
                'message' => $e->getMessage(),
            ]);
        }
    );

    // Ao sair desta função externa sem erros, 'Charlie' e o log 'PARTIAL_ROLLBACK'
    // recebem COMMIT normalmente!
});
```

### Comportamento de Savepoints por Banco:
- **SQLite, MySQL, MariaDB, PostgreSQL, Firebird/InterBase (`PdoDriver`, `MysqlDriver`, `IbaseDriver`)**:
  - `SAVEPOINT lumy_sp_N`
  - `RELEASE SAVEPOINT lumy_sp_N` (no commit do bloco interno)
  - `ROLLBACK TO SAVEPOINT lumy_sp_N` (no rollback do bloco interno)
- **Microsoft SQL Server (`PdoDriver` com `sqlserver`)**:
  - `SAVE TRANSACTION lumy_sp_N`
  - `ROLLBACK TRANSACTION lumy_sp_N`

---

## 6. Forma 6: Controle Manual (`beginTransaction`, `commit`, `rollBack`)

Caso prefira controlar o início, confirmação e reversão manualmente:

```php
try {
    $walletModel->beginTransaction();

    $walletModel->update(['balance' => 700.00], ['id' => 1]);
    $walletModel->update(['balance' => 550.00], ['id' => 2]);

    $walletModel->commit();
} catch (\Throwable $e) {
    $walletModel->rollBack();
    throw $e;
}
```

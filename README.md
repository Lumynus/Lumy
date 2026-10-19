# Lumy MicroORM

**Lumy** é um MicroORM PHP leve, seguro e multi-banco desenvolvido pela **Lumynus**. Ele oferece mapeamento de campos, CRUD parametrizado, `upsert` nativo por dialeto, construção expressiva de filtros, relacionamentos `1:1` e `1:N` em lote (sem problema de *N+1* e sem corromper paginação), joins/agregações, transações, pool de conexões em memória e runner de migrations SQL.

---

## Sumário

1. [Bancos de Dados Suportados](#1-bancos-de-dados-suportados)
2. [Instalação](#2-instalação)
3. [Como Configurar Cada Banco de Dados](#3-como-configurar-cada-banco-de-dados)
4. [Formas de Uso (Model Rápido vs. Subclasse `AbstractModel`)](#4-formas-de-uso-model-rápido-vs-subclasse-abstractmodel)
5. [Operações CRUD e Upsert](#5-operações-crud-e-upsert)
6. [Consultas (`select`), Filtros e Operadores](#6-consultas-select-filtros-e-operadores)
7. [Relacionamentos (`1:1`, `1:N` e Aninhados)](#7-relacionamentos-11-1n-e-aninhados)
8. [Joins, Aliases e Agregações (`GROUP BY`)](#8-joins-aliases-e-agregações-group-by)
9. [Transações](#9-transações)
10. [Múltiplas Conexões e Gerenciamento de Pool](#10-múltiplas-conexões-e-gerenciamento-de-pool)
11. [Migrations SQL (CLI e Programático)](#11-migrations-sql-cli-e-programático)
12. [Segurança e Boas Práticas](#12-segurança-e-boas-práticas)
13. [Exemplos Práticos e Testes](#13-exemplos-práticos-e-testes)

---

## 1. Bancos de Dados Suportados

O Lumy abstrai as diferenças de dialeto SQL (paginação, `upsert`, transações, `lastInsertId`, `threadId` e histórico de migrations) para **5 famílias de bancos de dados**:

| Banco de Dados | Valores aceitos em `driver` | Extensão PHP utilizada | Sintaxe de Paginação | Sintaxe de Upsert |
| :--- | :--- | :--- | :--- | :--- |
| **MySQL / MariaDB** | `'mysql'`, `'mariadb'`, `'pdo_mysql'` | `ext-mysqli` (padrão) ou `ext-pdo_mysql` (fallback/explícito) | `LIMIT x OFFSET y` | `INSERT ... ON DUPLICATE KEY UPDATE` |
| **PostgreSQL** | `'postgresql'`, `'pgsql'`, `'postgres'` | `ext-pdo_pgsql` | `LIMIT x OFFSET y` | `INSERT ... ON CONFLICT (...) DO UPDATE` |
| **SQLite** | `'sqlite'` | `ext-pdo_sqlite` | `LIMIT x OFFSET y` | `INSERT ... ON CONFLICT (...) DO UPDATE` |
| **SQL Server** | `'sqlserver'`, `'sqlsrv'`, `'mssql'` | `ext-pdo_sqlsrv` | `OFFSET y ROWS FETCH NEXT x ROWS ONLY` | `MERGE ... WITH (HOLDLOCK)` |
| **Firebird / InterBase** | `'firebird'`, `'ibase'`, `'pdo_firebird'` | `ext-interbase` (`ibase`) ou `ext-pdo_firebird` (fallback automático no PHP 8+) | `ROWS m TO n` | `UPDATE OR INSERT INTO ... MATCHING (...)` |

---

## 2. Instalação

Requer **PHP 8.2+** (compatível com PHP 8.2, 8.3, 8.4 e 8.5).

```bash
composer require lumynus/lumy
```

Ou em desenvolvimento local no repositório:

```bash
composer dump-autoload
```

---

## 3. Como Configurar Cada Banco de Dados

As configurações são registradas em memória na classe `Lumynus\Lumy\Config` (via `new Config(...)` ou `Config::setConnection(...)`). A conexão real com o banco só é aberta sob demanda quando uma Model ou Migration é utilizada.

### 3.1. MySQL e MariaDB
Usa `mysqli` por padrão com `utf8mb4` automático. Caso `mysqli` não esteja instalada ou você informe `'pdo_mysql'`, utiliza `PDO` automaticamente. Você pode informar a porta junto ao host (`host:porta`).

```php
use Lumynus\Lumy\Config;

new Config(
    driver: 'mysql', // 'mysql', 'mariadb' ou 'pdo_mysql'
    host: '127.0.0.1:3306',
    user: 'app_user',
    password: 'secret_password',
    database: 'minha_aplicacao',
    connectionIdentifier: 'default'
);
```

### 3.2. PostgreSQL
Usa `pdo_pgsql`. Suporta porta customizada no `host` (`127.0.0.1:5432`).

```php
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'postgresql', // 'postgresql', 'pgsql' ou 'postgres'
    host: '127.0.0.1:5432',
    user: 'postgres',
    password: 'secret_password',
    database: 'minha_aplicacao'
);
```

### 3.3. SQLite (Arquivo ou Memória)
Usa `pdo_sqlite`. Não requer `host`, `user` ou `password`.

```php
// Banco em memória (excelente para testes automatizados e prototipação)
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'sqlite',
    database: ':memory:'
);

// Banco em arquivo físico
Config::setConnection(
    connectionIdentifier: 'local_file',
    driver: 'sqlite',
    database: __DIR__ . '/storage/database.sqlite'
);
```

### 3.4. Microsoft SQL Server
Usa `pdo_sqlsrv`. Portas informadas no formato `127.0.0.1:1433` são convertidas automaticamente para o formato `Server=127.0.0.1,1433` exigido pelo driver SQL Server.

```php
Config::setConnection(
    connectionIdentifier: 'erp',
    driver: 'sqlserver', // 'sqlserver', 'sqlsrv' ou 'mssql'
    host: '127.0.0.1:1433',
    user: 'sa',
    password: 'StrongPassword!123',
    database: 'erp_production'
);
```

### 3.5. Firebird / InterBase
Em ambientes legados com `ext-interbase` (`ibase_connect`), usa o driver nativo `IbaseDriver`. No PHP 8+, onde a extensão `ibase` foi descontinuada em favor do PDO, o Lumy utiliza automaticamente o `pdo_firebird` (`PdoDriver`) e normaliza os nomes das colunas para letras minúsculas.

```php
Config::setConnection(
    connectionIdentifier: 'firebird_db',
    driver: 'firebird', // 'firebird', 'ibase' ou 'pdo_firebird'
    host: '127.0.0.1',  // ou '' para acesso direto ao caminho local
    user: 'SYSDBA',
    password: 'masterkey',
    database: '/var/lib/firebird/data/sistema.fdb'
);
```

---

## 4. Formas de Uso (Model Rápido vs. Subclasse `AbstractModel`)

O Lumy oferece duas formas complementares de trabalhar com suas tabelas:

### Forma 1: Uso Direto com `Lumynus\Lumy\Model` (Sem criar classes)
Ideal para scripts rápidos, tabelas auxiliares ou prototipação. Basta passar o nome da tabela (e opcionalmente o identificador da conexão):

```php
use Lumynus\Lumy\Model;

$users = new Model('users'); // usa a conexão 'default'

// Inserir
$id = $users->insert([
    'full_name' => 'Ana Silva',
    'email' => 'ana@example.com',
]);

// Buscar um único registro (exclusivo da classe Model)
$user = $users->get(
    filters: ['id' => $id],
    camelCaseTransform: true
);

// Buscar vários registros
$list = $users->select(
    filters: ['fullName LIKE' => 'Ana'],
    orderBy: 'id DESC',
    limit: 10,
    camelCaseTransform: true
);
```

### Forma 2: Subclasse de `Lumynus\Lumy\AbstractModel` (Recomendado para Aplicações)
Ideal para organizar regras de negócio, mapear e proteger colunas expostas (`$fields`), configurar joins (`$joins`), agregações (`$groupBy`), relacionamentos (`$relationships`) e queries customizadas (`customQuery()`):

```php
use Lumynus\Lumy\AbstractModel;

class UserModel extends AbstractModel
{
    protected string $table = 'users';

    /**
     * Mapa de campos:
     * - A chave é o nome lógico usado pela aplicação.
     * - 'db' é a coluna real (ou expressão SQL confiável) no banco.
     * - 'select' define se o campo é retornado por padrão no select().
     */
    protected array $fields = [
        'id' => ['db' => 'users.id', 'select' => true],
        'full_name' => ['db' => 'users.full_name', 'select' => true],
        'email' => ['db' => 'users.email', 'select' => true],
        'password_hash' => ['db' => 'users.password_hash', 'select' => false], // Oculto por padrão!
    ];

    public function findByEmail(string $email): ?array
    {
        return $this->getOne(
            filters: ['email' => $email],
            camelCaseTransform: true
        );
    }
}
```

---

## 5. Operações CRUD e Upsert

Todas as Models (`Model` e subclasses de `AbstractModel`) herdam os métodos de escrita de `AbstractLumy`:

### 5.1. `insert(array $data): int`
Insere um registro usando *prepared statements* e retorna o último ID gerado pelo banco (ou `0` quando a tabela/driver não possui sequência auto-incremento).

```php
$id = $userModel->insert([
    'full_name' => 'Carlos Eduardo',
    'email' => 'carlos@example.com',
]);
```

### 5.2. `upsert(array $data, array $uniqueBy): bool`
Insere um novo registro ou atualiza os campos caso ocorra conflito nas colunas únicas informadas em `$uniqueBy`. O Lumy gera automaticamente o SQL nativo de cada banco (`ON DUPLICATE KEY UPDATE`, `ON CONFLICT DO UPDATE`, `MERGE` ou `UPDATE OR INSERT`).

```php
$userModel->upsert(
    data: [
        'email' => 'carlos@example.com',
        'full_name' => 'Carlos E. Santos',
    ],
    uniqueBy: ['email']
);
```

### 5.3. `update(array $data, array|string $where, array $whereParams = []): int`
Atualiza registros que correspondam ao filtro e retorna o número de linhas afetadas. Condições vazias são rejeitadas por segurança para impedir atualização acidental da tabela inteira.

```php
// Usando array estruturado de condições
$affected = $userModel->update(
    data: ['full_name' => 'Carlos Santos'],
    where: ['id' => 10, 'status' => 'active']
);

// Usando expressão SQL customizada com placeholders (?)
$affected = $userModel->update(
    data: ['status' => 'inactive'],
    where: 'WHERE last_login < ? AND role <> ?',
    whereParams: ['2025-01-01', 'admin']
);
```

### 5.4. `delete(array|string $where, array $whereParams = []): int`
Remove registros que atendam às condições e retorna o total de linhas removidas. Condições vazias também são bloqueadas.

```php
// Usando array estruturado
$deleted = $userModel->delete(['id' => 10]);

// Usando expressão SQL parametrizada
$deleted = $userModel->delete(
    '(status = ? OR status = ?) AND verified_at IS NULL',
    ['spam', 'expired']
);
```

---

## 6. Consultas (`select`), Filtros e Operadores

O método `select()` suporta filtros ricos, ordenação, paginação multi-banco, seleção de colunas (`fielders`), conversão `camelCase` e carregamento de relacionamentos (`with`):

```php
$rows = $userModel->select(
    filters: [
        'status' => ['active', 'trial'],
        'age BETWEEN' => [18, 65],
        'fullName LIKE || email LIKE' => 'silva',
        '!deletedAt' => null,
    ],
    orderBy: 'id DESC',
    offset: 0,
    limit: 20,
    fielders: ['id', 'fullName', 'email'],
    camelCaseTransform: true,
    with: ['profile', 'orders']
);
```

### Tabela Completa de Operadores de Filtro (`select`, `update` e `delete`)

| Sintaxe no Array de Filtro | SQL Gerado | Descrição |
| :--- | :--- | :--- |
| `'age' => 18` ou `'age =' => 18` | `age = ?` | Igualdade exata |
| `'!status' => 'blocked'` ou `'status !=' => 'blocked'` | `status <> ?` / `status != ?` | Diferente de |
| `'age >' => 18`, `'age >=' => 18` | `age > ?`, `age >= ?` | Maior que / Maior ou igual |
| `'age <' => 65`, `'age <=' => 65` | `age < ?`, `age <= ?` | Menor que / Menor ou igual |
| `'name LIKE' => 'Ana'` | `name LIKE ?` (`'%Ana%'`) | Contém texto (se já possuir `%`, mantém o padrão informado) |
| `'!name LIKE' => 'Ana'` ou `'name NOT LIKE' => 'Ana'` | `name NOT LIKE ?` (`'%Ana%'`) | Não contém texto |
| `'status' => ['active', 'pending']` ou `'status IN' => [...]` | `status IN (?, ?)` | Pertence à lista de valores |
| `'!status' => ['blocked', 'banned']` ou `'status NOT IN' => [...]` | `status NOT IN (?, ?)` | Não pertence à lista |
| `'age BETWEEN' => [18, 65]` | `age BETWEEN ? AND ?` | Dentro do intervalo inclusivo |
| `'!age BETWEEN' => [18, 65]` ou `'age NOT BETWEEN' => [18, 65]` | `age NOT BETWEEN ? AND ?` | Fora do intervalo |
| `'email' => null` ou `'email IS' => null` | `email IS NULL` | Valor nulo |
| `'!email' => null` ou `'email IS NOT' => null` | `email IS NOT NULL` | Valor não nulo |
| `'email \|\| username' => 'ana'` | `(email = ? OR username = ?)` | Condição `OR` entre colunas com o mesmo valor |

---

## 7. Relacionamentos (`1:1`, `1:N` e Aninhados)

Para carregar dados relacionados sem sofrer com o problema de *N+1 queries* e sem que um `JOIN` 1:N multiplique linhas e quebre o `LIMIT` da paginação, declare `$relationships` na sua Model:

```php
class CustomerModel extends AbstractModel
{
    protected string $table = 'customers';

    protected array $fields = [
        'id' => ['db' => 'customers.id', 'select' => true],
        'customer_name' => ['db' => 'customers.customer_name', 'select' => true],
    ];

    protected array $relationships = [
        // Relação 1:1 (retorna array associativo ou null)
        'address' => [
            'model' => CustomerAddressModel::class,
            'localKey' => 'id',
            'foreignKey' => 'customer_id',
            'many' => false,
        ],
        // Relação 1:N (retorna lista de arrays)
        'orders' => [
            'model' => OrderModel::class,
            'localKey' => 'id',
            'foreignKey' => 'customer_id',
            'many' => true,
            // Opcional: permite buscar o relacionamento em outra conexão!
            // 'connectionIdentifier' => 'orders_db',
        ],
    ];
}
```

Para consultar carregando relacionamentos (inclusive sub-relacionamentos aninhados):

```php
$customers = (new CustomerModel())->select(
    limit: 10,
    camelCaseTransform: true,
    with: [
        'address',
        'orders' => ['items'], // Carrega orders e, dentro de cada order, seus items
    ]
);
```

---

## 8. Joins, Aliases e Agregações (`GROUP BY`)

Para relatórios e consultas agregadas, defina `$alias`, `$joins`, expressões em `$fields` e `$groupBy`:

```php
class SaleReportModel extends AbstractModel
{
    protected string $table = 'sales';
    protected string $alias = 's';

    protected array $fields = [
        'category_id' => ['db' => 'c.id', 'select' => true],
        'category_name' => ['db' => 'c.name', 'select' => true],
        'total_orders' => ['db' => 'COUNT(s.id)', 'select' => true],
        'total_revenue' => ['db' => 'SUM(s.amount)', 'select' => true],
    ];

    protected ?array $joins = [
        'INNER JOIN categories c ON s.category_id = c.id',
    ];

    protected array $groupBy = ['c.id', 'c.name'];
}
```

---

## 9. Transações

Todas as Models disponibilizam métodos diretos para transações no banco de dados:

### Uso Recomendado (Bloco `transaction` com Rollback Automático)
```php
$walletModel->transaction(function (WalletModel $model) use ($fromId, $toId, $amount): void {
    $model->update(['balance' => $newSenderBalance], ['id' => $fromId]);
    $model->update(['balance' => $newReceiverBalance], ['id' => $toId]);
});
```

### Controle Manual
```php
try {
    $walletModel->beginTransaction();
    $walletModel->update(['balance' => 500], ['id' => 1]);
    $walletModel->commit();
} catch (\Throwable $e) {
    $walletModel->rollBack();
    throw $e;
}
```

---

## 10. Múltiplas Conexões e Gerenciamento de Pool

O `LumyConnection` mantém um pool de conexões reutilizáveis em memória durante o ciclo de vida do processo PHP, indexado pelas credenciais e pelo `connectionIdentifier`.

```php
use Lumynus\Lumy\Connections\LumyConnection;

// Verificar tamanho do pool e conexões ativas
$activeCount = LumyConnection::poolSize();
$identifiers = LumyConnection::identifiers();

// Fechar uma conexão específica pelo identificador
LumyConnection::closeByIdentifier('audit');

// Fechar todas as conexões do pool
LumyConnection::closeAll();
```

---

## 11. Migrations SQL (CLI e Programático)

O Lumy inclui um runner de migrations SQL que executa arquivos `.sql` em ordem natural e registra as migrations aplicadas na tabela `lumy_migrations`.

### Via CLI (`bin/migrate.php`)
```bash
php bin/migrate.php <connectionIdentifier> [bootstrap.php] [migrations-directory]
```
Exemplo:
```bash
php bin/migrate.php default bootstrap.php database/migrations
```

### Via Código PHP
```php
use Lumynus\Lumy\Migrations;

$runner = new Migrations(
    connectionIdentifier: 'default',
    migrationsDirectory: __DIR__ . '/database/migrations'
);
$appliedFiles = $runner->run();
```

Recursos suportados pelo parser de migrations do Lumy:
- Múltiplos statements separados por `;` no mesmo arquivo `.sql`;
- Comentários de linha (`--` e `#`) e blocos (`/* ... */`);
- Strings com escape, identificadores entre crases, aspas ou colchetes e blocos dollar-quoted do PostgreSQL (`$$ ... $$` ou `$tag$ ... $tag$`);
- Transação automática por arquivo em bancos que suportam DDL transacional (SQLite, PostgreSQL, SQL Server, Firebird).

---

## 12. Segurança e Boas Práticas

O Lumy foi projetado com camadas rigorosas de segurança:
1. **Prepared Statements em 100% dos Valores:** Todos os dados de `insert()`, `upsert()`, `update()`, `delete()` e `select()` são enviados via *parameter binding* tipado (`PDO::PARAM_INT`, `PDO::PARAM_BOOL`, `PDO::PARAM_NULL`, `PDO::PARAM_STR` e inferência de tipos `i`/`d`/`s` no MySQLi).
2. **Validação Estrita de Identificadores SQL:** Nomes de tabelas, colunas e aliases passam por validação de expressão regular (`/\A[A-Za-z_][A-Za-z0-9_]*\z/`), bloqueando injeção de SQL via chaves de arrays.
3. **Proteção de Whitelist no Mapa `$fields`:** Quando uma `AbstractModel` define `$fields`, qualquer tentativa de filtrar ou ordenar por uma coluna não mapeada (mesmo que qualificada como `tabela.coluna_secreta`) é bloqueada com `InvalidArgumentException`.
4. **Proteção de Credenciais em Stack Traces (`#[\SensitiveParameter]`):** Todos os parâmetros de senha em `Config`, `LumyConnection` e nos drivers utilizam o atributo nativo `#[\SensitiveParameter]`, evitando que senhas vazem em logs de erro ou stack traces.
5. **Bloqueio de `UPDATE` e `DELETE` sem `WHERE`:** Condições vazias são rejeitadas por padrão para evitar perda acidental de dados.

---

## 13. Exemplos Práticos e Testes

Explore a pasta [`examples/`](examples/README.md) para ver scripts prontos e funcionais para cada recurso:

```bash
php examples/basic-usage.php
php examples/named-connections.php
php examples/crud-and-upsert.php
php examples/filters-and-operators.php
php examples/relationships.php
php examples/joins-and-aggregations.php
php examples/transactions.php
php examples/migrations-usage.php
php examples/all-databases-config.php
```

Para executar a suíte de testes automatizados:

```bash
php tests/example1.php
```

Consulte também a documentação detalhada em:
- [Documentação de Models (`docs/Model.md`)](docs/Model.md)
- [Documentação de Migrations (`docs/Migrations.md`)](docs/Migrations.md)


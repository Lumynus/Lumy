# Guia Completo dos Bancos de Dados Suportados

O **Lumy MicroORM** oferece suporte nativo para **5 famílias de bancos de dados relacionais**, abstraindo as diferenças de dialeto SQL para paginação, `upsert`, `LIKE ESCAPE`, transações com savepoints e execução de migrations.

---

## 1. Matriz de Compatibilidade e Dialetos

| Família | Valores aceitos em `driver` | Extensão PHP | Paginação (`select` / `get`) | Sintaxe de `upsert()` | Transações Aninhadas | Lock de Migrations |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **SQLite** | `'sqlite'` | `ext-pdo_sqlite` (`PdoDriver`) | `LIMIT ? OFFSET ?` | `INSERT ... ON CONFLICT (...) DO UPDATE SET ...` | `SAVEPOINT lumy_sp_N` | Serialização de arquivo + PK |
| **MySQL / MariaDB** | `'mysql'`, `'mariadb'`, `'pdo_mysql'` | `ext-mysqli` (`MysqlDriver`) ou `ext-pdo_mysql` (`PdoDriver`) | `LIMIT ? OFFSET ?` | `INSERT ... ON DUPLICATE KEY UPDATE ... = VALUES(...)` | `SAVEPOINT lumy_sp_N` | `GET_LOCK(name, 60)` |
| **PostgreSQL** | `'postgresql'`, `'pgsql'`, `'postgres'` | `ext-pdo_pgsql` (`PdoDriver`) | `LIMIT ? OFFSET ?` | `INSERT ... ON CONFLICT (...) DO UPDATE SET ...` | `SAVEPOINT lumy_sp_N` | `pg_try_advisory_lock(hashtext(?))` |
| **SQL Server** | `'sqlserver'`, `'sqlsrv'`, `'mssql'` | `ext-pdo_sqlsrv` (`PdoDriver`) | `ORDER BY ... OFFSET ? ROWS FETCH NEXT ? ROWS ONLY` | `MERGE INTO ... WITH (HOLDLOCK) USING ...` | `SAVE TRANSACTION lumy_sp_N` | `sp_getapplock` |
| **Firebird / InterBase** | `'firebird'`, `'ibase'`, `'pdo_firebird'` | `ext-interbase` (`IbaseDriver`) ou `ext-pdo_firebird` (`PdoDriver`) | `ROWS ? TO ?` (base 1) | `UPDATE OR INSERT INTO ... VALUES (...) MATCHING (...)` | `SAVEPOINT lumy_sp_N` | Transação + PK |

---

## 2. Como Configurar e Usar Cada Banco de Dados

Você pode registrar conexões instanciando `new Config(...)` ou chamando `Config::setConnection(...)`. As duas formas gravam a configuração no mesmo registro estático em memória.

### 2.1. SQLite (`sqlite`)

Ideal para testes automatizados (`:memory:`), aplicações embarcadas, CLIs e microsserviços locais.

```php
use Lumynus\Lumy\Config;
use Lumynus\Lumy\Model;

// Opção A: Banco em memória
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'sqlite',
    database: ':memory:'
);

// Opção B: Banco em arquivo físico
Config::setConnection(
    connectionIdentifier: 'local_file',
    driver: 'sqlite',
    database: __DIR__ . '/database/app.sqlite'
);

$users = new Model('users'); // usa 'default'
```

### 2.2. MySQL e MariaDB (`mysql`, `mariadb`, `pdo_mysql`)

- Com `'mysql'` ou `'mariadb'`, o Lumy usa `ext-mysqli` (`MysqlDriver`) se estiver carregada, ou faz fallback automático para `ext-pdo_mysql` (`PdoDriver`).
- Com `'pdo_mysql'`, força o uso do `PdoDriver` mesmo que `mysqli` esteja habilitada.
- Configura `utf8mb4` automaticamente e aceita porta customizada no `host` (`host:porta`).

```php
use Lumynus\Lumy\Config;

// Usando driver padrão (mysqli com fallback para pdo_mysql)
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'mysql', // ou 'mariadb'
    host: '127.0.0.1:3306',
    user: 'app_user',
    password: 'secret',
    database: 'lumy_app'
);

// Forçando PDO MySQL explicitamente
Config::setConnection(
    connectionIdentifier: 'reporting',
    driver: 'pdo_mysql',
    host: '127.0.0.1:3306',
    user: 'report_user',
    password: 'secret',
    database: 'lumy_app'
);
```

### 2.3. PostgreSQL (`postgresql`, `pgsql`, `postgres`)

Utiliza `ext-pdo_pgsql`. Aceita qualquer um dos três aliases (`'postgresql'`, `'pgsql'` ou `'postgres'`), porta customizada no `host` (`127.0.0.1:5432`) e configuração de `sslmode` em `options`.

```php
use Lumynus\Lumy\Config;

Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'postgresql', // ou 'pgsql' / 'postgres'
    host: '127.0.0.1:5432',
    user: 'postgres',
    password: 'secret',
    database: 'lumy_app',
    options: [
        'sslmode' => 'prefer', // disable | allow | prefer | require | verify-ca | verify-full
    ]
);
```

### 2.4. Microsoft SQL Server (`sqlserver`, `sqlsrv`, `mssql`)

Utiliza `ext-pdo_sqlsrv`. Aceita os aliases `'sqlserver'`, `'sqlsrv'` ou `'mssql'`.
- **Portas**: se você passar `host: '127.0.0.1:1433'`, o Lumy converte automaticamente para o formato `sqlsrv:Server=127.0.0.1,1433;Database=...` exigido pela Microsoft.
- **Paginação**: o SQL Server exige cláusula `ORDER BY` quando usa `OFFSET ... ROWS FETCH NEXT ... ROWS ONLY`. Caso você chame `select(limit: 10, offset: 0)` sem informar `orderBy`, o Lumy injeta automaticamente uma ordenação estável (`id ASC`, a primeira coluna do mapa `$fields` ou `ORDER BY (SELECT NULL)`).

```php
use Lumynus\Lumy\Config;

Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'sqlserver', // ou 'sqlsrv' / 'mssql'
    host: '127.0.0.1:1433',
    user: 'sa',
    password: 'StrongPassword!123',
    database: 'lumy_erp'
);
```

### 2.5. Firebird / InterBase (`firebird`, `ibase`, `pdo_firebird`)

- Com `'firebird'` ou `'ibase'`, utiliza `IbaseDriver` caso a extensão `interbase` (`ibase_connect`) esteja disponível; caso contrário (padrão no PHP 8+), utiliza automaticamente `ext-pdo_firebird` (`PdoDriver`).
- Com `'pdo_firebird'`, utiliza diretamente o `PdoDriver`.
- **Portas customizadas**: se você informar `host: '127.0.0.1:3050'` e `database: 'C:/db/sistema.fdb'`, o Lumy formata automaticamente o alvo como `127.0.0.1/3050:C:/db/sistema.fdb` (padrão oficial do Firebird).
- **Normalização de colunas**: como o Firebird retorna identificadores não-quotados em MAIÚSCULAS por padrão, o Lumy aplica `PDO::ATTR_CASE => PDO::CASE_LOWER` (no `PdoDriver`) e ` array_change_key_case(..., CASE_LOWER)` (no `IbaseDriver`), mantendo suas Models e o `camelCaseTransform` 100% compatíveis com os demais bancos.

```php
use Lumynus\Lumy\Config;

Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'firebird', // ou 'ibase' / 'pdo_firebird'
    host: '127.0.0.1:3050', // ou '' para arquivo local sem servidor TCP
    user: 'SYSDBA',
    password: 'masterkey',
    database: 'C:/databases/sistema.fdb'
);
```

---

## 3. Trabalhando com Múltiplos Bancos Simultaneamente

Você pode registrar quantas conexões quiser em `Config` (inclusive de bancos de dados diferentes) e usá-las na mesma requisição:

```php
use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;
use Lumynus\Lumy\Model;

// Conexão principal em PostgreSQL
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'postgresql',
    host: '127.0.0.1:5432',
    user: 'postgres',
    password: 'secret',
    database: 'core_db'
);

// Conexão secundária de auditoria em SQLite
Config::setConnection(
    connectionIdentifier: 'audit',
    driver: 'sqlite',
    database: __DIR__ . '/storage/audit.sqlite'
);

// 1. Usando Model rápida em cada conexão:
$usersModel = new Model('users', 'default');
$auditModel = new Model('audit_logs', 'audit');

// 2. Fixando a conexão em uma subclasse de AbstractModel:
class AuditLogModel extends AbstractModel
{
    protected string $table = 'audit_logs';

    public function __construct()
    {
        parent::__construct('audit');
    }
}

// 3. Executando transação em uma conexão específica:
transaction(
    transaction: function () use ($auditModel): void {
        $auditModel->insert(['event' => 'USER_LOGIN', 'created_at' => date('Y-m-d H:i:s')]);
    },
    rollback: function (\Throwable $e): void {
        error_log('Falha ao gravar auditoria: ' . $e->getMessage());
    },
    connectionIdentifier: 'audit'
);
```

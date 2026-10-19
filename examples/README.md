# Exemplos Práticos — Lumy MicroORM

Esta pasta reúne exemplos executáveis e independentes que demonstram todos os recursos do **Lumy MicroORM**.

Por padrão, os exemplos executáveis utilizam **SQLite em memória (`:memory:`)**, criando suas próprias tabelas e dados temporários para que você possa testá-los imediatamente sem precisar subir servidores externos.

## Pré-requisitos

Certifique-se de que a extensão `pdo_sqlite` esteja habilitada no seu `php.ini` (ou passe `-d extension=pdo_sqlite` na linha de comando).

## Como Executar

A partir da raiz do projeto, execute qualquer um dos scripts abaixo:

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

> **Dica (Windows / CLI sem `pdo_sqlite` ativo no `php.ini`):**
> ```powershell
> php -d extension=pdo_sqlite examples/relationships.php
> ```

---

## Catálogo de Exemplos

### 1. Uso Básico (`basic-usage.php`)
Demonstra o fluxo essencial do Lumy:
- Registro de conexão padrão (`default`) via `Config`;
- Criação de uma classe que estende `AbstractModel`;
- Inserção com `insert()` e listagem paginada com `select()`;
- Uso rápido sem criar classe dedicada por meio de `new Model('tbl')` e `get()`.

### 2. Conexões Nomeadas / Múltiplos Bancos (`named-connections.php`)
Demonstra como trabalhar com múltiplos bancos de dados simultaneamente no mesmo processo PHP:
- Registro de conexões independentes (`default` e `audit`) com `Config::setConnection()`;
- Seleção da conexão desejada no construtor da Model (`parent::__construct('audit')`).

### 3. CRUD Completo e Upsert (`crud-and-upsert.php`)
Demonstra todas as operações de escrita e leitura:
- `insert()`: insere registros e retorna o ID gerado;
- `upsert()`: insere ou atualiza automaticamente em caso de conflito em colunas únicas (`ON CONFLICT`, `ON DUPLICATE KEY UPDATE`, `MERGE` ou `UPDATE OR INSERT`);
- `update()`: atualização usando array associativo de condições ou expressão `WHERE` parametrizada;
- `delete()`: exclusão segura com retorno de linhas afetadas;
- `Model::get()`: busca rápida de um único registro.

### 4. Filtros Avançados e Operadores (`filters-and-operators.php`)
Demonstra o construtor de filtros seguros do `select()`, `update()` e `delete()`:
- Comparações: `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`;
- Intervalos: `BETWEEN` e `NOT BETWEEN` (ou `!campo BETWEEN`);
- Listas: `IN` e `NOT IN` (passando array simples ou prefixando com `!`);
- Buscas textuais: `LIKE` e `NOT LIKE` (ou `!campo LIKE`);
- Valores nulos: `IS NULL` (`'campo' => null`) e `IS NOT NULL` (`'!campo' => null`);
- Agrupamento lógico `OR` entre colunas com o operador `||` (ex.: `'fullName LIKE || roleName LIKE' => 'Lead'`);
- Transformação automática `camelCaseTransform: true`.

### 5. Relacionamentos 1:1, 1:N e Aninhados (`relationships.php`)
Demonstra o carregamento em lote (*eager loading* sem problema de N+1 e sem quebrar a paginação):
- Relação `1:1` (`many: false`) entre cliente e endereço;
- Relação `1:N` (`many: true`) entre cliente e pedidos;
- Relação aninhada (`with: ['address', 'orders' => ['items']]`) carregando os itens de cada pedido;
- Coexistência de múltiplos relacionamentos compartilhando a mesma `localKey`.

### 6. Joins e Agregações SQL (`joins-and-aggregations.php`)
Demonstra como estruturar consultas analíticas e relatórios:
- Uso de `$alias` para a tabela principal;
- Declaração de `$joins` (`INNER JOIN`, `LEFT JOIN`, etc.);
- Mapeamento de funções agregadas (`COUNT`, `SUM`) em `$fields`;
- Agrupamento com `$groupBy`.

### 7. Transações (`transactions.php`)
Demonstra o controle transacional seguro:
- Execução atômica via `$model->transaction(function ($model) { ... })` com `commit` automático em caso de sucesso e `rollBack` automático se qualquer exceção for lançada;
- Também aplicável manualmente via `$model->beginTransaction()`, `$model->commit()` e `$model->rollBack()`.

### 8. Runner de Migrations SQL (`migrations-usage.php`)
Demonstra o uso da classe `Migrations` para versionamento de schema:
- Execução ordenada de arquivos `.sql` com múltiplos statements e comentários;
- Criação e verificação automática da tabela de histórico `lumy_migrations`;
- Garantia de idempotência (arquivos já aplicados são ignorados em execuções futuras).

### 9. Configuração de Todos os Bancos Suportados (`all-databases-config.php`)
Mostra lado a lado como configurar conexões para todos os bancos de dados suportados pelo Lumy:
- **SQLite** (`sqlite` — em memória `:memory:` ou arquivo `.sqlite`);
- **MySQL / MariaDB** (`mysql`, `mariadb`, `pdo_mysql`);
- **PostgreSQL** (`postgresql`, `pgsql`, `postgres`);
- **Microsoft SQL Server** (`sqlserver`, `sqlsrv`, `mssql`);
- **Firebird / InterBase** (`firebird`, `ibase`, `pdo_firebird`).

---

## Como Configurar Cada Tipo de Banco na Prática

### SQLite
```php
use Lumynus\Lumy\Config;

// Em memória (ideal para testes e scripts rápidos)
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'sqlite',
    database: ':memory:'
);

// Em arquivo físico
Config::setConnection(
    connectionIdentifier: 'sqlite_file',
    driver: 'sqlite',
    database: __DIR__ . '/database/app.sqlite'
);
```

### MySQL / MariaDB
Suporta tanto a extensão nativa `mysqli` (padrão para `mysql` e `mariadb`) quanto `pdo_mysql` (usado automaticamente como fallback se `mysqli` não estiver ativa, ou explicitamente com `driver: 'pdo_mysql'`). Aceita porta customizada diretamente no `host`.
```php
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'mysql', // ou 'mariadb' ou 'pdo_mysql'
    host: '127.0.0.1:3306',
    user: 'root',
    password: 'secret',
    database: 'minha_base'
);
```

### PostgreSQL
Utiliza a extensão `pdo_pgsql`. Aceita os aliases `postgresql`, `pgsql` ou `postgres` e porta customizada no `host`.
```php
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'postgresql', // ou 'pgsql' / 'postgres'
    host: '127.0.0.1:5432',
    user: 'postgres',
    password: 'secret',
    database: 'minha_base'
);
```

### Microsoft SQL Server
Utiliza a extensão `pdo_sqlsrv`. Aceita os aliases `sqlserver`, `sqlsrv` ou `mssql` e converte portas no formato `host:1433` automaticamente para o padrão `Server=host,1433` do driver.
```php
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'sqlserver', // ou 'sqlsrv' / 'mssql'
    host: '127.0.0.1:1433',
    user: 'sa',
    password: 'StrongPassword!123',
    database: 'minha_base'
);
```

### Firebird / InterBase
Usa a extensão `ibase` se instalada ou faz fallback automático para `pdo_firebird` (nativo no PHP 8+). Você também pode forçar `pdo_firebird` como `driver`.
```php
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'firebird', // ou 'ibase' / 'pdo_firebird'
    host: '127.0.0.1', // deixe '' para arquivo local sem host
    user: 'SYSDBA',
    password: 'masterkey',
    database: 'C:/databases/sistema.fdb'
);
```
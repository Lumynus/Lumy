<div align="center">

# Lumy

### **O MicroORM & Data Mapper Multi-Dialeto do Ecossistema Lumynus**

*Simples no design. Veloz por padrão. Seguro em cada query.*  
**Tradicional no que importa. Visionário no que acelera.**

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.2-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Databases](https://img.shields.io/badge/Databases-MySQL%20%7C%20PostgreSQL%20%7C%20SQLite%20%7C%20SQL%20Server%20%7C%20Firebird-2563eb?style=for-the-badge)](https://lumy.lumynus.dev/)
[![Ecosystem](https://img.shields.io/badge/Ecossistema-lumynus.dev-0f172a?style=for-the-badge)](https://lumynus.dev/)
[![Docs](https://img.shields.io/badge/Docs-lumy.lumynus.dev-10b981?style=for-the-badge)](https://lumy.lumynus.dev/)
[![License](https://img.shields.io/badge/License-MIT-f59e0b?style=for-the-badge)](https://lumynus.dev/)

<p align="center">
  <a href="README.md"><strong>Read in English (README.md)</strong></a> •
  <a href="https://lumy.lumynus.dev/"><strong>Documentação Oficial (lumy.lumynus.dev)</strong></a> •
  <a href="https://lumynus.dev/"><strong>Site Principal (lumynus.dev)</strong></a> •
  <a href="#4-todas-as-formas-de-usar"><strong>Guia Rápido</strong></a> •
  <a href="#9-transações-todas-as-formas-de-uso"><strong>Transações</strong></a> •
  <a href="examples/README.md"><strong>Exemplos Executáveis</strong></a>
</p>

</div>

---

## O que é o Lumy? (MicroORM, Query Builder ou Data Gateway?)

O **Lumy** é um **MicroORM Híbrido orientado a dados (*Array-First Data Mapper & Table Data Gateway*)** com **tradutor nativo de dialetos SQL** e **runner de migrations integrado**.

Na prática, ele ocupa o ponto de equilíbrio ideal entre o **PDO puro** (que exige SQL repetitivo e manual para cada banco) e os **ORMs pesados tradicionais** (como Eloquent ou Doctrine, que hidratam centenas de objetos pesados em memória, usam reflexão/mágica excessiva e sofrem com *N+1 queries*).

### Por que essa arquitetura faz diferença?

1. **Mais que um Query Builder, mais leve que um ORM clássico:**
   - Como um **ORM**, o Lumy possui mapeamento declarativo de colunas (`$fields`), ocultação automática de campos sensíveis (`'select' => false`), relacionamentos `1:1`, `1:N` e aninhados (`$relationships`), transações com savepoints e `upsert` automático.
   - Diferente de um ORM pesado (Active Record), o Lumy é **Array-First (Zero Hydration Overhead)**: ele retorna arrays associativos puros (com conversão automática opcional `snake_case ↔ camelCase`), prontos para serialização JSON em APIs ou renderização em Views, consumindo uma fração mínima de memória e CPU.

2. **Arquitetura Dual-Mode (Você escolhe como usar):**
   - **Modo Instantâneo (`new Model('tabela')`)**: Acesse qualquer tabela imediatamente sem precisar criar uma classe dedicada — ideal para scripts, tabelas pivô/auxiliares, microsserviços e prototipação.
   - **Modo Estruturado (`extends AbstractModel`)**: Crie Models de domínio com proteção de *whitelist* de colunas, *joins*, agregações (`GROUP BY` / `HAVING`), relacionamentos em lote e métodos de negócio.

3. **Código Único, 5 Bancos de Dados Relacionais:**
   - O Lumy traduz automaticamente paginação (`LIMIT/OFFSET`, `OFFSET ... ROWS FETCH NEXT`, `ROWS m TO n`), `upsert()` (`ON DUPLICATE KEY UPDATE`, `ON CONFLICT DO UPDATE`, `MERGE`, `UPDATE OR INSERT`), `LIKE ESCAPE` e `SAVEPOINT` para **MySQL/MariaDB**, **PostgreSQL**, **SQLite**, **SQL Server** e **Firebird/InterBase**.

### Comparativo Arquitetural

| Característica | PDO / Driver Puro | **Lumy MicroORM** | ORMs Tradicionais Pesados |
| :--- | :---: | :---: | :---: |
| **Consumo de Memória / CPU** | Muito Baixo | **Muito Baixo (Array-First)** | Alto (Hidratação de Objetos/Proxies) |
| **Curva de Aprendizado** | Baixa (SQL manual) | **Imediata (API limpa e explícita)** | Alta (Mágicas e convenções ocultas) |
| **Uso sem criar classe de Model** | Manual | **Sim (`new Model('tabela')`)** | Não (Exige classes/entidades) |
| **Proteção de Campos Ocultos** | Manual | **Nativa (`'select' => false`)** | Via propriedades adicionais |
| **Paginação Multi-Banco Automática (`data` / `meta`)** | Não | **Sim (5 dialetos SQL)** | Sim |
| **Upsert Nativo Multi-Banco** | Não | **Sim (5 dialetos SQL)** | Parcial |
| **Relacionamentos sem N+1 e sem quebrar `LIMIT`** | Manual | **Sim (Batch Loading `IN (...)`)** | Fácil cair em N+1 involuntário |
| **Função Global `transaction()` com Rollback Customizado** | Não | **Sim (`transaction($tx, $rollback)`)** | Não possui callback de rollback dedicado |
| **Runner de Migrations `.sql` com Lock** | Não | **Integrado (`bin/migrate.php`)** | Geralmente exige pacotes extras |

---

## Principais Vantagens do Lumy

- **Performance Previsível:** Sem *lazy-loading* oculto disparando dezenas de queries escondidas. Relacionamentos são carregados apenas quando solicitados em `with` e sempre em lote (*1 query por relação*).
- **Segurança por Padrão (*Secure by Design*):**
  - 100% dos valores em `insert`, `upsert`, `update`, `delete` e `select` usam *prepared statements* tipados;
  - Identificadores SQL validados por regex estrita contra SQL Injection;
  - *Whitelist* automática quando `$fields` é declarado (impede filtragem ou ordenação por colunas não autorizadas);
  - Bloqueio nativo de `UPDATE` e `DELETE` sem cláusula `WHERE`;
  - Senhas protegidas com `#[\SensitiveParameter]` (nunca vazam em logs ou *stack traces*);
  - Sanitização de DSN e escape automático de curingas (`%`, `_`, `\`) em buscas `LIKE`.
- **Transações Poderosas e Flexíveis:** Função global `transaction($transaction, $rollback)` que faz rollback automático por padrão ou executa a sua rotina customizada de rollback/compensação, com suporte a transações aninhadas (`SAVEPOINT`).
- **Pool Inteligente e Multi-Conexão:** Trabalhe com múltiplos bancos ao mesmo tempo (ex.: PostgreSQL como banco principal + SQLite/MySQL para auditoria ou ERP legado) — inclusive relacionando Models de conexões diferentes!

---

## Sumário

1. [Bancos de Dados Suportados](#1-bancos-de-dados-suportados)
2. [Instalação](#2-instalação)
3. [Como Configurar Cada Banco de Dados](#3-como-configurar-cada-banco-de-dados)
4. [Todas as Formas de Usar (Instantâneo, Estruturado e Custom SQL)](#4-todas-as-formas-de-usar)
5. [Operações CRUD e Upsert Multi-Dialeto](#5-operações-crud-e-upsert-multi-dialeto)
6. [Consultas, Paginação Padronizada (`data` / `meta`) e Operadores de Filtro](#6-consultas-paginação-padronizada-data--meta-e-operadores-de-filtro)
7. [Relacionamentos (`1:1`, `1:N`, Aninhados e Multi-Conexão)](#7-relacionamentos-11-1n-aninhados-e-multi-conexão)
8. [Joins, Aliases e Agregações (`GROUP BY` / `HAVING`)](#8-joins-aliases-e-agregações-group-by--having)
9. [Transações (Todas as Formas de Uso)](#9-transações-todas-as-formas-de-uso)
10. [Múltiplas Conexões e Gerenciamento de Pool](#10-múltiplas-conexões-e-gerenciamento-de-pool)
11. [Migrations SQL (Todas as Formas de Executar)](#11-migrations-sql-todas-as-formas-de-executar)
12. [Segurança e Boas Práticas](#12-segurança-e-boas-práticas)
13. [Exemplos Executáveis e Links Oficiais](#13-exemplos-executáveis-e-links-oficiais)

---

## 1. Bancos de Dados Suportados

O Lumy abstrai nativamente **5 famílias de bancos de dados relacionais**:

| Banco de Dados | Valores aceitos em `driver` | Extensão PHP utilizada | Sintaxe de Paginação | Sintaxe de `upsert()` | Savepoints |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **MySQL / MariaDB** | `'mysql'`, `'mariadb'`, `'pdo_mysql'` | `ext-mysqli` (padrão) ou `ext-pdo_mysql` (fallback/explícito) | `LIMIT ? OFFSET ?` | `INSERT ... ON DUPLICATE KEY UPDATE` | `SAVEPOINT lumy_sp_N` |
| **PostgreSQL** | `'postgresql'`, `'pgsql'`, `'postgres'` | `ext-pdo_pgsql` | `LIMIT ? OFFSET ?` | `INSERT ... ON CONFLICT (...) DO UPDATE` | `SAVEPOINT lumy_sp_N` |
| **SQLite** | `'sqlite'` | `ext-pdo_sqlite` | `LIMIT ? OFFSET ?` | `INSERT ... ON CONFLICT (...) DO UPDATE` | `SAVEPOINT lumy_sp_N` |
| **SQL Server** | `'sqlserver'`, `'sqlsrv'`, `'mssql'` | `ext-pdo_sqlsrv` | `OFFSET ? ROWS FETCH NEXT ? ROWS ONLY` | `MERGE INTO ... WITH (HOLDLOCK)` | `SAVE TRANSACTION lumy_sp_N` |
| **Firebird / InterBase** | `'firebird'`, `'ibase'`, `'pdo_firebird'` | `ext-interbase` (`ibase`) ou `ext-pdo_firebird` (fallback automático no PHP 8+) | `ROWS ? TO ?` (base 1) | `UPDATE OR INSERT INTO ... MATCHING (...)` | `SAVEPOINT lumy_sp_N` |

---

## 2. Instalação

Requer **PHP 8.2 ou superior** (totalmente compatível com PHP 8.2, 8.3, 8.4 e 8.5).

```bash
composer require lumynus/lumy
```

---

## 3. Como Configurar Cada Banco de Dados

As conexões são registradas em memória por meio de `new Config(...)` ou `Config::setConnection(...)`. O Lumy trabalha com *lazy connection*: a conexão real com o banco só é aberta quando a primeira query é executada.

### 3.1. MySQL e MariaDB (`mysql`, `mariadb`, `pdo_mysql`)
Usa `mysqli` por padrão com `utf8mb4` automático. Se `mysqli` não estiver instalada ou se você usar `'pdo_mysql'`, utiliza `PDO` automaticamente. Aceita porta customizada no `host` (`host:porta`).

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

### 3.2. PostgreSQL (`postgresql`, `pgsql`, `postgres`)
Usa `pdo_pgsql`. Suporta porta customizada no `host` (`127.0.0.1:5432`) e `sslmode` em `options`.

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

### 3.3. SQLite (`sqlite` — Em Memória ou Arquivo)
Usa `pdo_sqlite`. Não exige `host`, `user` ou `password`.

```php
// Em memória (excelente para testes automatizados e prototipação)
Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'sqlite',
    database: ':memory:'
);

// Em arquivo físico
Config::setConnection(
    connectionIdentifier: 'local_file',
    driver: 'sqlite',
    database: __DIR__ . '/storage/database.sqlite'
);
```

### 3.4. Microsoft SQL Server (`sqlserver`, `sqlsrv`, `mssql`)
Usa `pdo_sqlsrv`. Portas informadas como `127.0.0.1:1433` são convertidas automaticamente para `Server=127.0.0.1,1433`.

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

### 3.5. Firebird / InterBase (`firebird`, `ibase`, `pdo_firebird`)
Usa `ibase` quando instalado ou faz fallback automático para `pdo_firebird` no PHP 8+, normalizando automaticamente os nomes das colunas para letras minúsculas e portas `host:3050` para `host/3050:database`.

```php
Config::setConnection(
    connectionIdentifier: 'firebird_db',
    driver: 'firebird', // 'firebird', 'ibase' ou 'pdo_firebird'
    host: '127.0.0.1:3050', // ou '' para arquivo local direto
    user: 'SYSDBA',
    password: 'masterkey',
    database: '/var/lib/firebird/data/sistema.fdb'
);
```

### 3.6. Opções Avançadas (TLS/SSL e Atributos PDO)
O argumento `options` permite configurar SSL e atributos específicos do driver:
- **PDO (`PdoDriver`)**: aceita constantes inteiras `PDO::ATTR_*` e, no PostgreSQL, `'sslmode'` (`disable`, `allow`, `prefer`, `require`, `verify-ca`, `verify-full`).
- **MySQLi (`MysqlDriver`)**: aceita `'ssl' => ['ca' => ..., 'cert' => ..., 'key' => ...]`.

```php
Config::setConnection(
    connectionIdentifier: 'pg_secure',
    driver: 'postgresql',
    host: 'db.lumynus.dev:5432',
    user: 'app_user',
    password: 'secret_password',
    database: 'production_db',
    options: ['sslmode' => 'require']
);
```

---

## 4. Todas as Formas de Usar

O Lumy oferece **3 formas complementares** de interagir com o banco de dados:

### Forma 1: Uso Instantâneo com `Model` (Sem criar classes)
Perfeito para operações rápidas, scripts, tabelas auxiliares ou quando você não quer criar uma classe para cada tabela:

```php
use Lumynus\Lumy\Model;

$users = new Model('users'); // usa a conexão 'default'
// Para outra conexão: $audit = new Model('audit_logs', 'audit');

// 1. Inserir registro
$id = $users->insert([
    'full_name' => 'Ana Silva',
    'email'     => 'ana@lumynus.dev',
    'status'    => 'active',
]);

// 2. Buscar um único registro (retorna array associativo ou null)
$user = $users->get(
    filters: ['id' => $id],
    camelCaseTransform: true
);
// Resultado: ['id' => 1, 'fullName' => 'Ana Silva', 'email' => 'ana@lumynus.dev', 'status' => 'active']

// 3. Buscar múltiplos registros com filtro e paginação (retorna 'data' e 'meta')
$result = $users->select(
    filters: ['status' => 'active'],
    orderBy: 'id DESC',
    page: 1,
    perPage: 10,
    camelCaseTransform: true
);
// $result['data'] -> registros da página
// $result['meta'] -> ['currentPage' => 1, 'previousPage' => 1, 'perPage' => 10, 'nextPage' => 1, 'totalRecords' => 1, 'totalPages' => 1]
```

### Forma 2: Subclasse de `AbstractModel` (Recomendado para Domínio e APIs)
Permite mapear colunas (`$fields`), ocultar colunas sensíveis por padrão (`'select' => false`), definir relacionamentos (`$relationships`), joins (`$joins`) e criar métodos de negócio:

```php
use Lumynus\Lumy\AbstractModel;

class UserModel extends AbstractModel
{
    protected string $table = 'users';

    /**
     * Mapa de campos (Whitelist):
     * - A chave é o nome lógico na aplicação (em snake_case).
     * - 'db' é a coluna real (ou expressão SQL) no banco.
     * - 'select' define se a coluna é retornada automaticamente no select().
     */
    protected array $fields = [
        'id'            => ['db' => 'users.id', 'select' => true],
        'full_name'     => ['db' => 'users.full_name', 'select' => true],
        'email'         => ['db' => 'users.email', 'select' => true],
        'status'        => ['db' => 'users.status', 'select' => true],
        'password_hash' => ['db' => 'users.password_hash', 'select' => false], // Oculto por padrão!
    ];

    public function findActiveByEmail(string $email): ?array
    {
        return $this->getOne(
            filters: [
                'email'  => $email,
                'status' => 'active',
            ],
            camelCaseTransform: true
        );
    }
}
```

### Forma 3: Queries SQL Customizadas Parametrizadas (`customQuery`)
Dentro de qualquer subclasse de `AbstractModel`, você tem acesso ao método protegido `customQuery(string $query, array $params = [], string|array $types = '')` para executar SQL arbitrário com *prepared statements*:

```php
class ReportModel extends AbstractModel
{
    protected string $table = 'orders';

    public function revenueByPeriod(string $startDate, string $endDate): array
    {
        $result = $this->customQuery(
            'SELECT status, COUNT(id) AS total_orders, SUM(amount) AS revenue
             FROM orders
             WHERE created_at BETWEEN ? AND ?
             GROUP BY status',
            [$startDate, $endDate]
        );

        return is_array($result) ? $result : [];
    }
}
```

---

## 5. Operações CRUD e Upsert Multi-Dialeto

Todas as Models (`Model` e subclasses de `AbstractModel`) possuem os métodos abaixo:

### 5.1. `insert(array $data): int`
Insere um registro via *prepared statement* e retorna o ID gerado pelo banco (Aceita também objetos `DateTimeInterface`, formatados automaticamente como `Y-m-d H:i:s`).

```php
$id = $userModel->insert([
    'full_name'  => 'Carlos Eduardo',
    'email'      => 'carlos@lumynus.dev',
    'created_at' => new \DateTimeImmutable(),
]);
```

### 5.2. `upsert(array $data, array $uniqueBy): bool`
Insere um novo registro ou atualiza automaticamente caso já exista um registro com a chave única informada em `$uniqueBy`. O Lumy escreve o SQL nativo para cada banco (`ON DUPLICATE KEY UPDATE`, `ON CONFLICT DO UPDATE`, `MERGE` ou `UPDATE OR INSERT`):

```php
$userModel->upsert(
    data: [
        'email'     => 'carlos@lumynus.dev',
        'full_name' => 'Carlos E. Santos',
        'status'    => 'active',
    ],
    uniqueBy: ['email']
);
```

### 5.3. `update(array $data, array|string $where, array $whereParams = []): int`
Atualiza os registros que atendem ao filtro e retorna o número de linhas afetadas. Condições vazias lançam exceção para proteger a tabela contra atualizações acidentais:

```php
// 1. Com array de condições (seguro e expressivo)
$affected = $userModel->update(
    data: ['full_name' => 'Carlos Santos'],
    where: ['id' => 10, 'status' => 'active']
);

// 2. Com expressão SQL parametrizada
$affected = $userModel->update(
    data: ['status' => 'inactive'],
    where: 'WHERE last_login < ? AND role <> ?',
    whereParams: ['2025-01-01', 'admin']
);
```

### 5.4. `delete(array|string $where, array $whereParams = []): int`
Remove os registros correspondentes e retorna o número de linhas afetadas (também bloqueia `where` vazio):

```php
// 1. Com array de condições
$deleted = $userModel->delete(['id' => 10]);

// 2. Com expressão SQL parametrizada
$deleted = $userModel->delete(
    '(status = ? OR status = ?) AND verified_at IS NULL',
    ['spam', 'expired']
);
```

---

## 6. Consultas, Paginação Padronizada (`data` / `meta`) e Operadores de Filtro

### 6.1. Formato de Retorno Padronizado do `select()`

Tanto em `Model` quanto em qualquer subclasse de `AbstractModel`, o método `select()` retorna sempre uma estrutura padronizada em inglês contendo `data` (os registros da página) e `meta` (os metadados calculados automaticamente da paginação):

```php
[
    'data' => [
        ['id' => 1, 'fullName' => 'Ana Silva', 'email' => 'ana@lumynus.dev'],
        // ...
    ],
    'meta' => [
        'currentPage'  => 1,     // Página atual (padrão: 1)
        'previousPage' => 1,     // Página anterior (se estiver na 1, mantém 1; se na 3, retorna 2)
        'perPage'      => 10,    // Registros por página (padrão quando não informado: 100)
        'nextPage'     => 2,     // Próxima página (calculada automaticamente até o limite de totalPages)
        'totalRecords' => 15000, // Quantidade total de registros encontrados no banco (sem o LIMIT)
        'totalPages'   => 1500,  // Total de páginas calculadas: ceil(totalRecords / perPage)
    ],
]
```

> **Paginação Padrão Automática (`perPage = 100` e `currentPage = 1`):**
> Se você chamar `$model->select()` sem informar parâmetros de paginação (`page`, `perPage`, `limit` ou `offset`), o Lumy assume automaticamente **página `1`** e **`100` registros por página**, executa a contagem total no banco (`totalRecords`) e calcula todos os campos do `meta` para você!

### 6.2. Como Paginar no `select()` (Por `page`/`perPage` ou `offset`/`limit`)

Você pode paginar passando `page` e `perPage` (o `offset` é calculado automaticamente como `($page - 1) * $perPage`) ou passando `offset` e `limit`:

```php
// 1. Sem passar paginação -> assume automaticamente page: 1 e perPage: 100
$result = $userModel->select(
    filters: ['status' => 'active'],
    camelCaseTransform: true
);

// 2. Paginando por número da página (page) e quantidade por página (perPage)
$result = $userModel->select(
    filters: [
        'status'                      => ['active', 'trial'],
        'age BETWEEN'                 => [18, 65],
        'fullName LIKE || email LIKE' => 'silva',
        '!deletedAt'                  => null,
    ],
    orderBy: 'id DESC',
    page: 2,                               // Calcula offset = 20 automaticamente!
    perPage: 20,                           // Equivale a limit = 20
    fielders: ['id', 'fullName', 'email'], // Seleciona apenas estas colunas do mapa
    camelCaseTransform: true,              // Converte filtros e chaves do retorno para camelCase
    with: ['profile', 'orders']            // Carrega relacionamentos em lote
);

// 3. Paginando com offset e limit tradicionais (currentPage é calculado automaticamente)
$result = $userModel->select(
    offset: 40,
    limit: 20 // currentPage será calculado como 3!
);

// Acessando os registros e os metadados:
$rows = $result['data'];
$meta = $result['meta'];
```

### 6.3. Tabela Completa de Operadores (`select`, `update` e `delete`)

| Sintaxe no Array de Filtro | SQL Produzido | Descrição |
| :--- | :--- | :--- |
| `'age' => 18` ou `'age =' => 18` | `age = ?` | Igualdade exata |
| `'!status' => 'blocked'` ou `'status !=' => 'blocked'` | `status <> ?` / `status != ?` | Diferente de |
| `'age >' => 18`, `'age >=' => 18` | `age > ?`, `age >= ?` | Maior que / Maior ou igual |
| `'age <' => 65`, `'age <=' => 65` | `age < ?`, `age <= ?` | Menor que / Menor ou igual |
| `'name LIKE' => 'Ana'` | `name LIKE ? ESCAPE ...` (`'%Ana%'`) | Contém texto (escapa `%` e `_` acidentais) |
| `'name LIKE' => 'Ana%'` | `name LIKE ? ESCAPE ...` (`'Ana%'`) | Quando você inclui `%`, você controla os curingas |
| `'!name LIKE' => 'Ana'` ou `'name NOT LIKE' => 'Ana'` | `name NOT LIKE ? ESCAPE ...` | Não contém texto |
| `'status' => ['active', 'pending']` ou `'status IN' => [...]` | `status IN (?, ?)` | Pertence à lista de valores |
| `'!status' => ['blocked', 'banned']` ou `'status NOT IN' => [...]` | `status NOT IN (?, ?)` | Não pertence à lista |
| `'age BETWEEN' => [18, 65]` | `age BETWEEN ? AND ?` | Dentro do intervalo inclusivo |
| `'!age BETWEEN' => [18, 65]` ou `'age NOT BETWEEN' => [18, 65]` | `age NOT BETWEEN ? AND ?` | Fora do intervalo |
| `'email' => null` ou `'email IS' => null` | `email IS NULL` | Valor nulo |
| `'!email' => null` ou `'email IS NOT' => null` | `email IS NOT NULL` | Valor não nulo |
| `'email \|\| username' => 'ana'` | `(email = ? OR username = ?)` | Agrupamento lógico `OR` entre colunas |
| `'fullName LIKE \|\| email LIKE' => 'ana'` | `(full_name LIKE ? OR email LIKE ?)` | `OR` combinado com operadores e `camelCase` |

---

## 7. Relacionamentos (`1:1`, `1:N`, Aninhados e Multi-Conexão)

O Lumy resolve relacionamentos via *Batch Eager Loading*: primeiro busca a página exata da tabela pai e depois busca todos os registros filhos daquela página com uma única query `WHERE foreign_key IN (...)` por relacionamento. Isso **elimina o problema de N+1 queries** e **impede que o `LIMIT` da paginação seja corrompido** pela multiplicação de linhas de um `JOIN`.

```php
class CustomerModel extends AbstractModel
{
    protected string $table = 'customers';

    protected array $fields = [
        'id'            => ['db' => 'customers.id', 'select' => true],
        'customer_name' => ['db' => 'customers.customer_name', 'select' => true],
    ];

    protected array $relationships = [
        // Relação 1:1 (many: false -> retorna array associativo ou null)
        'address' => [
            'model'      => CustomerAddressModel::class,
            'localKey'   => 'id',
            'foreignKey' => 'customer_id',
            'many'       => false,
        ],
        // Relação 1:N (many: true -> retorna lista de arrays)
        'orders' => [
            'model'      => OrderModel::class,
            'localKey'   => 'id',
            'foreignKey' => 'customer_id',
            'many'       => true,
            // Opcional: permite buscar os filhos em OUTRO banco/conexão!
            // 'connectionIdentifier' => 'orders_db',
        ],
    ];
}
```

Carregando relacionamentos diretos e sub-relacionamentos aninhados:

```php
$customers = (new CustomerModel())->select(
    limit: 10,
    camelCaseTransform: true,
    with: [
        'address',
        'orders' => ['items'], // Carrega orders e, dentro de cada order, seus items!
    ]
);
```

---

## 8. Joins, Aliases e Agregações (`GROUP BY` / `HAVING`)

Para relatórios analíticos e consultas agregadas, declare `$alias`, `$joins`, funções SQL em `$fields` (`COUNT`, `SUM`, `AVG`, `MIN`, `MAX`) e `$groupBy`. Quando você filtra por um campo agregado em `filters`, o Lumy move automaticamente a condição para a cláusula `HAVING` (tanto na query de dados quanto na contagem `totalRecords`):

```php
class SaleReportModel extends AbstractModel
{
    protected string $table = 'sales';
    protected string $alias = 's';

    protected array $fields = [
        'category_id'   => ['db' => 'c.id', 'select' => true],
        'category_name' => ['db' => 'c.name', 'select' => true],
        'total_orders'  => ['db' => 'COUNT(s.id)', 'select' => true],
        'total_revenue' => ['db' => 'SUM(s.amount)', 'select' => true],
    ];

    protected ?array $joins = [
        'INNER JOIN categories c ON s.category_id = c.id',
    ];

    protected array $groupBy = ['c.id', 'c.name'];
}

$report = (new SaleReportModel())->select(
    filters: ['total_orders >=' => 5], // Aplicado automaticamente em HAVING COUNT(s.id) >= ?
    orderBy: 'total_revenue DESC'
);
```

---

## 9. Transações (Todas as Formas de Uso)

O Lumy disponibiliza a **função global `transaction()`** (além de `\Lumynus\Lumy\transaction()`, `LumyConnection::runTransaction()` e `$model->transaction()`):

```php
transaction(
    callable $transaction,
    ?callable $rollback = null,
    string $connectionIdentifier = 'default'
): mixed
```

### 9.1. Forma 1: Rollback Padrão Automático (`$rollback = null`)
Quando você **não** passa o segundo parâmetro (`$rollback = null`), o sistema abre a transação, executa suas queries e faz `commit` ao final. Se qualquer exceção ocorrer, o Lumy **executa o `rollBack()` automaticamente** no banco e relança a exceção:

```php
transaction(function () use ($walletModel, $logModel): void {
    $walletModel->update(['balance' => 700.00], ['id' => 1]);
    $walletModel->update(['balance' => 550.00], ['id' => 2]);
    $logModel->insert(['status' => 'SUCCESS', 'message' => 'Transferência concluída']);
});
```

### 9.2. Forma 2: Rollback Customizado pelo Usuário (`$rollback` informado)
Quando você passa uma função em `$rollback`, caso ocorra qualquer erro dentro de `$transaction`:
1. O Lumy **desfaz a transação pendente no banco (`rollBack()`)** imediatamente para garantir que nenhuma alteração parcial seja salva;
2. Em seguida, **executa o que está escrito no seu callback `$rollback`** (permitindo gravar logs de auditoria, executar compensações ou devolver um retorno amigável) e retorna o valor produzido por ele:

```php
$resultado = transaction(
    transaction: function () use ($walletModel): array {
        $walletModel->update(['balance' => 0.00], ['id' => 1]);
        throw new \RuntimeException('Saldo insuficiente para concluir a transferência.');
    },
    rollback: function (\Throwable $e) use ($logModel): array {
        // As queries de $transaction já sofreram rollBack() no banco!
        // Agora executamos a ação definida pelo usuário no rollback:
        $logModel->insert([
            'status'  => 'ROLLED_BACK',
            'message' => 'Rollback customizado: ' . $e->getMessage(),
        ]);

        return [
            'ok'    => false,
            'error' => $e->getMessage(),
        ];
    }
);
```

#### Assinaturas aceitas pelo callback `$rollback`:
O Lumy detecta automaticamente quantos parâmetros sua função recebe:
- `function (): mixed` *(sem parâmetros)*
- `function (\Throwable $e): mixed` *(recebendo a exceção)*
- `function (\Throwable $e, DatabaseDriverInterface $connection): mixed` *(recebendo a exceção e o driver da conexão)*

### 9.3. Forma 3: Transações em Conexões Nomeadas (`$connectionIdentifier`)
Para rodar uma transação em outra conexão registrada em `Config` (ex.: `'erp'` ou `'audit'`), passe o 3º parâmetro:

```php
transaction(
    transaction: function () use ($erpModel): void {
        $erpModel->insert(['order_code' => 'ORD-500', 'total' => 980.00]);
    },
    rollback: function (\Throwable $e): void {
        error_log('Rollback no banco ERP: ' . $e->getMessage());
    },
    connectionIdentifier: 'erp'
);
```

### 9.4. Forma 4: Direto na Instância da Model (`$model->transaction()`)
```php
$walletModel->transaction(
    function (WalletModel $model) use ($fromId, $toId): void {
        $model->update(['balance' => 700.00], ['id' => $fromId]);
        $model->update(['balance' => 550.00], ['id' => $toId]);
    },
    function (\Throwable $e, WalletModel $model): void {
        // Callback opcional de rollback customizado recebendo a exceção e a Model
    }
);
```

### 9.5. Forma 5: Transações Aninhadas (Savepoints Automáticos)
Chamar `transaction()` dentro de outro `transaction()` cria automaticamente um `SAVEPOINT` (`SAVEPOINT lumy_sp_N` ou `SAVE TRANSACTION lumy_sp_N` no SQL Server). Se o bloco interno falhar, **apenas o savepoint interno é revertido**, mantendo a transação externa intacta:

```php
transaction(function () use ($walletModel, $logModel): void {
    $walletModel->insert(['owner' => 'Alice', 'balance' => 1000.00]);

    // Transação interna isolada em um SAVEPOINT:
    transaction(
        transaction: function () use ($walletModel): void {
            $walletModel->insert(['owner' => 'BonusTemp', 'balance' => 50.00]);
            throw new \RuntimeException('Bônus expirado: reverter apenas o savepoint');
        },
        rollback: function (\Throwable $e) use ($logModel): void {
            $logModel->insert(['status' => 'SAVEPOINT_ROLLED_BACK', 'message' => $e->getMessage()]);
        }
    );

    // Alice e o log SAVEPOINT_ROLLED_BACK são confirmados no COMMIT externo!
});
```

### 9.6. Forma 6: Controle Manual (`beginTransaction`, `commit`, `rollBack`)
```php
try {
    $walletModel->beginTransaction();
    $walletModel->update(['balance' => 500.00], ['id' => 1]);
    $walletModel->commit();
} catch (\Throwable $e) {
    $walletModel->rollBack();
    throw $e;
}
```

---

## 10. Múltiplas Conexões e Gerenciamento de Pool

O `LumyConnection` mantém um pool de conexões reutilizáveis em memória durante o ciclo de vida do processo PHP, indexado por um hash SHA-256 das credenciais e do `connectionIdentifier`.

```php
use Lumynus\Lumy\Connections\LumyConnection;

// Obter a instância do driver de uma conexão registrada em Config
$driver = LumyConnection::connectionFor('default');

// Inspecionar conexões ativas no pool
$activeCount = LumyConnection::poolSize();
$identifiers = LumyConnection::identifiers();

// Fechar uma conexão específica pelo identificador
LumyConnection::closeByIdentifier('audit');

// Fechar todas as conexões do pool
LumyConnection::closeAll();
```

---

## 11. Migrations SQL (Todas as Formas de Executar)

O Lumy inclui um **Runner de Migrations SQL** nativo (`Lumynus\Lumy\Migrations` e `bin/migrate.php`) que executa arquivos `.sql` pendentes em ordem natural (`001_...`, `002_...`) e registra o histórico na tabela `lumy_migrations`.

### 11.1. Como o CLI encontra o `Config` se ele pode estar em qualquer parte do projeto?

Quando você roda um comando via terminal (`php vendor/bin/migrate.php default` ou `php bin/migrate.php default`), o PHP inicia um processo novo do zero. Como a classe `Config` guarda as conexões na memória desse processo, o CLI precisa localizar e executar onde suas conexões (`new Config(...)` ou `Config::setConnection(...)`) foram definidas.

Para que você **não fique preso a uma estrutura única de pastas ou de código**, o `bin/migrate.php` localiza suas configurações em **3 etapas automáticas**:

1. **Etapa 1 — Caminho Explícito (2º argumento do CLI):**
   Se você passar o caminho do seu arquivo de configuração como segundo argumento (ex.: `php vendor/bin/migrate.php default app/Core/DatabaseConfig.php`), ele carrega exatamente esse arquivo.
2. **Etapa 2 — Locais Convencionais na Raiz do Projeto:**
   Se você omitir o 2º argumento (`php vendor/bin/migrate.php default`), o CLI verifica automaticamente os arquivos mais comuns:
   - `bootstrap.php`, `config.php`
   - `config/database.php`, `config/lumy.php`, `config/db.php`, `config/config.php`
   - `app/Config/database.php`, `app/Config/Database.php`, `src/Config/database.php`
   - `database/config.php`, `bootstrap/database.php`, `bootstrap/app.php`
3. **Etapa 3 — Auto-Discovery em Qualquer Pasta, Model ou Classe do Projeto:**
   Se a sua conexão ainda não tiver sido encontrada nos caminhos acima, o `bin/migrate.php` **vasculha automaticamente os arquivos `.php` do seu projeto** (ignorando `vendor`, `node_modules`, `tests`, `public/index.php`, etc.), analisa o código-fonte como texto antes de carregá-lo para encontrar chamadas a `Config::setConnection(...)` ou `new Config(...)` e registra a conexão — **mesmo que ela esteja dentro de uma Model ou Classe!**

#### Formas Suportadas de Declarar o `Config` (Todas Reconhecidas pelo CLI):

```php
// Forma A: Em qualquer arquivo de configuração ou bootstrap (ex.: config/database.php ou src/Infra/db.php)
use Lumynus\Lumy\Config;

Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'postgresql',
    host: '127.0.0.1:5432',
    user: 'postgres',
    password: 'secret',
    database: 'minha_aplicacao'
);
```

```php
// Forma B: No topo do arquivo de uma Model ou Classe (fora do bloco class { ... })
namespace App\Models;

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;

new Config(driver: 'mysql', host: '127.0.0.1', user: 'root', password: '', database: 'app');

class UserModel extends AbstractModel
{
    protected string $table = 'users';
}
```

```php
// Forma C: Dentro do __construct() de uma Model ou BaseModel (executado via Reflection pelo CLI)
namespace App\Models;

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;

abstract class BaseModel extends AbstractModel
{
    public function __construct(string $connectionIdentifier = 'default')
    {
        new Config(
            driver: 'mysql',
            host: '127.0.0.1',
            user: 'root',
            password: '',
            database: 'app',
            connectionIdentifier: 'default'
        );

        parent::__construct($connectionIdentifier);
    }
}
```

```php
// Forma D: Dentro de um método de qualquer Classe de Configuração (ex.: Database::init())
namespace App\Core;

use Lumynus\Lumy\Config;

class Database
{
    public static function init(): void
    {
        Config::setConnection(
            connectionIdentifier: 'default',
            driver: 'sqlite',
            database: __DIR__ . '/../../storage/app.sqlite'
        );
    }
}
```

```php
// Forma E: Em um arquivo que retorna uma Closure / função anônima
use Lumynus\Lumy\Config;

return static function (): void {
    Config::setConnection('default', driver: 'mysql', host: '127.0.0.1', user: 'root', password: '', database: 'app');
};
```

---

### 11.2. Preparando os Arquivos `.sql`

Crie a pasta de migrations (por padrão `database/migrations/` na raiz do seu projeto) com arquivos `.sql` numerados:
```text
database/
└── migrations/
    ├── 001_create_users.sql
    └── 002_create_orders.sql
```

Cada arquivo `.sql` pode conter múltiplos comandos separados por `;`, além de comentários (`--`, `#`, `/* ... */`):
```sql
-- database/migrations/001_create_users.sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE
);

CREATE INDEX idx_users_email ON users (email);
```

---

### 11.3. Todas as Formas de Executar as Migrations

Você pode rodar suas migrations tanto **via Terminal (CLI)** quanto **via Código PHP (Programático)**:

#### Forma 1: Via CLI com Auto-Discovery (sem precisar informar onde está a `Config`)
Basta passar o nome da conexão (`default`). O Lumy encontrará automaticamente em qual arquivo do seu projeto o `Config` da conexão `default` foi declarado:
```bash
# Quando instalado via Composer no seu projeto (binário do Composer):
php vendor/bin/migrate.php default

# Ou chamando diretamente o script:
php bin/migrate.php default
```

#### Forma 2: Via CLI Apontando para o Arquivo onde está sua `Config` (2º argumento)
Se você quiser apontar manualmente para o arquivo onde suas conexões estão (ou se sua configuração estiver dentro de uma função/closure retornada por um arquivo), passe-o como **2º argumento**:
```bash
php vendor/bin/migrate.php default src/Infra/Database/connections.php
```

#### Forma 3: Via CLI Sobrescrevendo a Pasta de Migrations (3º argumento)
Para executar migrations de uma pasta específica (ex.: um módulo isolado, banco de auditoria ou *hotfix*), informe o diretório como **3º argumento**:
```bash
php vendor/bin/migrate.php audit config/database.php database/migrations/audit
```

> **Sintaxe completa do CLI:**
> ```bash
> php vendor/bin/migrate.php <connectionIdentifier> [caminho/do/config.php] [caminho/da/pasta-migrations]
> ```
> **Saída e códigos de retorno (ideal para CI/CD e Docker):**
> - Imprime `Applied: 001_create_users.sql` para cada arquivo novo ou `No pending migrations.` caso tudo já esteja atualizado;
> - Código `0`: sucesso; Código `1`: erro ao conectar ou executar SQL; Código `2`: argumentos inválidos ou conexão não encontrada.

#### Forma 4: Via Atalho no `composer.json` (Composer Scripts)
Adicione scripts no `composer.json` do seu projeto para rodar com um único comando:
```json
{
    "scripts": {
        "migrate": "php vendor/bin/migrate.php default",
        "migrate:audit": "php vendor/bin/migrate.php audit"
    }
}
```
E execute no terminal:
```bash
composer migrate
composer migrate:audit
```

#### Forma 5: Via Código PHP (Usando a Pasta Definida no `Config`)
Quando você roda pela própria aplicação PHP, o `Config` já foi carregado no fluxo normal do seu sistema. Basta instanciar `Migrations` passando o `connectionIdentifier` e chamar `run()`:
```php
use Lumynus\Lumy\Config;
use Lumynus\Lumy\Migrations;

Config::setConnection(
    connectionIdentifier: 'default',
    driver: 'sqlite',
    database: __DIR__ . '/storage/database.sqlite',
    migrationsPath: __DIR__ . '/database/migrations'
);

$runner = new Migrations('default');
$appliedFiles = $runner->run(); // Retorna array com os nomes dos arquivos aplicados (ex.: ['001_create_users.sql'])
```

#### Forma 6: Via Código PHP (Informando a Pasta Diretamente no Construtor)
Ideal para testes automatizados, instaladores web, *multi-tenancy* ou execução dinâmica em qualquer rota/controller:
```php
use Lumynus\Lumy\Migrations;

$runner = new Migrations(
    connectionIdentifier: 'default',
    migrationsDirectory: __DIR__ . '/database/migrations'
);

$appliedFiles = $runner->run();
// Na 1ª execução: ['001_create_users.sql', '002_create_orders.sql']
// Na 2ª execução: [] (idempotente — ignora arquivos já registrados em lumy_migrations)
```

---

### 11.4. Ordem de Prioridade da Pasta e Recursos de Segurança das Migrations

O Lumy resolve qual pasta de migrations usar nesta ordem:
1. O diretório passado explicitamente em `new Migrations($id, $migrationsDirectory)` (ou 3º argumento do CLI);
2. O `migrationsPath` configurado em `Config::setConnection(...)` para aquela conexão;
3. A pasta padrão `database/migrations/` na raiz do projeto atual.

**Segurança e Concorrência no Runner:**
- **Idempotência Garantida:** Cria e consulta automaticamente a tabela `lumy_migrations` no dialeto do banco conectado;
- **Parser SQL Completo:** Separa múltiplos statements por `;` sem quebrar strings com ponto-e-vírgula, comentários ou blocos `$$ ... $$` de funções/triggers do PostgreSQL;
- **Transação por Arquivo:** Em SQLite, PostgreSQL, SQL Server e Firebird, cada arquivo `.sql` roda dentro de uma transação isolada (se um statement falhar, o arquivo inteiro sofre rollback);
- **Lock Global contra Execução Concorrente:** Impede que dois containers/processos apliquem as mesmas migrations simultaneamente no deploy usando `GET_LOCK` (MySQL/MariaDB), `pg_try_advisory_lock` (PostgreSQL) e `sp_getapplock` (SQL Server).

---

## 12. Segurança e Boas Práticas

1. **Prepared Statements Nativos em 100% dos Valores:** Nenhum valor de `insert`, `upsert`, `update`, `delete` ou `select` é concatenado na query. No MySQL, `EMULATE_PREPARES` e `MULTI_STATEMENTS` ficam desativados.
2. **Validação Estrita de Identificadores SQL:** Nomes de tabelas, colunas e aliases passam por validação regex (`/\A[A-Za-z_][A-Za-z0-9_]*\z/`), impedindo SQL Injection via chaves de array.
3. **Proteção de Whitelist no Mapa `$fields`:** Quando uma `AbstractModel` define `$fields`, qualquer tentativa de filtrar ou ordenar por uma coluna não mapeada é bloqueada com `InvalidArgumentException`.
4. **Proteção de Credenciais (`#[\SensitiveParameter]`):** Senhas nunca aparecem em *stack traces* ou logs de exceção do PHP.
5. **Bloqueio de `UPDATE` e `DELETE` sem `WHERE`:** Condições vazias são rejeitadas por padrão.
6. **Escape Automático em `LIKE`:** Curingas `%`, `_` e `\` digitados sem intenção são escapados com cláusula `ESCAPE` específica para cada dialeto.

---

## 13. Exemplos Executáveis e Links Oficiais

Explore a pasta [`examples/`](examples/README.md) para testar scripts prontos e independentes (usando SQLite em memória, sem precisar subir servidores externos):

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

Para rodar a suíte de testes automatizados:

```bash
php tests/example1.php
```

### Guias Detalhados no Repositório
- [Guia Completo de Transações (`docs/Transactions.md`)](docs/Transactions.md)
- [Guia Completo dos Bancos de Dados (`docs/Databases.md`)](docs/Databases.md)
- [Referência de Models (`docs/Model.md`)](docs/Model.md)
- [Referência de Migrations (`docs/Migrations.md`)](docs/Migrations.md)

---

<div align="center">

**Documentação Oficial do Lumy:** [https://lumy.lumynus.dev/](https://lumy.lumynus.dev/)  
**Ecossistema Lumynus Framework:** [https://lumynus.dev/](https://lumynus.dev/)

Desenvolvido com foco em simplicidade, performance e elegância por **Weleny Santos** & **Lumynus**.  
Distribuído sob a **Licença MIT**.

</div>


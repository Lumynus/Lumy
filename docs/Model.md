# Model

`AbstractLumy` contém as regras CRUD. Por convenção, os modelos da aplicação
devem estender `AbstractModel`, que herda essas operações. Defina a propriedade
`$table` para herdar `insert`, `upsert`, `update`, `delete` e `select` sem
reescrevê-las. A conexão é selecionada pela configuração registrada em `Config`.

## Configuração

Registre a conexão antes de criar modelos. A configuração fica disponível em
memória durante a execução atual do PHP.

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Lumynus\Lumy\Config;
use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Model;

new Config(
    driver: 'mysql',
    host: '127.0.0.1',
    user: 'app_user',
    password: 'sua_senha',
    database: 'app_db',
    connectionIdentifier: 'default'
);

class UserModel extends AbstractModel
{
    protected string $table = 'users';

    protected array $fields = [
        'id' => ['db' => 'users.id', 'select' => true],
        'cpf' => ['db' => 'users.cpf', 'select' => true],
        'nome' => ['db' => 'users.nome', 'select' => true],
        'data_cadastro' => ['db' => 'users.data_cadastro', 'select' => true],
    ];
}

$users = new UserModel();
```

O construtor herdado recebe opcionalmente o identificador de conexão. Para
usar a conexão `archive` no modelo:

```php
$archiveUsers = new UserModel('archive');
```

O segundo argumento deve corresponder ao `connectionIdentifier` usado ao
registrar uma configuração:

```php
new Config(
    driver: 'sqlite',
    host: '',
    user: '',
    password: null,
    database: __DIR__ . '/archive.sqlite',
    connectionIdentifier: 'archive'
);
```

Para um uso rápido sem declarar uma subclasse, `Model` continua disponível como
atalho que recebe o nome da tabela:

```php
$users = new Model('users');
```

`AbstractModel` é a classe convencional a ser estendida pelas models da
aplicação. `AbstractLumy` é a base interna que fornece as regras comuns.

## Consultar um registro

`Model::get()` retorna o primeiro registro encontrado ou `null`. O argumento
`camelCaseTransform` converte nomes camelCase dos filtros para snake_case do
banco e as chaves retornadas de volta para camelCase.

```php
$user = (new Model('users'))->get(
    ['cpf' => '874444444'],
    camelCaseTransform: true
);
```

Ao usar uma subclasse, `get()` não é exposto; use `select()` com limite 1.

## Consultar vários registros

`select()` executa imediatamente e retorna uma lista de registros. Os
argumentos recomendados são nomeados para deixar claro o papel de cada um:

```php
$users = $userModel->select(
    filters: ['dataCadastro >=' => '2026-01-01'],
    orderBy: 'dataCadastro DESC',
    offset: 0,
    limit: 10,
    fielders: ['id', 'nome', 'dataCadastro'],
    camelCaseTransform: true
);
```

Com um mapa `$fields`, apenas os campos marcados com `select => true` são
retornados quando `fielders` não é informado. `fielders` escolhe um subconjunto
desse mapa; um campo inexistente no mapa é rejeitado. Sem mapa `$fields`, a
consulta seleciona todas as colunas, ou apenas as indicadas em `fielders`.

`offset` é a quantidade de linhas a ignorar e `limit` é a quantidade máxima de
linhas. `limit: null` desativa o limite. Quando há paginação sem `orderBy`, o
modelo usa `id ASC` como ordem estável. Um `limit` igual a zero retorna uma
lista vazia.

## CamelCase

Com `camelCaseTransform: true`, filtros e nomes passados em `fielders` são
convertidos de camelCase para snake_case antes de serem resolvidos no mapa. As
chaves das linhas retornadas são convertidas de snake_case para camelCase.

```php
$users = (new Model('users'))->select(
    filters: ['dataCadastro' => '2026-10-19'],
    fielders: ['dataCadastro', 'nomeCompleto'],
    camelCaseTransform: true
);
```

No modelo, as chaves do mapa `$fields` devem continuar em snake_case, por
exemplo `data_cadastro` e `nome_completo`.

## Joins e agregações

Declare joins SQL confiáveis na classe e mapeie cada resultado pelo nome
lógico. O `db` pode ser uma coluna qualificada ou uma expressão de agregação:

```php
class PatientRequestModel extends AbstractModel
{
    protected string $table = 'tbl_pacientes_solicitacoes_alteracao';
    protected string $alias = 'psa';

    protected array $fields = [
        'id' => ['db' => 'psa.id', 'select' => true],
        'paciente_id' => ['db' => 'psa.paciente_id', 'select' => true],
        'paciente_nome' => ['db' => 'p.nome', 'select' => true],
        'total' => ['db' => 'COUNT(psa.id)', 'select' => true],
    ];

    protected ?array $joins = [
        'INNER JOIN tbl_pacientes p ON psa.paciente_id = p.id',
    ];

    protected array $groupBy = ['psa.id', 'p.nome'];
}
```

Use `select()` com `fielders` para escolher quais campos do mapa retornar.
`$joins` e as expressões `db` são SQL definido pelo desenvolvedor, não entrada
de usuário.

## Relacionamentos

Para relações 1:N, prefira `$relationships` com `with`. O modelo primeiro busca
a página de registros principais e depois busca todos os filhos daquela página
em uma consulta por relacionamento. Isso evita que o `LIMIT` corte os pais ou
os filhos por causa da multiplicação de linhas do JOIN.

```php
class PatientModel extends AbstractModel
{
    protected string $table = 'tbl_pacientes';

    protected array $fields = [
        'id' => ['db' => 'tbl_pacientes.id', 'select' => true],
        'nome' => ['db' => 'tbl_pacientes.nome', 'select' => true],
    ];

    protected array $relationships = [
        'solicitacoes' => [
            'model' => PatientRequestModel::class,
            'localKey' => 'id',
            'foreignKey' => 'paciente_id',
            'many' => true,
        ],
    ];
}

$patients = (new PatientModel())->select(
    orderBy: 'id ASC',
    offset: 0,
    limit: 10,
    with: ['solicitacoes']
);
```

O campo `model` deve ser uma subclasse de `AbstractModel`. `localKey` aponta
para o campo lógico do pai; `foreignKey` aponta para o campo lógico no modelo
relacionado. `many` é `true` por padrão; use `false` para retornar um objeto
relacionado ou `null`. Subrelacionamentos podem ser ativados de forma aninhada:

```php
$patients = (new PatientModel())->select(
    with: [
        'solicitacoes' => ['anexos'],
    ]
);
```

Cada relacionamento é buscado em lote; não é executada uma query por linha.
Joins 1:1 também podem ser usados para agregar campos na consulta principal.
Evite joins 1:N em consultas paginadas: se usados diretamente em `$joins`, o
limite do banco se aplica às linhas combinadas, não aos registros principais.

Os nomes de tabela e coluna aceitos são identificadores simples formados por
letras ASCII, números e `_`, começando por letra ou `_`.

## Inserir

Passe um array associativo com nomes de colunas e valores. O método retorna o
ID gerado pelo driver, quando disponível.

```php
$id = $users->insert([
    'name' => 'Ana',
    'email' => 'ana@example.com',
]);
```

Os valores são enviados como parâmetros preparados. `insert()` não recebe
`where`: em SQL, o `INSERT` define os dados da nova linha, não filtra linhas
existentes.

## Inserir ou atualizar (Upsert)

Use `upsert()` para inserir os dados ou atualizar a linha correspondente a uma
chave única. Informe em `$uniqueBy` as colunas que identificam o conflito; elas
precisam estar cobertas por uma constraint ou índice único no banco. O método
retorna `true` quando o comando é executado sem erro.

```php
$saved = $users->upsert(
    [
        'email' => 'ana@example.com',
        'name' => 'Ana Silva',
    ],
    ['email']
);
```

O comportamento é gerado pelo driver do banco. MySQL/MariaDB considera qualquer
índice único que entre em conflito; PostgreSQL e SQLite usam as colunas de
`$uniqueBy` como alvo do `ON CONFLICT`; SQL Server usa `MERGE`; Firebird usa
`UPDATE OR INSERT ... MATCHING`.

## Atualizar

`update()` recebe os dados novos e um array de condições. O método retorna o
número de linhas afetadas. Condições em chaves diferentes são combinadas com
`AND`.

```php
$affected = $users->update(
    ['name' => 'Ana Silva'],
    ['id' => 12]
);

$affected = $users->update(
    ['status' => 'active'],
    [
        'tenant_id' => 4,
        'email' => 'ana@example.com',
    ]
);
```

Também é possível fornecer a expressão SQL completa do `WHERE` como string.
Use `?` para os valores e passe-os no terceiro argumento:

```php
$affected = $users->update(
    ['status' => 'active'],
    'WHERE (age >= ? AND age < ?) OR email IS NULL',
    [18, 65]
);
```

O prefixo `WHERE` é opcional. Essa forma permite expressões SQL não cobertas
pelo array de condições.

## Excluir

`delete()` recebe as condições e também retorna o número de linhas afetadas.
Um `where` vazio é rejeitado para evitar apagar a tabela inteira por acidente.

```php
$deleted = $users->delete(['id' => 12]);

$deleted = $users->delete(
    '(status = ? OR status = ?) AND email IS NOT NULL',
    ['blocked', 'inactive']
);
```

Assim como em `update()`, `delete()` aceita uma expressão SQL personalizada
como primeiro argumento e seus parâmetros como segundo argumento.

## Operadores do where

O operador pode ser escrito depois do nome da coluna. Sem operador, é usado
`=`.

| Chave | SQL produzido |
| --- | --- |
| `'age' => 18` | `age = ?` |
| `'age =' => 18` | `age = ?` |
| `'age <>' => 18` | `age <> ?` |
| `'age !=' => 18` | `age != ?` |
| `'age >' => 18` | `age > ?` |
| `'age >=' => 18` | `age >= ?` |
| `'age <' => 65` | `age < ?` |
| `'age <=' => 65` | `age <= ?` |

Exemplo com intervalo inclusivo:

```php
$users->update(
    ['user_group' => 'adult'],
    ['age BETWEEN' => [18, 65]]
);
```

`BETWEEN` e `NOT BETWEEN` exigem exatamente dois valores não nulos:

```php
$users->delete(['age NOT BETWEEN' => [18, 65]]);
```

## IN e NOT IN

Um array de valores gera `IN`. Prefixar a coluna com `!` gera `NOT IN`:

```php
$users->delete(['status' => ['blocked', 'inactive']]);
$users->delete(['!status' => ['active', 'pending']]);
```

Listas usadas em `IN`, `NOT IN` ou `BETWEEN` não podem ser vazias nem conter
`null`.

## NULL

Use `null` diretamente para testar valores nulos:

```php
$users->delete(['email' => null]);
$users->delete(['!email' => null]);
```

Essas condições geram `email IS NULL` e `email IS NOT NULL`. `null` só pode ser
usado com igualdade ou sua negação; comparações como `> null` são rejeitadas.

## OR

Use `||` entre nomes de colunas. As colunas desse grupo são comparadas com o
mesmo valor; outras entradas do array continuam combinadas por `AND`.

```php
$users->update(
    ['status' => 'review'],
    [
        'tenant_id' => 4,
        'email || username' => 'ana',
    ]
);
```

O `where` gerado equivale a:

```sql
tenant_id = ? AND (email = ? OR username = ?)
```

Também funciona com `null` e listas:

```php
$users->delete(['email || username' => null]);
$users->delete(['status || role' => ['blocked', 'inactive']]);
```

O formato atual não permite dar valores diferentes às colunas dentro do mesmo
grupo `||`. Para alternativas sobre a mesma coluna, use `IN`, por exemplo
`['status' => ['active', 'pending']]`.

## Negação

O prefixo `!` nega igualdade, `IN` e `BETWEEN`:

```php
$users->delete(['!status' => 'blocked']);
$users->delete(['!status' => ['blocked', 'inactive']]);
$users->delete(['!age BETWEEN' => [18, 65]]);
```

Eles geram `<>`, `NOT IN` e `NOT BETWEEN`, respectivamente.

## Segurança e limitações

- Os valores de `insert`, `update` e `where` são enviados por parâmetros
  preparados.
- Expressões `WHERE` personalizadas são SQL escrito pelo desenvolvedor; use
    placeholders (`?`) para valores e nunca concatene entrada não confiável.
- Os operadores aceitos são validados pelo modelo.
- Não concatene valores recebidos de usuários nas chaves do `where`.
- `update()` e `delete()` não aceitam condições vazias.
- O ID de `insert()` depende do driver; o driver Ibase ainda retorna `0` porque
  a leitura de generators não foi implementada.
- `getAffectedRows()` retorna o que o driver reporta. Alguns bancos podem
  reportar zero quando um `UPDATE` grava o mesmo valor que já estava na linha.
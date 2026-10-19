# Migrations

`Lumynus\Lumy\Migrations` executa arquivos `.sql` pendentes em ordem natural
de nome. Cada arquivo pode conter um ou mais statements SQL separados por `;`.

O runner é CLI, não é executado automaticamente ao carregar a biblioteca. Ele
usa a conexão cadastrada em `Config` para o identificador informado no comando.

## Configuração para CLI

A configuração de `Config` existe somente na memória do processo PHP atual.
Assim, cada execução do comando precisa carregar um bootstrap que registre a
conexão. O bootstrap padrão é `bootstrap.php` na raiz do projeto. Exemplo:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Lumynus\Lumy\Config;

new Config(
    driver: 'mysql',
    host: '127.0.0.1',
    user: 'app_user',
    password: getenv('DB_PASSWORD') ?: '',
    database: 'app_db',
    connectionIdentifier: 'default',
    migrationsPath: __DIR__ . '/database/migrations'
);
```

No exemplo, a conexão é registrada como `default`, que será o primeiro
argumento do comando. O bootstrap deve apenas preparar a aplicação e registrar
as configurações necessárias; não deve executar as migrations por conta própria.

`migrationsPath` é opcional e define a pasta de migrations daquela conexão.
Você pode apontar várias conexões para pastas diferentes:

```php
Config::setConnection(
    connectionIdentifier: 'billing',
    driver: 'mysql',
    host: '127.0.0.1',
    user: 'billing_user',
    password: getenv('BILLING_DB_PASSWORD') ?: '',
    database: 'billing',
    migrationsPath: __DIR__ . '/database/billing-migrations'
);
```

Se a aplicação já tiver um bootstrap CLI que registra a conexão, use esse
arquivo em vez de criar outro. Não grave senhas reais no repositório; use
variáveis de ambiente ou o mecanismo de configuração da aplicação.

## Executar

Na raiz do projeto, crie migrations em `database/migrations/`. Use nomes
ordenáveis com prefixo numérico ou timestamp:

```text
database/
└── migrations/
        ├── 001_create_users.sql
        └── 002_add_users_email.sql
```

Exemplo de `001_create_users.sql`:

```sql
CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE
);
```

Execute da raiz do projeto:

```bash
php bin/migrate.php default
```

Sem override no comando, o caminho é escolhido nesta ordem: terceiro argumento
do CLI, `migrationsPath` da conexão e, por fim, `database/migrations/` relativo
à raiz da biblioteca. A sintaxe completa é:

```bash
php bin/migrate.php <connectionIdentifier> [bootstrap.php] [migrations-directory]
```

Por exemplo, no Bash ou PowerShell:

```bash
php bin/migrate.php billing config/console.php
```

O segundo e terceiro argumentos substituem, respectivamente, o bootstrap e a
pasta de migrations. Caminhos relativos são resolvidos a partir do diretório
atual do terminal; caminhos absolutos também podem ser usados. O bootstrap deve
registrar exatamente o `connectionIdentifier` passado como primeiro argumento.

Para sobrepor a pasta configurada, informe o terceiro argumento:

```bash
php bin/migrate.php billing config/console.php database/migrations/billing-hotfix
```

O comando imprime `Applied: <arquivo>` para cada arquivo novo e
`No pending migrations.` quando não houver trabalho.

### Códigos de saída

- `0`: todas as migrations pendentes foram aplicadas ou não havia nenhuma.
- `1`: erro ao conectar, ler ou executar uma migration.
- `2`: uso incorreto, identificador ausente ou bootstrap não encontrado.

## Histórico e reexecução

O runner cria a tabela `lumy_migrations` no banco selecionado. Ela armazena o
nome de cada arquivo concluído. Migrations já registradas são ignoradas nas
execuções seguintes.

O histórico guarda nomes, não hashes do conteúdo. Depois de aplicada, não edite
uma migration existente: crie outro arquivo numerado. Não há comando de
rollback/down; alterações inversas devem ser escritas como uma nova migration.

Se uma migration falhar, o runner interrompe a execução e não registra aquele
arquivo. Corrija a causa antes de executar novamente. Em drivers transacionais,
cada arquivo é executado em uma transação e recebe rollback em caso de erro.
MySQL é executado sem transação externa porque DDL pode fazer commit implícito;
um arquivo MySQL que falhar pode ter aplicado parcialmente seus statements.
Nesse caso, inspecione e repare manualmente o banco antes de repetir o arquivo.

## Formato dos arquivos

Somente arquivos com extensão minúscula `.sql` diretamente dentro da pasta
configurada são descobertos. Subpastas não são percorridas. A ordenação é
natural e não diferencia maiúsculas/minúsculas, mas prefira nomes sempre
minúsculos e prefixos de largura consistente (`001`, `002`, `010`).

O runner divide statements pelo caractere `;` quando ele não está dentro de
uma string entre aspas ou comentário. Um arquivo pode conter, por exemplo:

```sql
CREATE TABLE categories (
        id INTEGER PRIMARY KEY,
        name VARCHAR(100) NOT NULL
);

CREATE INDEX idx_categories_name ON categories (name);
```

Strings entre aspas simples/duplas, identificadores entre crases ou colchetes,
comentários de linha (`--`, `#`) e comentários de bloco (`/* ... */`) são
considerados pelo separador. `DELIMITER` do cliente MySQL e blocos dollar-quoted
do PostgreSQL (como `DO $$ ... $$`) não são suportados. Para procedures,
functions e triggers com delimitadores especiais, use uma ferramenta específica
do banco ou uma migration PHP executada por outro mecanismo.

Use SQL compatível com o driver configurado. DDL, tipos, índices, sequências e
identidades variam entre bancos; o runner não converte SQL de um dialeto para
outro.

## Erros comuns

- **`Configuration '<nome>' is not defined`:** confira se o bootstrap registra
    o mesmo identificador passado no terminal.
- **Bootstrap não encontrado:** execute da raiz ou passe seu caminho como
    segundo argumento.
- **Migration não localizada:** confirme a pasta do terceiro argumento, a
    extensão minúscula `.sql` e se os arquivos estão diretamente nela.
- **Erro SQL:** confira o dialeto do banco e o conteúdo do arquivo. Em MySQL,
    verifique se parte dos statements foi aplicada antes da falha.
- **Sem permissão:** o usuário do banco precisa criar a tabela `lumy_migrations`
    e executar o DDL/DML dos arquivos.

Os arquivos `.sql` são código confiável executado diretamente no banco. Não
gere migrations concatenando dados recebidos de usuários.

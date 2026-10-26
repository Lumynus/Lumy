# Migrations

`Lumynus\Lumy\Migrations` executa arquivos `.sql` pendentes em ordem natural
de nome. Cada arquivo pode conter um ou mais statements SQL separados por `;`.

O runner pode ser executado tanto via CLI (`bin/migrate.php` ou `vendor/bin/migrate.php`)
quanto programaticamente via código PHP (`new Migrations(...)`). Ele usa a conexão
cadastrada em `Config` para o identificador informado.

## Como o CLI encontra o `Config` em qualquer parte do projeto

A configuração de `Config` existe na memória do processo PHP atual. Quando você
executa o comando no terminal, o `bin/migrate.php` localiza e carrega o arquivo
onde suas conexões (`new Config(...)` ou `Config::setConnection(...)`) estão
definidas por meio de **3 etapas automáticas**:

1. **Caminho explícito (2º argumento do CLI):**
   Se você informar o caminho do arquivo de configuração no segundo argumento
   (por exemplo, `php vendor/bin/migrate.php default app/Core/Database.php`),
   esse arquivo é carregado diretamente.
2. **Locais convencionais na raiz do projeto:**
   Se o segundo argumento não for informado (`php vendor/bin/migrate.php default`),
   o CLI procura automaticamente nos caminhos mais comuns:
   - `bootstrap.php`, `config.php`
   - `config/database.php`, `config/lumy.php`, `config/db.php`, `config/config.php`
   - `app/Config/database.php`, `app/Config/Database.php`, `src/Config/database.php`
   - `database/config.php`, `bootstrap/database.php`, `bootstrap/app.php`
3. **Auto-Discovery em qualquer pasta do projeto:**
   Se a conexão ainda não tiver sido registrada após a etapa anterior, o CLI
   vasculha automaticamente os arquivos `.php` do seu projeto (ignorando pastas
   como `vendor`, `node_modules`, `tests`, `examples`, `bin` e `public/index.php`)
   em busca de chamadas a `Config::setConnection(...)` ou `new Config(...)`,
   carregando-os até localizar o `connectionIdentifier` solicitado.

Exemplo de arquivo de configuração (em qualquer pasta do projeto):

```php
<?php

declare(strict_types=1);

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

Não grave senhas reais no repositório; use variáveis de ambiente ou o mecanismo
de configuração da aplicação.

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
# Via binário do Composer (quando lumynus/lumy está instalado no projeto):
php vendor/bin/migrate.php default

# Ou diretamente no repositório da biblioteca:
php bin/migrate.php default
```

Sem override no comando, o caminho da pasta de migrations é escolhido nesta ordem:
1. Terceiro argumento do CLI (`[migrations-directory]`);
2. `migrationsPath` configurado na conexão em `Config`;
3. `database/migrations/` relativo à raiz do projeto atual.

A sintaxe completa é:

```bash
php vendor/bin/migrate.php <connectionIdentifier> [config-or-bootstrap.php] [migrations-directory]
```

Por exemplo, no Bash ou PowerShell:

```bash
php vendor/bin/migrate.php billing config/console.php
```

Para sobrepor a pasta configurada, informe o terceiro argumento:

```bash
php vendor/bin/migrate.php billing config/console.php database/migrations/billing-hotfix
```

O comando imprime `Applied: <arquivo>` para cada arquivo novo e
`No pending migrations.` quando não houver trabalho.

### Códigos de saída

- `0`: todas as migrations pendentes foram aplicadas ou não havia nenhuma.
- `1`: erro ao conectar, ler ou executar uma migration.
- `2`: uso incorreto, identificador ausente ou configuração não encontrada.

## Histórico e reexecução

O runner cria a tabela `lumy_migrations` no banco selecionado. Ela armazena o
nome de cada arquivo concluído. Migrations já registradas são ignoradas nas
execuções seguintes.

O histórico guarda nomes, não hashes do conteúdo. Depois de aplicada, não edite
uma migration existente: crie outro arquivo numerado. Não há comando de
rollback/down; alterações inversas devem ser escritas como uma nova migration.

Para impedir que dois processos apliquem os mesmos arquivos ao mesmo tempo, o
runner adquire um lock global no servidor antes de executar e o libera ao
terminar (`finally`, mesmo em caso de erro). O lock é nomeado a partir da pasta
de migrations, então projetos diferentes no mesmo servidor não se bloqueiam:

- MySQL/MariaDB: `GET_LOCK`.
- PostgreSQL: `pg_try_advisory_lock` com `hashtext`.
- SQL Server: `sp_getapplock` com `@LockOwner = 'Session'`.
- SQLite e Firebird: sem lock de servidor. Em SQLite os escritores já são
  serializados e, em ambos, a chave primária da tabela de histórico impede o
  registro duplicado de uma migration.

Se o lock não estiver disponível em 60 segundos, `run()` lança `RuntimeException`
e nenhuma migration pendente é aplicada.

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
comentários de linha (`--`, `#`), comentários de bloco (`/* ... */`) e blocos
dollar-quoted do PostgreSQL (`$$ ... $$` ou `$tag$ ... $tag$`) são considerados
pelo separador. O comando `DELIMITER` do cliente MySQL não é suportado. Para
procedures, functions e triggers com delimitadores especiais, use uma ferramenta
específica do banco ou uma migration PHP executada por outro mecanismo.

Use SQL compatível com o driver configurado. DDL, tipos, índices, sequências e
identidades variam entre bancos; o runner não converte SQL de um dialeto para
outro.

<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;
use Lumynus\Lumy\Connections\LumyConnection;
use Lumynus\Lumy\Contracts\DatabaseDriverInterface;
use Lumynus\Lumy\Drivers\PdoDriver;
use Lumynus\Lumy\Migrations;
use Lumynus\Lumy\Model;

/**
 * Suite de testes automatizados do Lumy MicroORM.
 * Executa em SQLite (:memory:) e verifica todos os 5 dialetos de bancos suportados
 * (SQLite, MySQL/MariaDB, PostgreSQL, SQL Server e Firebird/InterBase),
 * alem da funcao global transaction($transaction, $rollback).
 */
function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            "Assertion failed: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function assertThrows(string $exceptionClass, callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $exceptionClass) {
            return;
        }
        throw new RuntimeException(
            "Assertion failed: {$message} (threw " . $e::class . ': ' . $e->getMessage() . ')'
        );
    }

    throw new RuntimeException("Assertion failed: {$message} (no exception thrown)");
}

Config::clear();
LumyConnection::closeAll();

new Config(
    driver: 'sqlite',
    database: ':memory:',
    connectionIdentifier: 'default'
);

class AuthorProfileModel extends AbstractModel
{
    protected string $table = 'author_profiles';

    protected array $fields = [
        'id' => ['db' => 'author_profiles.id', 'select' => true],
        'author_id' => ['db' => 'author_profiles.author_id', 'select' => true],
        'bio_text' => ['db' => 'author_profiles.bio_text', 'select' => true],
    ];
}

class CommentModel extends AbstractModel
{
    protected string $table = 'comments';

    protected array $fields = [
        'id' => ['db' => 'comments.id', 'select' => true],
        'post_id' => ['db' => 'comments.post_id', 'select' => true],
        'comment_body' => ['db' => 'comments.comment_body', 'select' => true],
    ];
}

class PostModel extends AbstractModel
{
    protected string $table = 'posts';

    protected array $fields = [
        'id' => ['db' => 'posts.id', 'select' => true],
        'author_id' => ['db' => 'posts.author_id', 'select' => true],
        'post_title' => ['db' => 'posts.post_title', 'select' => true],
        'views_count' => ['db' => 'posts.views_count', 'select' => true],
    ];

    protected array $relationships = [
        'comments' => [
            'model' => CommentModel::class,
            'localKey' => 'id',
            'foreignKey' => 'post_id',
            'many' => true,
        ],
    ];
}

class AuthorModel extends AbstractModel
{
    protected string $table = 'authors';

    protected array $fields = [
        'id' => ['db' => 'authors.id', 'select' => true],
        'full_name' => ['db' => 'authors.full_name', 'select' => true],
        'email_address' => ['db' => 'authors.email_address', 'select' => true],
        'status_code' => ['db' => 'authors.status_code', 'select' => true],
        'secret_token' => ['db' => 'authors.secret_token', 'select' => false],
    ];

    protected array $relationships = [
        'profile' => [
            'model' => AuthorProfileModel::class,
            'localKey' => 'id',
            'foreignKey' => 'author_id',
            'many' => false,
        ],
        'posts' => [
            'model' => PostModel::class,
            'localKey' => 'id',
            'foreignKey' => 'author_id',
            'many' => true,
        ],
    ];

    /** Expõe customQuery (protegido) para asserções diretas da suíte. */
    public function rawQuery(string $sql, array $params = []): array|bool
    {
        return $this->customQuery($sql, $params);
    }

    public function setupSchema(): void
    {
        $this->customQuery('CREATE TABLE authors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email_address TEXT UNIQUE,
            status_code TEXT NOT NULL DEFAULT "active",
            secret_token TEXT
        )');
        $this->customQuery('CREATE TABLE author_profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER NOT NULL,
            bio_text TEXT NOT NULL
        )');
        $this->customQuery('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER NOT NULL,
            post_title TEXT NOT NULL,
            views_count INTEGER NOT NULL DEFAULT 0
        )');
        $this->customQuery('CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            comment_body TEXT NOT NULL
        )');
    }
}

$authors = new AuthorModel();
$authors->setupSchema();

// 1. Insert & Upsert
$anaId = $authors->insert([
    'full_name' => 'Ana Silva',
    'email_address' => 'ana@lumy.dev',
    'status_code' => 'active',
    'secret_token' => 'tok_123',
]);
$brunoId = $authors->insert([
    'full_name' => 'Bruno Costa',
    'email_address' => null,
    'status_code' => 'pending',
    'secret_token' => 'tok_456',
]);

$authors->upsert(
    [
        'full_name' => 'Ana Maria Silva',
        'email_address' => 'ana@lumy.dev',
        'status_code' => 'active',
    ],
    ['email_address']
);

// 2. Relacionamentos múltiplos (1:1 + 1:N + aninhado) e camelCaseTransform
(new AuthorProfileModel())->insert(['author_id' => $anaId, 'bio_text' => 'Arquiteta de Software']);
$post1Id = (new PostModel())->insert(['author_id' => $anaId, 'post_title' => 'Guia Lumy ORM', 'views_count' => 120]);
(new CommentModel())->insert(['post_id' => $post1Id, 'comment_body' => 'Excelente artigo!']);

$result = $authors->select(
    filters: ['id' => $anaId],
    camelCaseTransform: true,
    with: [
        'profile',
        'posts' => ['comments'],
    ]
);

assertSameValue('Ana Maria Silva', $result['data'][0]['fullName'], 'Upsert + camelCaseTransform deve atualizar e converter fullName');
assertSameValue('Arquiteta de Software', $result['data'][0]['profile']['bioText'], 'Relacionamento 1:1 deve coexistir com 1:N na mesma localKey');
assertSameValue('Excelente artigo!', $result['data'][0]['posts'][0]['comments'][0]['commentBody'], 'Relacionamento aninhado deve carregar comments');
assertSameValue(false, isset($result['data'][0]['posts'][0]['_LumyForeignPosts']), 'Chave interna de relacionamento nao pode vazar com camelCaseTransform');
assertSameValue(
    [
        'currentPage' => 1,
        'previousPage' => 1,
        'perPage' => 100,
        'nextPage' => 1,
        'totalRecords' => 1,
        'totalPages' => 1,
    ],
    $result['meta'],
    'select() sem paginacao deve usar padrao perPage=100 e currentPage=1 no meta'
);

// 3. Filtros (LIKE, NOT LIKE, NULL, IN, BETWEEN, OR)
$likeRows = $authors->select(filters: ['fullName LIKE' => 'Maria'], camelCaseTransform: true);
assertSameValue(1, count($likeRows['data']), 'Filtro LIKE deve encontrar Ana Maria');

$notLikeRows = $authors->select(filters: ['!fullName LIKE' => 'Maria'], camelCaseTransform: true);
assertSameValue(1, count($notLikeRows['data']), 'Filtro !LIKE deve encontrar Bruno');

$nullRows = $authors->select(filters: ['emailAddress' => null], camelCaseTransform: true);
assertSameValue($brunoId, $nullRows['data'][0]['id'], 'Filtro IS NULL deve encontrar Bruno');

// 4. Transações via método e via função global transaction($transaction, $rollback)
assertThrows(RuntimeException::class, function () use ($authors): void {
    transaction(function () use ($authors): void {
        $authors->insert([
            'full_name' => 'Usuario Rollback Padrao',
            'email_address' => 'rollback_default@lumy.dev',
            'status_code' => 'temp',
        ]);
        throw new RuntimeException('Simulando falha sem callback de rollback');
    });
}, 'transaction() sem callback de rollback deve fazer rollback automatico e relancar a excecao');

$afterDefaultRollback = (new Model('authors'))->get(['email_address' => 'rollback_default@lumy.dev']);
assertSameValue(null, $afterDefaultRollback, 'Rollback padrao deve desfazer o insert');

// Testando funcao global transaction() COM callback de rollback definido pelo usuario
$customRollbackExecuted = false;
$customReturn = transaction(
    function () use ($authors): string {
        $authors->insert([
            'full_name' => 'Usuario Rollback Customizado',
            'email_address' => 'rollback_custom@lumy.dev',
            'status_code' => 'temp',
        ]);
        throw new InvalidArgumentException('Erro capturado pelo rollback do usuario');
    },
    function (Throwable $e) use (&$customRollbackExecuted, $authors): string {
        $customRollbackExecuted = true;
        // O usuario tambem pode gravar log ou executar acoes compensatorias no banco!
        $authors->insert([
            'full_name' => 'Log de Falha',
            'email_address' => 'audit_failure@lumy.dev',
            'status_code' => 'audit',
        ]);

        return 'tratado:' . $e->getMessage();
    }
);

assertSameValue(true, $customRollbackExecuted, 'Callback de rollback customizado deve ser executado');
assertSameValue('tratado:Erro capturado pelo rollback do usuario', $customReturn, 'transaction() deve retornar o valor do callback de rollback');
assertSameValue(null, (new Model('authors'))->get(['email_address' => 'rollback_custom@lumy.dev']), 'Insert da transacao falha deve ter sofrido rollback');
assertSameValue('Log de Falha', (new Model('authors'))->get(['email_address' => 'audit_failure@lumy.dev'])['full_name'] ?? null, 'Query executada dentro do callback de rollback deve persistir');
$authors->delete(['email_address' => 'audit_failure@lumy.dev']);

// 5. Segurança: bloqueio de SQL Injection em identificadores e bypass de mapa
assertThrows(InvalidArgumentException::class, function () use ($authors): void {
    $authors->select(filters: ['authors.non_mapped_column' => 'x']);
}, 'Coluna qualificada fora do mapa $fields deve ser bloqueada');

assertThrows(InvalidArgumentException::class, function () use ($authors): void {
    $authors->insert(['full_name; DROP TABLE authors;--' => 'Hack']);
}, 'Identificador de coluna invalido deve ser bloqueado');

// 6. LIKE literal: curingas digitados pelo usuario devem casar literalmente
$underscoreRows = $authors->select(filters: ['fullName LIKE' => 'A_a'], camelCaseTransform: true);
assertSameValue(0, count($underscoreRows['data']), 'Underscore literal nao pode casar "Ana" (bug de escape)');

$wildcardRows = $authors->select(filters: ['fullName LIKE' => '%Maria%'], camelCaseTransform: true);
assertSameValue(1, count($wildcardRows['data']), 'Coringa % digitado pelo usuario ainda deve funcionar');

// 7. DateTimeInterface no binding nao pode causar fatal
$ts = $authors->rawQuery('SELECT ? AS ts', [new DateTimeImmutable('2026-10-19 12:00:00')]);
assertSameValue('2026-10-19 12:00:00', $ts[0]['ts'], 'DateTimeInterface deve ser formatado no binding');

// 8. Parametros nomeados (com e sem prefixo ":")
$named = $authors->rawQuery(
    'SELECT full_name FROM authors WHERE email_address = :email',
    ['email' => 'ana@lumy.dev']
);
assertSameValue('Ana Maria Silva', $named[0]['full_name'], 'Parametro nomeado sem ":" no array deve ser aceito');

$namedColon = $authors->rawQuery(
    'SELECT full_name FROM authors WHERE email_address = :email',
    [':email' => 'ana@lumy.dev']
);
assertSameValue('Ana Maria Silva', $namedColon[0]['full_name'], 'Parametro nomeado com ":" no array deve ser aceito');

assertThrows(InvalidArgumentException::class, function () use ($authors): void {
    $authors->rawQuery('SELECT ? AS a', ['missing' => 1]);
}, 'Chave de parametro nomeado ausente na query deve ter erro claro');

// 9. Transacoes aninhadas via savepoint
$nestedOuter = null;
transaction(function () use ($authors, &$nestedOuter): void {
    $authors->insert([
        'full_name' => 'Outer Commit',
        'email_address' => 'outer@lumy.dev',
        'status_code' => 'active',
    ]);
    transaction(
        function () use ($authors): void {
            $authors->insert([
                'full_name' => 'Inner Rollback',
                'email_address' => 'inner@lumy.dev',
                'status_code' => 'temp',
            ]);
            throw new RuntimeException('Reverte apenas o savepoint interno');
        },
        function (Throwable $e) use (&$nestedOuter): void {
            $nestedOuter = 'inner_handled:' . $e->getMessage();
        }
    );
});
assertSameValue('inner_handled:Reverte apenas o savepoint interno', $nestedOuter, 'Erro no savepoint interno deve ser tratado pelo rollback interno sem abortar a transacao externa');

$outerRow = (new Model('authors'))->get(['email_address' => 'outer@lumy.dev']);
$innerRow = (new Model('authors'))->get(['email_address' => 'inner@lumy.dev']);
assertSameValue(true, is_array($outerRow), 'Commit externo deve persistir o insert externo');
assertSameValue(null, $innerRow, 'Rollback do savepoint interno deve desfazer o insert interno');

// 10. Paginacao deterministica com campos mapeados e meta padronizado
$page1 = $authors->select(orderBy: 'id ASC', offset: 0, limit: 2);
$page2 = $authors->select(orderBy: 'id ASC', page: 2, perPage: 2);
assertSameValue(true, count($page1['data']) === 2, 'Pagina 1 deve retornar 2 registros');
assertSameValue(
    [
        'currentPage' => 1,
        'previousPage' => 1,
        'perPage' => 2,
        'nextPage' => 2,
        'totalRecords' => 3,
        'totalPages' => 2,
    ],
    $page1['meta'],
    'Meta da pagina 1 deve calcular currentPage, previousPage, perPage, nextPage, totalRecords e totalPages'
);
assertSameValue(
    [
        'currentPage' => 2,
        'previousPage' => 1,
        'perPage' => 2,
        'nextPage' => 2,
        'totalRecords' => 3,
        'totalPages' => 2,
    ],
    $page2['meta'],
    'Meta da pagina 2 (ultima pagina) deve calcular currentPage=2, previousPage=1 e nextPage=2'
);
assertSameValue(
    [],
    array_intersect(
        array_column($page1['data'], 'id'),
        array_column($page2['data'], 'id')
    ),
    'Paginas consecutivas nao podem se sobrepor'
);

// 11. Migrations em SQLite: executa pendentes e ignora aplicadas
$migrationsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lumy_suite_migrations_' . getmypid();
@mkdir($migrationsDir);
file_put_contents(
    $migrationsDir . DIRECTORY_SEPARATOR . '001_create_users.sql',
    "CREATE TABLE IF NOT EXISTS suite_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL);"
);

$firstRun = (new Migrations('default', $migrationsDir))->run();
assertSameValue(['001_create_users.sql'], $firstRun, 'Primeira execucao deve aplicar a migration pendente');

$secondRun = (new Migrations('default', $migrationsDir))->run();
assertSameValue([], $secondRun, 'Segunda execucao nao deve reaplicar migrations ja registradas');

$userCount = $authors->rawQuery('SELECT COUNT(*) AS total FROM suite_users');
assertSameValue(0, (int) $userCount[0]['total'], 'Tabela criada pela migration deve existir e estar vazia');

// 11.1. CLI bin/migrate.php: Auto-discovery das Formas A, B, C, D e E do README
$migrateBin = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'migrate.php';
$runCliMigrate = static function (string $connId, string $bootstrapPath) use ($migrateBin, $migrationsDir): array {
    $cmd = sprintf(
        '"%s" -d extension=pdo_sqlite "%s" %s "%s" "%s" 2>&1',
        PHP_BINARY,
        $migrateBin,
        $connId,
        $bootstrapPath,
        $migrationsDir
    );
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);

    return [$code, implode("\n", $output)];
};

$cfgFiles = [
    'cli_a' => [$migrationsDir . '/cfg_a.php', "<?php\nuse Lumynus\\Lumy\\Config;\nConfig::setConnection('cli_a', driver: 'sqlite', database: ':memory:');\n"],
    'cli_b' => [$migrationsDir . '/cfg_b.php', "<?php\nuse Lumynus\\Lumy\\AbstractModel;\nuse Lumynus\\Lumy\\Config;\nnew Config(driver: 'sqlite', database: ':memory:', connectionIdentifier: 'cli_b');\nclass SuiteCliModelB extends AbstractModel { protected string \$table = 'suite_users'; }\n"],
    'cli_c' => [$migrationsDir . '/cfg_c.php', "<?php\nuse Lumynus\\Lumy\\AbstractModel;\nuse Lumynus\\Lumy\\Config;\nabstract class SuiteCliBaseModelC extends AbstractModel {\n    public function __construct(string \$id = 'cli_c') {\n        new Config(driver: 'sqlite', database: ':memory:', connectionIdentifier: 'cli_c');\n        parent::__construct(\$id);\n    }\n}\n"],
    'cli_d' => [$migrationsDir . '/cfg_d.php', "<?php\nuse Lumynus\\Lumy\\Config;\nclass SuiteCliDatabaseD {\n    public static function init(): void {\n        Config::setConnection('cli_d', driver: 'sqlite', database: ':memory:');\n    }\n}\n"],
    'cli_e' => [$migrationsDir . '/cfg_e.php', "<?php\nuse Lumynus\\Lumy\\Config;\nreturn static function (): void {\n    Config::setConnection('cli_e', driver: 'sqlite', database: ':memory:');\n};\n"],
];

foreach ($cfgFiles as $connId => [$cfgPath, $cfgCode]) {
    file_put_contents($cfgPath, $cfgCode);
    [$exitCode, $cliOut] = $runCliMigrate($connId, $cfgPath);
    assertSameValue(0, $exitCode, "CLI migrate deve ter sucesso para {$connId}");
    assertSameValue(true, str_contains($cliOut, 'Applied: 001_create_users.sql'), "CLI migrate deve aplicar 001_create_users.sql para {$connId}");
    @unlink($cfgPath);
}

array_map('unlink', glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql') ?: []);
@rmdir($migrationsDir);

// 12. Verificacao dos 5 dialetos de bancos suportados (SQLite, MySQL, PostgreSQL, SQL Server, Firebird)
$buildDsnMethod = new ReflectionMethod(PdoDriver::class, 'buildDsn');

assertSameValue(
    'sqlite::memory:',
    $buildDsnMethod->invoke(null, 'sqlite', '', ':memory:', []),
    'DSN SQLite deve ser gerado corretamente'
);
assertSameValue(
    'mysql:host=127.0.0.1;port=3307;dbname=app_db;charset=utf8mb4',
    $buildDsnMethod->invoke(null, 'mysql', '127.0.0.1:3307', 'app_db', []),
    'DSN MySQL com porta deve ser gerado corretamente'
);
assertSameValue(
    'pgsql:host=db.internal;port=5433;dbname=analytics;sslmode=require',
    $buildDsnMethod->invoke(null, 'postgresql', 'db.internal:5433', 'analytics', ['sslmode' => 'require']),
    'DSN PostgreSQL com porta e sslmode deve ser gerado corretamente'
);
assertSameValue(
    'sqlsrv:Server=10.0.0.5,1433;Database=erp_db',
    $buildDsnMethod->invoke(null, 'sqlserver', '10.0.0.5:1433', 'erp_db', []),
    'DSN SQL Server com porta deve converter ":" para ","'
);
assertSameValue(
    'firebird:dbname=127.0.0.1/3050:/var/firebird/app.fdb;charset=UTF8',
    $buildDsnMethod->invoke(null, 'firebird', '127.0.0.1:3050', '/var/firebird/app.fdb', []),
    'DSN Firebird com host:porta deve converter para host/porta:database'
);

// Verifica geracao de SQL de paginacao, COUNT(*), filtros e ORDER BY em cada dialeto (mysql, pgsql, sqlsrv, firebird, sqlite)
class DialectRecorderDriver implements DatabaseDriverInterface
{
    public string $lastQuery = '';
    /** @var list<mixed> */
    public array $lastParams = [];
    /** @var list<array{query: string, params: list<mixed>}> */
    public array $history = [];

    public function __construct(private string $driverName, private int $simulatedTotal = 25)
    {
    }

    public function query(string $query, array $params = [], string|array $types = ''): array|bool
    {
        $this->lastQuery = $query;
        $this->lastParams = $params;
        $this->history[] = ['query' => $query, 'params' => $params];

        if (str_contains($query, 'lumy_total_count')) {
            return [['lumy_total_count' => $this->simulatedTotal]];
        }

        return [];
    }

    public function upsert(string $table, array $data, array $uniqueBy): bool
    {
        return true;
    }

    public function getDriverName(): string
    {
        return $this->driverName;
    }

    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
    public function getInsertId(): int { return 1; }
    public function getAffectedRows(): int { return 1; }
    public function getThreadId(): ?int { return 1; }
}

/**
 * @return array{0: string, 1: list<mixed>, 2: list<array{query: string, params: list<mixed>}>, 3: mixed}
 */
function inspectDialectQuery(string $driverName, callable $action, int $simulatedTotal = 25): array
{
    $recorder = new DialectRecorderDriver($driverName, $simulatedTotal);
    $model = new Model('items');
    $prop = new ReflectionProperty(Lumynus\Lumy\AbstractLumy::class, 'modelConnection');
    $prop->setValue($model, $recorder);
    $result = $action($model);

    return [$recorder->lastQuery, $recorder->lastParams, $recorder->history, $result];
}

[$sqlSrvQuery, , $sqlSrvHistory, $sqlSrvResult] = inspectDialectQuery(
    'sqlsrv',
    static fn (Model $m) => $m->select(
        filters: ['status' => 'active', 'price >=' => 100],
        orderBy: ['created_at' => 'DESC', 'id' => 'ASC'],
        page: 3,
        perPage: 10
    ),
    25
);
assertSameValue(
    'SELECT COUNT(*) AS lumy_total_count FROM items WHERE status = ? AND price >= ?',
    $sqlSrvHistory[0]['query'],
    'SQL Server COUNT(*) nao deve incluir ORDER BY e deve aplicar os mesmos filtros WHERE'
);
assertSameValue(
    ['active', 100],
    $sqlSrvHistory[0]['params'],
    'SQL Server COUNT(*) deve receber os parametros do filtro'
);
assertSameValue(
    'SELECT * FROM items WHERE status = ? AND price >= ? ORDER BY created_at DESC, id ASC OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY',
    $sqlSrvQuery,
    'SQL Server deve paginar com WHERE + ORDER BY + OFFSET ... ROWS FETCH NEXT ... ROWS ONLY'
);
assertSameValue(
    [
        'currentPage'  => 3,
        'previousPage' => 2,
        'perPage'      => 10,
        'nextPage'     => 3,
        'totalRecords' => 25,
        'totalPages'   => 3,
    ],
    $sqlSrvResult['meta'],
    'SQL Server deve calcular meta de paginacao corretamente'
);

[$firebirdQuery, , $firebirdHistory] = inspectDialectQuery(
    'firebird',
    static fn (Model $m) => $m->select(
        filters: ['status' => 'active'],
        orderBy: 'name ASC',
        offset: 10,
        limit: 5
    )
);
assertSameValue(
    'SELECT COUNT(*) AS lumy_total_count FROM items WHERE status = ?',
    $firebirdHistory[0]['query'],
    'Firebird COUNT(*) deve usar alias iniciado por letra (lumy_total_count) e aplicar WHERE'
);
assertSameValue(
    'SELECT * FROM items WHERE status = ? ORDER BY name ASC ROWS 11 TO 15',
    $firebirdQuery,
    'Firebird deve paginar com WHERE + ORDER BY + ROWS m TO n'
);

[$mysqlQuery, , $mysqlHistory] = inspectDialectQuery(
    'mysql',
    static fn (Model $m) => $m->select(
        filters: ['name LIKE' => '50%'],
        orderBy: 'id DESC',
        offset: 0,
        limit: 5
    )
);
assertSameValue(
    "SELECT COUNT(*) AS lumy_total_count FROM items WHERE name LIKE ? ESCAPE '\\\\'",
    $mysqlHistory[0]['query'],
    'MySQL COUNT(*) deve aplicar o mesmo ESCAPE dobrado do SELECT'
);
assertSameValue(
    "SELECT * FROM items WHERE name LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 5 OFFSET 0",
    $mysqlQuery,
    'MySQL deve usar ESCAPE dobrado, ORDER BY e LIMIT/OFFSET'
);

[$pgsqlQuery, , $pgsqlHistory] = inspectDialectQuery(
    'pgsql',
    static fn (Model $m) => $m->select(
        filters: ['name LIKE' => '50%'],
        orderBy: ['name' => 'ASC'],
        offset: 0,
        limit: 5
    )
);
assertSameValue(
    "SELECT COUNT(*) AS lumy_total_count FROM items WHERE name LIKE ? ESCAPE '\\'",
    $pgsqlHistory[0]['query'],
    'PostgreSQL COUNT(*) deve aplicar o mesmo ESCAPE simples do SELECT'
);
assertSameValue(
    "SELECT * FROM items WHERE name LIKE ? ESCAPE '\\' ORDER BY name ASC LIMIT 5 OFFSET 0",
    $pgsqlQuery,
    'PostgreSQL deve usar ESCAPE simples, ORDER BY e LIMIT/OFFSET'
);

// Teste de GROUP BY + filtro de agregacao (HAVING) + ORDER BY em coluna agregada no SQLite real
class AuthorPostStatsModel extends AbstractModel
{
    protected string $table = 'authors';
    protected string $alias = 'a';
    protected ?array $joins = [
        'INNER JOIN posts p ON p.author_id = a.id',
    ];
    protected array $groupBy = ['a.id', 'a.full_name'];
    protected array $fields = [
        'author_id'   => ['db' => 'a.id', 'select' => true],
        'full_name'   => ['db' => 'a.full_name', 'select' => true],
        'total_posts' => ['db' => 'COUNT(p.id)', 'select' => true],
        'total_views' => ['db' => 'SUM(p.views_count)', 'select' => true],
    ];
}

(new PostModel())->insert(['author_id' => $anaId, 'post_title' => 'Segundo Post', 'views_count' => 80]);
(new PostModel())->insert(['author_id' => $brunoId, 'post_title' => 'Post do Bruno', 'views_count' => 30]);

$statsModel = new AuthorPostStatsModel();
$groupedFiltered = $statsModel->select(
    filters: ['total_posts >=' => 2],
    orderBy: 'total_views DESC',
    camelCaseTransform: true
);
assertSameValue(1, count($groupedFiltered['data']), 'Filtro em campo agregado (COUNT/SUM) deve ir para HAVING e filtrar grupos');
assertSameValue(1, $groupedFiltered['meta']['totalRecords'], 'COUNT(*) com GROUP BY + HAVING deve contar apenas os grupos filtrados');
assertSameValue('Ana Maria Silva', $groupedFiltered['data'][0]['fullName'], 'Grupo retornado pelo HAVING deve ser Ana Maria Silva (2 posts)');
assertSameValue(200, (int) $groupedFiltered['data'][0]['totalViews'], 'Soma de views da Ana deve ser 200');

echo "Todos os testes do Lumy (incluindo os 5 dialetos e transaction global) executados com sucesso!" . PHP_EOL;

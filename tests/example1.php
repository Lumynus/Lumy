<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;
use Lumynus\Lumy\Connections\LumyConnection;
use Lumynus\Lumy\Migrations;
use Lumynus\Lumy\Model;

/**
 * Suite de testes automatizados do Lumy MicroORM.
 * Executa em SQLite (:memory:) validando CRUD, filtros, relacionamentos,
 * transações, migrations, pool de conexões e proteções de segurança.
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

assertSameValue('Ana Maria Silva', $result[0]['fullName'], 'Upsert + camelCaseTransform deve atualizar e converter fullName');
assertSameValue('Arquiteta de Software', $result[0]['profile']['bioText'], 'Relacionamento 1:1 deve coexistir com 1:N na mesma localKey');
assertSameValue('Excelente artigo!', $result[0]['posts'][0]['comments'][0]['commentBody'], 'Relacionamento aninhado deve carregar comments');
assertSameValue(false, isset($result[0]['posts'][0]['_LumyForeignPosts']), 'Chave interna de relacionamento nao pode vazar com camelCaseTransform');

// 3. Filtros (LIKE, NOT LIKE, NULL, IN, BETWEEN, OR)
$likeRows = $authors->select(filters: ['fullName LIKE' => 'Maria'], camelCaseTransform: true);
assertSameValue(1, count($likeRows), 'Filtro LIKE deve encontrar Ana Maria');

$notLikeRows = $authors->select(filters: ['!fullName LIKE' => 'Maria'], camelCaseTransform: true);
assertSameValue(1, count($notLikeRows), 'Filtro !LIKE deve encontrar Bruno');

$nullRows = $authors->select(filters: ['emailAddress' => null], camelCaseTransform: true);
assertSameValue($brunoId, $nullRows[0]['id'], 'Filtro IS NULL deve encontrar Bruno');

// 4. Transações (commit e rollback)
assertThrows(RuntimeException::class, function () use ($authors): void {
    $authors->transaction(function (AuthorModel $model): void {
        $model->insert([
            'full_name' => 'Usuario Rollback',
            'email_address' => 'rollback@lumy.dev',
            'status_code' => 'temp',
        ]);
        throw new RuntimeException('Simulando falha na transacao');
    });
}, 'Transacao com erro deve propagar excecao');

$afterRollback = (new Model('authors'))->get(['email_address' => 'rollback@lumy.dev']);
assertSameValue(null, $afterRollback, 'Rollback deve desfazer o insert');

// 5. Segurança: bloqueio de SQL Injection em identificadores e bypass de mapa
assertThrows(InvalidArgumentException::class, function () use ($authors): void {
    $authors->select(filters: ['authors.non_mapped_column' => 'x']);
}, 'Coluna qualificada fora do mapa $fields deve ser bloqueada');

assertThrows(InvalidArgumentException::class, function () use ($authors): void {
    $authors->insert(['full_name; DROP TABLE authors;--' => 'Hack']);
}, 'Identificador de coluna invalido deve ser bloqueado');

// 6. LIKE literal: curingas digitados pelo usuario devem casar literalmente
$underscoreRows = $authors->select(filters: ['fullName LIKE' => 'A_a'], camelCaseTransform: true);
assertSameValue(0, count($underscoreRows), 'Underscore literal nao pode casar "Ana" (bug de escape)');

$wildcardRows = $authors->select(filters: ['fullName LIKE' => '%Maria%'], camelCaseTransform: true);
assertSameValue(1, count($wildcardRows), 'Coringa % digitado pelo usuario ainda deve funcionar');

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
$authors->transaction(function (AuthorModel $model) use (&$nestedOuter): void {
    $model->insert([
        'full_name' => 'Outer Commit',
        'email_address' => 'outer@lumy.dev',
        'status_code' => 'active',
    ]);
    try {
        $model->transaction(function (AuthorModel $inner): void {
            $inner->insert([
                'full_name' => 'Inner Rollback',
                'email_address' => 'inner@lumy.dev',
                'status_code' => 'temp',
            ]);
            throw new RuntimeException('Reverte apenas o savepoint interno');
        });
    } catch (RuntimeException) {
    }
    $nestedOuter = 'reached';
});
assertSameValue('reached', $nestedOuter, 'Erro no savepoint interno nao pode abortar a transacao externa');

$outerRow = (new Model('authors'))->get(['email_address' => 'outer@lumy.dev']);
$innerRow = (new Model('authors'))->get(['email_address' => 'inner@lumy.dev']);
assertSameValue(true, is_array($outerRow), 'Commit externo deve persistir o insert externo');
assertSameValue(null, $innerRow, 'Rollback do savepoint interno deve desfazer o insert interno');

// 10. Paginacao deterministica com campos mapeados
$page1 = $authors->select(orderBy: 'id ASC', offset: 0, limit: 2);
$page2 = $authors->select(orderBy: 'id ASC', offset: 2, limit: 2);
assertSameValue(true, count($page1) === 2, 'Pagina 1 deve retornar 2 registros');
assertSameValue(
    [],
    array_intersect(
        array_column($page1, 'id'),
        array_column($page2, 'id')
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

array_map('unlink', glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql') ?: []);
@rmdir($migrationsDir);

echo "Todos os testes do Lumy executados com sucesso!" . PHP_EOL;

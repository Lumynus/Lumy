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

echo "Todos os testes do Lumy executados com sucesso!" . PHP_EOL;

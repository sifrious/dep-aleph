<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;

it('builds PostgreSQL migration SQL with the self foreign key added after table creation', function (): void {
    Connection::resolverFor('pgsql', static function (mixed $connection, string $database, string $prefix, array $config): PostgresConnection {
        $pdo = new PDO('sqlite::memory:');
        $postgres = new PostgresConnection($pdo, $database, $prefix, $config);
        $postgres->setSchemaGrammar(new PostgresGrammar);

        return $postgres;
    });

    $connectionName = 'aleph_pgsql_proof';
    $previousDefault = (string) config('database.default');

    config()->set("database.connections.{$connectionName}", [
        'driver' => 'pgsql',
        'database' => 'aleph',
        'prefix' => '',
    ]);
    config()->set('database.default', $connectionName);
    DB::purge($connectionName);

    try {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_04_100000_create_aleph_bibliographic_catalog.php';
        $queries = DB::connection($connectionName)->pretend(static function () use ($migration): void {
            $migration->up();
        });
    } finally {
        config()->set('database.default', $previousDefault);
        DB::purge($connectionName);
    }

    $sql = array_map(
        static fn (array $query): string => strtolower((string) ($query['query'] ?? '')),
        $queries
    );
    $createIndex = null;
    $alterIndex = null;

    foreach ($sql as $index => $statement) {
        if ($createIndex === null && str_contains($statement, 'create table "aleph_book_files"')) {
            $createIndex = $index;
        }

        if (
            $alterIndex === null
            && str_contains($statement, 'alter table "aleph_book_files"')
            && str_contains($statement, 'references "aleph_book_files" ("id")')
        ) {
            $alterIndex = $index;
        }
    }

    expect($createIndex)->not->toBeNull()
        ->and($alterIndex)->not->toBeNull()
        ->and($alterIndex)->toBeGreaterThan($createIndex)
        ->and($sql[$createIndex])->not->toContain('references "aleph_book_files" ("id")');
});

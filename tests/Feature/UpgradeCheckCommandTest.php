<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('reports a migrated configured package as upgrade ready', function (): void {
    $status = Artisan::call('aleph:upgrade:check', ['--json' => true]);
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(0)
        ->and($result)->toBe([
            'ready' => true,
            'missing_migrations' => [],
            'missing_configuration' => [],
            'connector_violations' => [],
        ]);
});

it('names a package migration that the host has not run', function (): void {
    $migration = '2026_09_02_000000_create_aleph_web_redirect_aliases';
    DB::table('migrations')->where('migration', $migration)->delete();

    $status = Artisan::call('aleph:upgrade:check', ['--json' => true]);
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(1)
        ->and($result['ready'])->toBeFalse()
        ->and($result['missing_migrations'])->toContain($migration);
});

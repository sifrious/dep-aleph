<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Sifrious\Aleph\AlephServiceProvider;

it('registers the service provider', function (): void {
    expect($this->app->getLoadedProviders())->toHaveKey(AlephServiceProvider::class);
});

it('merges the package configuration', function (): void {
    expect(config('aleph'))->toBeArray();
});

it('publishes the package configuration under its own tag', function (): void {
    expect(ServiceProvider::pathsToPublish(AlephServiceProvider::class, 'aleph-config'))->not->toBeEmpty();
});

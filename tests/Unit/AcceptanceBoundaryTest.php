<?php

declare(strict_types=1);

it('lets only the acceptance layer reach Funes persistence', function (): void {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! str_contains($source, 'Sifrious\\Funes\\Persistence')
            && ! str_contains($source, 'Sifrious\\Funes\\Acceptance')) {
            continue;
        }

        $offenders[] = str_replace(dirname(__DIR__, 2).'/src/', '', $file->getPathname());
    }

    sort($offenders);

    expect($offenders)->toBe([
        'Acceptance/AcceptanceClient.php',
        'AlephServiceProvider.php',
        'Envelope/EnvelopeSubmitter.php',
        'Inventory/InventoryReader.php',
        'Web/FunesObservationWriter.php',
    ]);
});

it('keeps connector packages away from Funes persistence entirely', function (): void {
    $connector = dirname(__DIR__, 3).'/aleph-connector-pigeonpost/src';

    if (! is_dir($connector)) {
        expect(true)->toBeTrue();

        return;
    }

    $source = '';

    foreach (glob($connector.'/*.php') ?: [] as $file) {
        $source .= file_get_contents($file);
    }

    expect($source)->not->toContain('Sifrious\\Funes\\Persistence')
        ->and($source)->not->toContain('Sifrious\\Funes\\Acceptance')
        ->and($source)->toContain('Sifrious\\Aleph\\Envelope\\EnvelopeSubmitter');
});

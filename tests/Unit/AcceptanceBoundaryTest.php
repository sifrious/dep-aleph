<?php

declare(strict_types=1);

it('keeps direct Funes dependencies inside reviewed boundary adapters', function (): void {
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
        'Acceptance/Backfill.php',
        'AlephServiceProvider.php',
        'Connector/Handwriting/FunesHandwritingOcrDerivationRecorder.php',
        'Connector/Image/FunesImageClassificationRecorder.php',
        'Connector/Image/FunesImageConversionRecorder.php',
        'Connector/Midi/FunesMidiExtractionRecorder.php',
        'Connector/ScoreTab/FunesScoreTabDerivationRecorder.php',
        'Inventory/InventoryReader.php',
        'Web/FunesObservationWriter.php',
    ]);
});

it('leaves no way to create accepted history except the acceptance gateway', function (): void {
    $callers = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/observations->accept\(|store->accept\(/', $source) !== 1) {
            continue;
        }

        $callers[] = str_replace(dirname(__DIR__, 2).'/src/', '', $file->getPathname());
    }

    expect($callers)->toBe([]);
});

it('keeps historical assertion persistence inside acceptance', function (): void {
    $users = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! str_contains($source, 'HistoricalAssertionStore')) {
            continue;
        }

        $users[] = str_replace(dirname(__DIR__, 2).'/src/', '', $file->getPathname());
    }

    sort($users);

    expect($users)->toBe([
        'Acceptance/HistoricalAssertionAcceptance.php',
        'AlephServiceProvider.php',
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

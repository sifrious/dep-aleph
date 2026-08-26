<?php

declare(strict_types=1);

use Sifrious\Funes\Persistence\ObservationStore;

it('depends on the Funes acceptance contract', function (): void {
    expect(interface_exists(ObservationStore::class))->toBeTrue()
        ->and((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'))->toContain('sifrious/funes');
});

it('keeps Funes independent from Aleph', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/vendor/sifrious/funes/src'));
    $source = '';

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }

    expect($source)->not->toContain('Sifrious\\Aleph');
});

<?php

declare(strict_types=1);

use Sifrious\Aleph\Envelope\ExtensionMetadata;

it('accepts a dotted lowercase namespace with a positive version', function (): void {
    $extension = new ExtensionMetadata('weirdservice.widget', 3, ['colour' => 'teal']);

    expect($extension->toArray())->toBe([
        'namespace' => 'weirdservice.widget',
        'version' => 3,
        'data' => ['colour' => 'teal'],
    ]);
});

it('refuses the reserved aleph and funes root namespaces', function (string $namespace): void {
    new ExtensionMetadata($namespace, 1, []);
})->with(['aleph', 'aleph.something', 'funes', 'funes.core'])
    ->throws(InvalidArgumentException::class, 'reserved root namespace');

it('refuses a malformed namespace', function (string $namespace): void {
    new ExtensionMetadata($namespace, 1, []);
})->with(['Weird', 'weird service', '', 'weird..service', '.weird'])
    ->throws(InvalidArgumentException::class);

it('refuses a version below one', function (): void {
    new ExtensionMetadata('weirdservice.widget', 0, []);
})->throws(InvalidArgumentException::class, 'positive integer');

it('survives a round trip through an array', function (): void {
    $original = new ExtensionMetadata('weirdservice.widget', 2, ['nested' => ['a' => 1]]);
    $restored = ExtensionMetadata::fromArray($original->toArray());

    expect($restored->toArray())->toBe($original->toArray());
});

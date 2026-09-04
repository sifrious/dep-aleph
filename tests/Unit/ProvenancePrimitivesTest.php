<?php

declare(strict_types=1);

use Sifrious\Aleph\Provenance\Placement;
use Sifrious\Aleph\Provenance\PlacementRegion;
use Sifrious\Aleph\Provenance\PlacementRelation;
use Sifrious\Aleph\Provenance\PlacementScope;
use Sifrious\Aleph\Provenance\SourceRange;
use Sifrious\Aleph\Provenance\TextSpan;

it('represents every available source coordinate system', function (array $coordinates, array $expected): void {
    expect((new SourceRange(...$coordinates))->toArray())->toBe($expected);
})->with([
    'bytes only' => [
        [12, 24],
        ['byte' => ['start' => 12, 'end' => 24]],
    ],
    'characters only' => [
        [null, null, 8, 19],
        ['character' => ['start' => 8, 'end' => 19]],
    ],
    'lines only' => [
        [null, null, null, null, 3, 7],
        ['line' => ['start' => 3, 'end' => 7]],
    ],
    'all coordinates' => [
        [12, 30, 8, 23, 3, 4],
        [
            'byte' => ['start' => 12, 'end' => 30],
            'character' => ['start' => 8, 'end' => 23],
            'line' => ['start' => 3, 'end' => 4],
        ],
    ],
    'zero-length offset anchor' => [
        [0, 0, 0, 0],
        [
            'byte' => ['start' => 0, 'end' => 0],
            'character' => ['start' => 0, 'end' => 0],
        ],
    ],
]);

it('rejects incomplete or invalid source coordinates', function (array $coordinates, string $message): void {
    expect(fn (): SourceRange => new SourceRange(...$coordinates))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no coordinates' => [[], 'requires at least one coordinate pair'],
    'byte end absent' => [[0], 'byte start and end coordinates must be provided together'],
    'character start absent' => [[null, null, null, 4], 'character start and end coordinates must be provided together'],
    'line end absent' => [[null, null, null, null, 1], 'line start and end coordinates must be provided together'],
    'negative byte start' => [[-1, 2], 'byte coordinates do not form a valid range'],
    'reversed character range' => [[null, null, 4, 3], 'character coordinates do not form a valid range'],
    'zero-based line' => [[null, null, null, null, 0, 1], 'line coordinates do not form a valid range'],
    'reversed line range' => [[null, null, null, null, 4, 3], 'line coordinates do not form a valid range'],
]);

it('anchors text spans to source ranges and available token boundaries', function (?int $tokenStart, ?int $tokenEnd, array $expected): void {
    $span = new TextSpan(
        sourceRange: new SourceRange(byteStart: 20, byteEnd: 35, lineStart: 2, lineEnd: 2),
        tokenStart: $tokenStart,
        tokenEnd: $tokenEnd,
    );

    expect($span->toArray())->toBe($expected);
})->with([
    'without tokenizer coordinates' => [
        null,
        null,
        [
            'source_range' => [
                'byte' => ['start' => 20, 'end' => 35],
                'line' => ['start' => 2, 'end' => 2],
            ],
        ],
    ],
    'with tokenizer coordinates' => [
        5,
        8,
        [
            'source_range' => [
                'byte' => ['start' => 20, 'end' => 35],
                'line' => ['start' => 2, 'end' => 2],
            ],
            'token' => ['start' => 5, 'end' => 8],
        ],
    ],
]);

it('rejects incomplete or invalid token boundaries', function (?int $start, ?int $end, string $message): void {
    $range = new SourceRange(characterStart: 0, characterEnd: 4);

    expect(fn (): TextSpan => new TextSpan($range, $start, $end))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'token end absent' => [0, null, 'must be provided together'],
    'token start absent' => [null, 1, 'must be provided together'],
    'negative token start' => [-1, 1, 'do not form a valid range'],
    'reversed token range' => [3, 2, 'do not form a valid range'],
]);

it('classifies normalized placement using thirds', function (float $position, PlacementRegion $region): void {
    expect(new Placement(PlacementScope::Document, 1, $position))
        ->region()->toBe($region);
})->with([
    'start' => [0.0, PlacementRegion::Beginning],
    'first third boundary' => [1 / 3, PlacementRegion::Beginning],
    'middle' => [0.5, PlacementRegion::Middle],
    'last third boundary' => [2 / 3, PlacementRegion::End],
    'end' => [1.0, PlacementRegion::End],
]);

it('normalizes one-based ordinals in every placement scope', function (PlacementScope $scope, int $ordinal, int $total, float $expected): void {
    $placement = Placement::fromOrdinal($scope, $ordinal, $total);

    expect($placement->scope)->toBe($scope)
        ->and($placement->ordinal)->toBe($ordinal)
        ->and($placement->normalizedPosition)->toBe($expected);
})->with([
    'sentence start' => [PlacementScope::Sentence, 1, 5, 0.0],
    'paragraph midpoint' => [PlacementScope::Paragraph, 3, 5, 0.5],
    'section end' => [PlacementScope::Section, 5, 5, 1.0],
    'singleton document is neutral' => [PlacementScope::Document, 1, 1, 0.5],
]);

it('serializes placement without an artifact or textual-unit identity', function (PlacementScope $scope, array $expected): void {
    expect((new Placement($scope, 4, 0.75))->toArray())->toBe($expected);
})->with([
    'document placement' => [
        PlacementScope::Document,
        [
            'scope' => 'document',
            'absolute_ordinal' => 4,
            'normalized_position' => 0.75,
            'region' => 'end',
        ],
    ],
]);

it('compares placement direction and distance within a scope', function (
    int $leftOrdinal,
    float $leftPosition,
    int $rightOrdinal,
    float $rightPosition,
    PlacementRelation $relation,
    int $ordinalDistance,
    float $normalizedDistance,
): void {
    $left = new Placement(PlacementScope::Paragraph, $leftOrdinal, $leftPosition);
    $right = new Placement(PlacementScope::Paragraph, $rightOrdinal, $rightPosition);

    expect($left->relationTo($right))->toBe($relation)
        ->and($left->ordinalDistanceTo($right))->toBe($ordinalDistance)
        ->and($left->normalizedDistanceTo($right))->toBe($normalizedDistance);
})->with([
    'preceding' => [2, 0.25, 5, 1.0, PlacementRelation::Preceding, 3, 0.75],
    'same' => [3, 0.5, 3, 0.5, PlacementRelation::Same, 0, 0.0],
    'following' => [5, 1.0, 2, 0.25, PlacementRelation::Following, 3, 0.75],
]);

it('rejects invalid placement values', function (Closure $construct, string $message): void {
    expect($construct)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero ordinal' => [
        fn (): Placement => new Placement(PlacementScope::Sentence, 0, 0.0),
        'ordinal must be at least one',
    ],
    'negative normalized position' => [
        fn (): Placement => new Placement(PlacementScope::Sentence, 1, -0.1),
        'must be between zero and one',
    ],
    'normalized position above one' => [
        fn (): Placement => new Placement(PlacementScope::Sentence, 1, 1.1),
        'must be between zero and one',
    ],
    'non-finite normalized position' => [
        fn (): Placement => new Placement(PlacementScope::Sentence, 1, INF),
        'must be between zero and one',
    ],
    'ordinal exceeds total' => [
        fn (): Placement => Placement::fromOrdinal(PlacementScope::Sentence, 4, 3),
        'must fall within the supplied total',
    ],
    'empty total' => [
        fn (): Placement => Placement::fromOrdinal(PlacementScope::Sentence, 1, 0),
        'must fall within the supplied total',
    ],
]);

it('rejects comparisons across placement scopes', function (string $operation): void {
    $sentence = new Placement(PlacementScope::Sentence, 1, 0.0);
    $paragraph = new Placement(PlacementScope::Paragraph, 1, 0.0);

    expect(fn (): mixed => $sentence->{$operation}($paragraph))
        ->toThrow(InvalidArgumentException::class, 'within the same scope');
})->with([
    'relation' => ['relationTo'],
    'ordinal distance' => ['ordinalDistanceTo'],
    'normalized distance' => ['normalizedDistanceTo'],
]);

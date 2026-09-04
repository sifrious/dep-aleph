<?php

declare(strict_types=1);

use Sifrious\Aleph\Provenance\Placement;
use Sifrious\Aleph\Provenance\PlacementFrame;
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

it('preserves producer-supplied placement classifications without inventing boundary policy', function (int $ordinal, PlacementRegion $region): void {
    $placement = Placement::at(new PlacementFrame(PlacementScope::Document, 3), $ordinal, $region);

    expect($placement->region)->toBe($region);
})->with([
    'beginning' => [1, PlacementRegion::Beginning],
    'middle' => [2, PlacementRegion::Middle],
    'end' => [3, PlacementRegion::End],
]);

it('normalizes one-based ordinals in every placement scope', function (PlacementScope $scope, int $ordinal, int $total, ?float $expected): void {
    $frame = new PlacementFrame($scope, $total);
    $placement = Placement::at($frame, $ordinal, PlacementRegion::Middle);

    expect($placement->frame)->toBe($frame)
        ->and($placement->ordinal)->toBe($ordinal)
        ->and($placement->normalizedPosition)->toBe($expected);
})->with([
    'sentence start' => [PlacementScope::Sentence, 1, 5, 0.0],
    'paragraph midpoint' => [PlacementScope::Paragraph, 3, 5, 0.5],
    'section end' => [PlacementScope::Section, 5, 5, 1.0],
    'singleton document has no relative position' => [PlacementScope::Document, 1, 1, null],
]);

it('serializes placement without an artifact or textual-unit identity', function (PlacementFrame $frame, int $ordinal, PlacementRegion $region, array $expected): void {
    expect(Placement::at($frame, $ordinal, $region)->toArray())->toBe($expected);
})->with([
    'document placement' => [
        new PlacementFrame(PlacementScope::Document, 5),
        4,
        PlacementRegion::End,
        [
            'scope' => 'document',
            'absolute_ordinal' => 4,
            'normalized_position' => 0.75,
            'region' => 'end',
        ],
    ],
    'singleton omits undefined normalized position' => [
        new PlacementFrame(PlacementScope::Sentence, 1),
        1,
        PlacementRegion::Beginning,
        [
            'scope' => 'sentence',
            'absolute_ordinal' => 1,
            'region' => 'beginning',
        ],
    ],
]);

it('compares placement direction and distance within a scope', function (
    int $leftOrdinal,
    int $rightOrdinal,
    PlacementRelation $relation,
    int $ordinalDistance,
    ?float $normalizedDistance,
): void {
    $frame = new PlacementFrame(PlacementScope::Paragraph, 5);
    $left = Placement::at($frame, $leftOrdinal, PlacementRegion::Middle);
    $right = Placement::at($frame, $rightOrdinal, PlacementRegion::Middle);

    expect($left->relationTo($right))->toBe($relation)
        ->and($left->ordinalDistanceTo($right))->toBe($ordinalDistance)
        ->and($left->normalizedDistanceTo($right))->toBe($normalizedDistance);
})->with([
    'preceding' => [2, 5, PlacementRelation::Preceding, 3, 0.75],
    'same' => [3, 3, PlacementRelation::Same, 0, 0.0],
    'following' => [5, 2, PlacementRelation::Following, 3, 0.75],
]);

it('keeps relative distance undefined for a singleton frame', function (PlacementScope $scope): void {
    $frame = new PlacementFrame($scope, 1);
    $placement = Placement::at($frame, 1, PlacementRegion::Beginning);

    expect($placement->normalizedDistanceTo($placement))->toBeNull()
        ->and($placement->ordinalDistanceTo($placement))->toBe(0);
})->with([
    'singleton sentence' => [PlacementScope::Sentence],
]);

it('rejects invalid placement values', function (Closure $construct, string $message): void {
    expect($construct)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty frame' => [
        fn (): PlacementFrame => new PlacementFrame(PlacementScope::Sentence, 0),
        'must contain at least one position',
    ],
    'zero ordinal' => [
        fn (): Placement => Placement::at(
            new PlacementFrame(PlacementScope::Sentence, 3),
            0,
            PlacementRegion::Beginning,
        ),
        'must fall within its frame',
    ],
    'ordinal exceeds total' => [
        fn (): Placement => Placement::at(
            new PlacementFrame(PlacementScope::Sentence, 3),
            4,
            PlacementRegion::End,
        ),
        'must fall within its frame',
    ],
]);

it('rejects comparisons across distinct coordinate frames', function (PlacementFrame $leftFrame, PlacementFrame $rightFrame, string $operation): void {
    $left = Placement::at($leftFrame, 1, PlacementRegion::Beginning);
    $right = Placement::at($rightFrame, 1, PlacementRegion::Beginning);

    expect(fn (): mixed => $left->{$operation}($right))
        ->toThrow(InvalidArgumentException::class, 'within the same coordinate frame');
})->with([
    'same scope type but different container' => [
        new PlacementFrame(PlacementScope::Paragraph, 3),
        new PlacementFrame(PlacementScope::Paragraph, 3),
        'relationTo',
    ],
    'different scope type' => [
        new PlacementFrame(PlacementScope::Sentence, 3),
        new PlacementFrame(PlacementScope::Paragraph, 3),
        'ordinalDistanceTo',
    ],
    'normalized distance' => [
        new PlacementFrame(PlacementScope::Document, 3),
        new PlacementFrame(PlacementScope::Document, 3),
        'normalizedDistanceTo',
    ],
]);

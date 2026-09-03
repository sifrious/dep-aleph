<?php

declare(strict_types=1);

use InvalidArgumentException;
use Sifrious\Aleph\Connector\Midi\OursSmfMidiParser;
use Sifrious\Aleph\Tests\Fixtures\MidiFixture;

it('parses SMF 0 note, tempo, and time signature events with the in-house parser', function (): void {
    $result = (new OursSmfMidiParser)->parse(MidiFixture::smf0Simple());

    expect($result->format)->toBe(0)
        ->and($result->trackCount)->toBe(1)
        ->and($result->division)->toBe(96)
        ->and($result->events)->toHaveCount(4)
        ->and($result->events[0])->toMatchArray([
            'type' => 'time_signature',
            'tick' => 0,
            'track' => 0,
            'numerator' => 4,
            'denominator' => 4,
        ])
        ->and($result->events[1])->toMatchArray([
            'type' => 'tempo',
            'tick' => 0,
            'track' => 0,
            'microseconds_per_quarter' => 500_000,
        ])
        ->and($result->events[2])->toMatchArray([
            'type' => 'note_on',
            'tick' => 0,
            'track' => 0,
            'channel' => 0,
            'note' => 60,
            'velocity' => 64,
        ])
        ->and($result->events[3])->toMatchArray([
            'type' => 'note_off',
            'tick' => 96,
            'track' => 0,
            'channel' => 0,
            'note' => 60,
            'velocity' => 64,
        ]);
});

it('parses SMF 1 multi-track files', function (): void {
    $result = (new OursSmfMidiParser)->parse(MidiFixture::smf1TwoTracks());

    expect($result->format)->toBe(1)
        ->and($result->trackCount)->toBe(2)
        ->and($result->division)->toBe(480)
        ->and(collect($result->events)->pluck('type')->all())->toBe([
            'time_signature',
            'tempo',
            'note_on',
            'note_off',
        ])
        ->and($result->events[2])->toMatchArray([
            'type' => 'note_on',
            'track' => 1,
            'channel' => 1,
            'note' => 62,
            'velocity' => 80,
        ]);
});

it('rejects non-MIDI bytes', function (): void {
    expect(fn () => (new OursSmfMidiParser)->parse('not-a-midi-file'))
        ->toThrow(InvalidArgumentException::class);
});

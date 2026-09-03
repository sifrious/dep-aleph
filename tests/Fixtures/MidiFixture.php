<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Tests\Fixtures;

/**
 * Builds tiny Standard MIDI File fixtures for package tests.
 */
final class MidiFixture
{
    /**
     * SMF 0: 4/4 time signature, 120 BPM tempo, one C4 note on/off.
     */
    public static function smf0Simple(): string
    {
        $track = self::concat([
            self::vlq(0), "\xFF\x58\x04\x04\x02\x18\x08",
            self::vlq(0), "\xFF\x51\x03\x07\xA1\x20",
            self::vlq(0), "\x90\x3C\x40",
            self::vlq(96), "\x80\x3C\x40",
            self::vlq(0), "\xFF\x2F\x00",
        ]);

        return self::file(format: 0, trackCount: 1, division: 96, tracks: [$track]);
    }

    /**
     * SMF 1: tempo/time-sig on track 0, note events on track 1.
     */
    public static function smf1TwoTracks(): string
    {
        $conductor = self::concat([
            self::vlq(0), "\xFF\x58\x04\x03\x02\x18\x08",
            self::vlq(0), "\xFF\x51\x03\x07\xA1\x20",
            self::vlq(0), "\xFF\x2F\x00",
        ]);
        $notes = self::concat([
            self::vlq(0), "\x91\x3E\x50",
            self::vlq(48), "\x81\x3E\x00",
            self::vlq(0), "\xFF\x2F\x00",
        ]);

        return self::file(format: 1, trackCount: 2, division: 480, tracks: [$conductor, $notes]);
    }

    /**
     * @param  list<string>  $tracks
     */
    public static function file(int $format, int $trackCount, int $division, array $tracks): string
    {
        $header = 'MThd'
            .pack('N', 6)
            .pack('n', $format)
            .pack('n', $trackCount)
            .pack('n', $division);

        $body = '';

        foreach ($tracks as $track) {
            $body .= 'MTrk'.pack('N', strlen($track)).$track;
        }

        return $header.$body;
    }

    /**
     * @param  list<string>  $parts
     */
    private static function concat(array $parts): string
    {
        return implode('', $parts);
    }

    private static function vlq(int $value): string
    {
        $buffer = chr($value & 0x7F);

        while (($value >>= 7) > 0) {
            $buffer = chr(($value & 0x7F) | 0x80).$buffer;
        }

        return $buffer;
    }
}

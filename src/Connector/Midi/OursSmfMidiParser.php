<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

use InvalidArgumentException;

/**
 * Small in-house Standard MIDI File (SMF 0/1) parser.
 * Named "ours" in provenance; no third-party MIDI license or paid API.
 */
final class OursSmfMidiParser implements MidiParser
{
    public function parse(string $contents): MidiParseResult
    {
        if (strlen($contents) < 14) {
            throw new InvalidArgumentException('MIDI input is too short to be a Standard MIDI File.');
        }

        $offset = 0;
        [$type, $length] = $this->readChunkHeader($contents, $offset);

        if ($type !== 'MThd' || $length < 6) {
            throw new InvalidArgumentException('MIDI input is missing a valid MThd header chunk.');
        }

        $format = $this->readUint16($contents, $offset);
        $trackCount = $this->readUint16($contents, $offset);
        $division = $this->readUint16($contents, $offset);
        $offset += $length - 6;

        if ($format !== 0 && $format !== 1) {
            throw new InvalidArgumentException("Unsupported Standard MIDI File format [{$format}].");
        }

        if (($division & 0x8000) !== 0) {
            throw new InvalidArgumentException('SMPTE MIDI division is not supported by this parser.');
        }

        $events = [];

        for ($track = 0; $track < $trackCount; $track++) {
            if ($offset + 8 > strlen($contents)) {
                throw new InvalidArgumentException("MIDI track [{$track}] is truncated.");
            }

            [$trackType, $trackLength] = $this->readChunkHeader($contents, $offset);

            if ($trackType !== 'MTrk') {
                throw new InvalidArgumentException("Expected MTrk chunk for track [{$track}], found [{$trackType}].");
            }

            $trackEnd = $offset + $trackLength;

            if ($trackEnd > strlen($contents)) {
                throw new InvalidArgumentException("MIDI track [{$track}] extends past end of file.");
            }

            $tick = 0;
            $runningStatus = null;

            while ($offset < $trackEnd) {
                $delta = $this->readVariableLength($contents, $offset, $trackEnd);
                $tick += $delta;

                if ($offset >= $trackEnd) {
                    throw new InvalidArgumentException("MIDI track [{$track}] ended inside an event.");
                }

                $statusByte = ord($contents[$offset]);

                if ($statusByte < 0x80) {
                    if ($runningStatus === null) {
                        throw new InvalidArgumentException("MIDI track [{$track}] used running status without a prior status byte.");
                    }

                    $status = $runningStatus;
                } else {
                    $status = $statusByte;
                    $offset++;

                    if ($status < 0xF0) {
                        $runningStatus = $status;
                    } elseif ($status === 0xF0 || $status === 0xF7 || $status === 0xFF) {
                        $runningStatus = null;
                    }
                }

                if ($status === 0xFF) {
                    $metaType = $this->readUint8($contents, $offset, $trackEnd);
                    $metaLength = $this->readVariableLength($contents, $offset, $trackEnd);
                    $metaData = $this->readBytes($contents, $offset, $metaLength, $trackEnd);
                    $parsed = $this->metaEvent($tick, $track, $metaType, $metaData);

                    if ($parsed !== null) {
                        $events[] = $parsed;
                    }

                    continue;
                }

                if ($status === 0xF0 || $status === 0xF7) {
                    $sysexLength = $this->readVariableLength($contents, $offset, $trackEnd);
                    $this->readBytes($contents, $offset, $sysexLength, $trackEnd);

                    continue;
                }

                $parsed = $this->channelEvent($tick, $track, $status, $contents, $offset, $trackEnd);

                if ($parsed !== null) {
                    $events[] = $parsed;
                }
            }
        }

        return new MidiParseResult($format, $trackCount, $division, $events);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readChunkHeader(string $contents, int &$offset): array
    {
        if ($offset + 8 > strlen($contents)) {
            throw new InvalidArgumentException('MIDI chunk header is truncated.');
        }

        $type = substr($contents, $offset, 4);
        $offset += 4;
        $length = $this->readUint32($contents, $offset);

        return [$type, $length];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function metaEvent(int $tick, int $track, int $metaType, string $data): ?array
    {
        return match ($metaType) {
            0x51 => $this->tempoEvent($tick, $track, $data),
            0x58 => $this->timeSignatureEvent($tick, $track, $data),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function tempoEvent(int $tick, int $track, string $data): array
    {
        if (strlen($data) !== 3) {
            throw new InvalidArgumentException('MIDI tempo meta event must carry exactly three bytes.');
        }

        $microseconds = (ord($data[0]) << 16) | (ord($data[1]) << 8) | ord($data[2]);

        return [
            'type' => 'tempo',
            'tick' => $tick,
            'track' => $track,
            'microseconds_per_quarter' => $microseconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timeSignatureEvent(int $tick, int $track, string $data): array
    {
        if (strlen($data) < 2) {
            throw new InvalidArgumentException('MIDI time signature meta event is truncated.');
        }

        $numerator = ord($data[0]);
        $denominatorPower = ord($data[1]);

        return [
            'type' => 'time_signature',
            'tick' => $tick,
            'track' => $track,
            'numerator' => $numerator,
            'denominator' => 2 ** $denominatorPower,
            'clocks_per_click' => isset($data[2]) ? ord($data[2]) : null,
            'thirty_seconds_per_quarter' => isset($data[3]) ? ord($data[3]) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function channelEvent(
        int $tick,
        int $track,
        int $status,
        string $contents,
        int &$offset,
        int $trackEnd,
    ): ?array {
        $command = $status & 0xF0;
        $channel = $status & 0x0F;

        return match ($command) {
            0x80 => $this->noteEvent('note_off', $tick, $track, $channel, $contents, $offset, $trackEnd),
            0x90 => $this->noteOnEvent($tick, $track, $channel, $contents, $offset, $trackEnd),
            0xA0, 0xB0, 0xE0 => $this->skipDataBytes($contents, $offset, 2, $trackEnd),
            0xC0, 0xD0 => $this->skipDataBytes($contents, $offset, 1, $trackEnd),
            default => throw new InvalidArgumentException(sprintf('Unsupported MIDI status byte [0x%02X].', $status)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function noteOnEvent(
        int $tick,
        int $track,
        int $channel,
        string $contents,
        int &$offset,
        int $trackEnd,
    ): array {
        $note = $this->readUint8($contents, $offset, $trackEnd);
        $velocity = $this->readUint8($contents, $offset, $trackEnd);

        if ($velocity === 0) {
            return [
                'type' => 'note_off',
                'tick' => $tick,
                'track' => $track,
                'channel' => $channel,
                'note' => $note,
                'velocity' => 0,
            ];
        }

        return [
            'type' => 'note_on',
            'tick' => $tick,
            'track' => $track,
            'channel' => $channel,
            'note' => $note,
            'velocity' => $velocity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function noteEvent(
        string $type,
        int $tick,
        int $track,
        int $channel,
        string $contents,
        int &$offset,
        int $trackEnd,
    ): array {
        $note = $this->readUint8($contents, $offset, $trackEnd);
        $velocity = $this->readUint8($contents, $offset, $trackEnd);

        return [
            'type' => $type,
            'tick' => $tick,
            'track' => $track,
            'channel' => $channel,
            'note' => $note,
            'velocity' => $velocity,
        ];
    }

    /**
     * @return null
     */
    private function skipDataBytes(string $contents, int &$offset, int $count, int $trackEnd): null
    {
        $this->readBytes($contents, $offset, $count, $trackEnd);

        return null;
    }

    private function readVariableLength(string $contents, int &$offset, int $limit): int
    {
        $value = 0;

        for ($i = 0; $i < 4; $i++) {
            if ($offset >= $limit || $offset >= strlen($contents)) {
                throw new InvalidArgumentException('MIDI variable-length quantity is truncated.');
            }

            $byte = ord($contents[$offset]);
            $offset++;
            $value = ($value << 7) | ($byte & 0x7F);

            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        throw new InvalidArgumentException('MIDI variable-length quantity exceeds four bytes.');
    }

    private function readUint8(string $contents, int &$offset, int $limit): int
    {
        if ($offset >= $limit || $offset >= strlen($contents)) {
            throw new InvalidArgumentException('MIDI event data is truncated.');
        }

        $value = ord($contents[$offset]);
        $offset++;

        return $value;
    }

    private function readUint16(string $contents, int &$offset): int
    {
        if ($offset + 2 > strlen($contents)) {
            throw new InvalidArgumentException('MIDI uint16 field is truncated.');
        }

        $value = (ord($contents[$offset]) << 8) | ord($contents[$offset + 1]);
        $offset += 2;

        return $value;
    }

    private function readUint32(string $contents, int &$offset): int
    {
        if ($offset + 4 > strlen($contents)) {
            throw new InvalidArgumentException('MIDI uint32 field is truncated.');
        }

        $value = (ord($contents[$offset]) << 24)
            | (ord($contents[$offset + 1]) << 16)
            | (ord($contents[$offset + 2]) << 8)
            | ord($contents[$offset + 3]);
        $offset += 4;

        return $value;
    }

    private function readBytes(string $contents, int &$offset, int $length, int $limit): string
    {
        if ($length < 0 || $offset + $length > $limit || $offset + $length > strlen($contents)) {
            throw new InvalidArgumentException('MIDI byte payload is truncated.');
        }

        $bytes = substr($contents, $offset, $length);
        $offset += $length;

        return $bytes;
    }
}

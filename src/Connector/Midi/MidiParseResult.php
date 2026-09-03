<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

/**
 * Versioned SMF parse output destined for Funes MME-1438 extracted representations.
 * Music document graph schema ownership stays with Funes MME-2202.
 */
final readonly class MidiParseResult
{
    public const PARSER_NAME = 'ours';

    public const PARSER_VERSION = '1.0.0';

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        public int $format,
        public int $trackCount,
        public int $division,
        public array $events,
    ) {
        if ($format !== 0 && $format !== 1) {
            throw new \InvalidArgumentException('MIDI ingest supports Standard MIDI File formats 0 and 1 only.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toExtractionResult(string $checksum, int $bytes): array
    {
        return [
            'kind' => 'midi_smf_events',
            'parser' => [
                'name' => self::PARSER_NAME,
                'version' => self::PARSER_VERSION,
            ],
            'format' => $this->format,
            'smf_format' => 'SMF '.$this->format,
            'track_count' => $this->trackCount,
            'division' => $this->division,
            'checksum' => [
                'algorithm' => 'sha256',
                'value' => $checksum,
                'bytes' => $bytes,
            ],
            'events' => $this->events,
        ];
    }
}

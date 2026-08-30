<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

interface MidiExtractionRecorder
{
    public function record(string $observationId, MidiParseResult $parse, string $checksum, int $bytes, string $runId): void;
}

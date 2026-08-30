<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\ExtractionDraft;
use Sifrious\Funes\Value\IngestionRun as FunesIngestionRun;
use Sifrious\Funes\Value\Producer;
use Sifrious\Funes\Value\ProducerContext;

/**
 * Stores SMF-derived event lists as versioned Funes extracted representations (MME-1438).
 * Does not overwrite raw MIDI bytes and does not invent music-domain columns (MME-2202).
 */
final readonly class FunesMidiExtractionRecorder implements MidiExtractionRecorder
{
    public function __construct(private ObservationStore $observations) {}

    public function record(
        string $observationId,
        MidiParseResult $parse,
        string $checksum,
        int $bytes,
        string $runId,
    ): void {
        $this->observations->recordExtraction(new ExtractionDraft(
            observationId: $observationId,
            extractor: 'aleph.midi.'.MidiParseResult::PARSER_NAME,
            version: MidiParseResult::PARSER_VERSION,
            producerContext: new ProducerContext(
                new Producer(
                    'aleph:midi/parser/'.MidiParseResult::PARSER_NAME,
                    'Aleph in-house MIDI SMF parser',
                ),
                new FunesIngestionRun($runId),
            ),
            result: $parse->toExtractionResult($checksum, $bytes),
        ));
    }
}

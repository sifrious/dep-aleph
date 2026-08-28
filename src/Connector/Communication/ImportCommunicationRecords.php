<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Throwable;

final readonly class ImportCommunicationRecords
{
    public function __construct(
        private CommunicationSources $sources,
        private ConnectorInstallations $installations,
        private SourceStreams $streams,
        private IngestionRuns $runs,
        private IngestionCheckpoints $checkpoints,
        private CommunicationRecordSubmitter $submitter,
    ) {}

    public function import(CommunicationImportRequest $request): CommunicationImportResult
    {
        $source = $this->sources->get($request->provider, $request->sourceReference);
        $stream = $this->streams->find($request->streamId);
        $run = $this->runs->find($request->runId);
        $attempt = $this->runs->attempt($request->attemptId);
        $installation = $run?->sourceInstallationId === null ? null : $this->installations->find($run->sourceInstallationId);

        if ($stream === null || $run === null || $attempt === null || $installation === null
            || $stream->sourceInstallationId !== $installation->id || $attempt->runId !== $run->id
            || $run->sourceReference !== $request->sourceReference || $source->provider() !== $request->provider) {
            throw new InvalidArgumentException('Communication import stream, run, attempt, source, provider, and installation must agree.');
        }

        $current = $this->checkpoints->latest($stream, $request->capability, 'conversation');
        $checkpoint = $current?->value->value;
        $accepted = [];
        $pages = 0;
        $records = 0;
        $complete = false;

        try {
            do {
                $previous = $checkpoint;
                $page = $source->page($checkpoint === '' ? null : $checkpoint, $request->pageSize);
                $pageAccepted = [];
                $pages++;

                foreach ($page->records as $record) {
                    if ($record->provider !== $request->provider || $record->sourceReference !== $request->sourceReference) {
                        throw new InvalidArgumentException('Communication source returned a record outside the requested provider or source.');
                    }

                    $reference = $this->submitter->submit($record, $installation->id, $installation->externalAccountId, $run->id, $attempt->id);
                    $pageAccepted[] = $reference;
                    $accepted[] = $reference;
                    $records++;
                }

                $checkpoint = $page->checkpoint;

                if ($page->hasMore && ($checkpoint === null || $checkpoint === $previous)) {
                    throw new InvalidArgumentException('Communication pagination must advance its checkpoint.');
                }

                $pageAccepted = array_values(array_unique($pageAccepted));
                $accepted = array_values(array_unique($accepted));
                $stats = ['pages' => $pages, 'records' => $records, 'accepted' => count($accepted)];

                if ($pageAccepted !== []) {
                    $this->runs->recordProgress($run, $attempt, ['checkpoint' => $checkpoint], $stats, $accepted);
                    $this->checkpoints->commit(
                        $stream,
                        $request->capability,
                        'conversation',
                        new CheckpointValue($source->checkpointType(), '1', $checkpoint ?? ''),
                        $request->expectedCheckpointVersion + $pages - 1,
                        $run,
                        $pageAccepted,
                        $attempt,
                    );
                }

                $complete = ! $page->hasMore;
            } while (! $complete && $pages < $request->maxPages);

            $stats = ['pages' => $pages, 'records' => $records, 'accepted' => count($accepted)];
            $this->runs->recordProgress($run, $attempt, ['checkpoint' => $checkpoint], $stats, $accepted);

            if ($complete) {
                $this->runs->succeedAttempt($run, $attempt, $stats, $accepted);
            } else {
                $this->runs->failAttempt(
                    $run,
                    $attempt,
                    new RunFailure('communication_page_budget', 'Communication import paused at its bounded page budget.', true, ['checkpoint' => $checkpoint]),
                    $stats,
                    $accepted,
                    true,
                    [['partition' => 'conversation', 'checkpoint' => $checkpoint]],
                    warningCount: 1,
                    errorCount: 0,
                );
            }
        } catch (Throwable $failure) {
            $this->runs->failAttempt($run, $attempt, new RunFailure(
                'communication_import',
                $failure->getMessage(),
                ! $failure instanceof InvalidArgumentException,
                ['checkpoint' => $checkpoint],
            ), acceptedReferences: array_values(array_unique($accepted)));

            throw $failure;
        }

        return new CommunicationImportResult($pages, $records, $checkpoint, $complete, array_values(array_unique($accepted)));
    }
}

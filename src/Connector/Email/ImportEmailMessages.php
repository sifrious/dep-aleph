<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Throwable;

final readonly class ImportEmailMessages
{
    public function __construct(
        private EmailSources $sources,
        private ConnectorInstallations $installations,
        private SourceStreams $streams,
        private IngestionRuns $runs,
        private IngestionCheckpoints $checkpoints,
        private EmailMessageSubmitter $submitter,
    ) {}

    public function import(EmailImportRequest $request): EmailImportResult
    {
        $source = $this->sources->get($request->sourceReference);
        $stream = $this->streams->find($request->streamId);
        $run = $this->runs->find($request->runId);
        $attempt = $this->runs->attempt($request->attemptId);
        $installation = $run?->sourceInstallationId === null ? null : $this->installations->find($run->sourceInstallationId);

        if ($stream === null || $run === null || $attempt === null || $installation === null
            || $stream->sourceInstallationId !== $installation->id
            || $attempt->runId !== $run->id
            || $run->sourceReference !== $request->sourceReference
        ) {
            throw new InvalidArgumentException('Email import stream, run, attempt, source, and mailbox must share one installation.');
        }

        $current = $this->checkpoints->latest($stream, $request->capability, 'mailbox');
        $checkpoint = $current?->value->value;
        $accepted = [];
        $pages = 0;
        $messages = 0;
        $complete = false;

        try {
            do {
                $previousCheckpoint = $checkpoint;
                $page = $source->page($checkpoint === '' ? null : $checkpoint, $request->pageSize);
                $pageAccepted = [];
                $pages++;

                foreach ($page->messages as $message) {
                    if ($message->mailboxReference !== $request->sourceReference) {
                        throw new InvalidArgumentException('Email source returned a message outside the requested mailbox.');
                    }

                    $reference = $this->submitter->submit(
                        $message,
                        $installation->id,
                        $installation->externalAccountId,
                        new DateTimeImmutable,
                        $run->id,
                        $attempt->id,
                    );
                    $pageAccepted[] = $reference;
                    $accepted[] = $reference;
                    $messages++;
                }

                $checkpoint = $page->checkpoint;

                if ($page->hasMore && ($checkpoint === null || $checkpoint === $previousCheckpoint)) {
                    throw new InvalidArgumentException('Email pagination must advance its checkpoint.');
                }

                $pageAccepted = array_values(array_unique($pageAccepted));
                $accepted = array_values(array_unique($accepted));
                $stats = ['pages' => $pages, 'messages' => $messages, 'accepted' => count($accepted)];

                if ($pageAccepted !== []) {
                    $this->runs->recordProgress($run, $attempt, ['checkpoint' => $checkpoint], $stats, $accepted);
                    $this->checkpoints->commit(
                        $stream,
                        $request->capability,
                        'mailbox',
                        new CheckpointValue($source->checkpointType(), '1', $checkpoint ?? ''),
                        $request->expectedCheckpointVersion + $pages - 1,
                        $run,
                        $pageAccepted,
                        $attempt,
                    );
                }

                $complete = ! $page->hasMore;
            } while (! $complete && $pages < $request->maxPages);

            $stats = ['pages' => $pages, 'messages' => $messages, 'accepted' => count($accepted)];
            $this->runs->recordProgress($run, $attempt, ['checkpoint' => $checkpoint], $stats, $accepted);

            if ($complete) {
                $this->runs->succeedAttempt($run, $attempt, $stats, $accepted);
            } else {
                $this->runs->failAttempt(
                    $run,
                    $attempt,
                    new RunFailure('email_page_budget', 'Email import paused at its bounded page budget.', true, [
                        'checkpoint' => $checkpoint,
                    ]),
                    $stats,
                    $accepted,
                    true,
                    [['partition' => 'mailbox', 'checkpoint' => $checkpoint]],
                    warningCount: 1,
                    errorCount: 0,
                );
            }
        } catch (Throwable $failure) {
            $this->runs->failAttempt($run, $attempt, new RunFailure(
                'email_import',
                $failure->getMessage(),
                ! $failure instanceof InvalidArgumentException,
                ['checkpoint' => $checkpoint],
            ), acceptedReferences: array_values(array_unique($accepted)));

            throw $failure;
        }

        return new EmailImportResult($pages, $messages, $checkpoint, $complete, array_values(array_unique($accepted)));
    }
}

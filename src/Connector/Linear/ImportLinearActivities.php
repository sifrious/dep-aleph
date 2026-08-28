<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Throwable;

final readonly class ImportLinearActivities
{
    public function __construct(
        private LinearActivitySources $sources,
        private ConnectorInstallations $installations,
        private SourceStreams $streams,
        private IngestionRuns $runs,
        private IngestionCheckpoints $checkpoints,
        private LinearActivitySubmitter $submitter,
    ) {}

    public function import(LinearImportRequest $request): LinearImportResult
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
            throw new InvalidArgumentException('Linear import stream, run, attempt, source, and workspace must share one installation.');
        }

        $accepted = [];
        $pages = [];
        $activities = [];
        $cursors = [];

        try {
            foreach ($request->streams as $linearStream) {
                $partition = $linearStream->value;
                $current = $this->checkpoints->latest($stream, $request->capability, $partition);
                $cursor = $current?->value->value;
                $streamAccepted = [];
                $pageCount = 0;
                $activityCount = 0;

                do {
                    $previousCursor = $cursor;
                    $page = $source->page($linearStream, $cursor === '' ? null : $cursor, $request->pageSize);
                    $pageCount++;

                    foreach ($page->activities as $activity) {
                        if ($activity->workspaceReference !== $request->sourceReference) {
                            throw new InvalidArgumentException('Linear source returned activity outside the requested workspace.');
                        }

                        $reference = $this->submitter->submit(
                            $activity,
                            $installation->id,
                            $installation->externalAccountId,
                            new DateTimeImmutable,
                            'poll',
                            $run->id,
                            $attempt->id,
                        );
                        $streamAccepted[] = $reference;
                        $accepted[] = $reference;
                        $activityCount++;
                    }

                    $cursor = $page->endCursor;

                    if ($page->hasNextPage && ($cursor === null || $cursor === $previousCursor)) {
                        throw new InvalidArgumentException("Linear {$partition} pagination must advance its cursor.");
                    }
                } while ($page->hasNextPage);

                $streamAccepted = array_values(array_unique($streamAccepted));
                $pages[$partition] = $pageCount;
                $activities[$partition] = $activityCount;
                $cursors[$partition] = $cursor;

                if ($streamAccepted !== []) {
                    $this->runs->recordProgress($run, $attempt, ['cursors' => $cursors], [
                        'pages' => array_sum($pages),
                        'activities' => array_sum($activities),
                        'accepted' => count(array_unique($accepted)),
                    ], array_values(array_unique($accepted)));
                    $this->checkpoints->commit(
                        $stream,
                        $request->capability,
                        $partition,
                        new CheckpointValue('linear.graphql.cursor', '1', $cursor ?? ''),
                        $request->expectedCheckpointVersions[$partition] ?? 0,
                        $run,
                        $streamAccepted,
                        $attempt,
                    );
                }
            }

            $accepted = array_values(array_unique($accepted));
            $stats = [
                'pages' => array_sum($pages),
                'activities' => array_sum($activities),
                'accepted' => count($accepted),
            ];
            $this->runs->recordProgress($run, $attempt, ['cursors' => $cursors], $stats, $accepted);
            $this->runs->succeedAttempt($run, $attempt, $stats, $accepted);
        } catch (Throwable $failure) {
            $this->runs->failAttempt($run, $attempt, new RunFailure(
                'linear_activity_import',
                $failure->getMessage(),
                ! $failure instanceof InvalidArgumentException,
                ['cursors' => $cursors],
            ), acceptedReferences: array_values(array_unique($accepted)));

            throw $failure;
        }

        return new LinearImportResult($pages, $activities, $cursors, $accepted);
    }
}

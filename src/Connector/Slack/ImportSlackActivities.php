<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Throwable;

final readonly class ImportSlackActivities
{
    public function __construct(
        private SlackActivitySources $sources,
        private ConnectorInstallations $installations,
        private SourceStreams $streams,
        private IngestionRuns $runs,
        private IngestionCheckpoints $checkpoints,
        private SlackActivitySubmitter $submitter,
    ) {}

    public function import(SlackImportRequest $request): SlackImportResult
    {
        $source = $this->sources->get($request->sourceReference);
        $stream = $this->streams->find($request->streamId);
        $run = $this->runs->find($request->runId);
        $attempt = $this->runs->attempt($request->attemptId);
        $installation = $run?->sourceInstallationId === null ? null : $this->installations->find($run->sourceInstallationId);

        if ($stream === null || $run === null || $attempt === null || $installation === null || $stream->sourceInstallationId !== $installation->id || $attempt->runId !== $run->id || $run->sourceReference !== $request->sourceReference) {
            throw new InvalidArgumentException('Slack import stream, run, attempt, source, and workspace must share one installation.');
        }

        $accepted = [];
        $encoded = [];
        $pages = 0;
        $activities = 0;
        $complete = true;

        try {
            foreach ($request->partitions as $partition) {
                $current = $this->checkpoints->latest($stream, $request->capability, $partition);
                $checkpoint = SlackCheckpoint::decode($current?->value->value);
                $partitionPages = 0;

                do {
                    $page = $source->page($partition, $checkpoint, $request->pageSize);
                    $pageAccepted = [];
                    $pages++;
                    $partitionPages++;

                    foreach ($page->activities as $activity) {
                        if ($activity->workspaceReference !== $request->sourceReference) {
                            throw new InvalidArgumentException('Slack source returned activity outside the requested workspace.');
                        }

                        $reference = $this->submitter->submit($activity, $installation->id, $installation->externalAccountId, 'poll', $run->id, $attempt->id);
                        $pageAccepted[] = $reference;
                        $accepted[] = $reference;
                        $activities++;
                    }

                    $next = new SlackCheckpoint($page->nextCursor, $page->highWater ?? $checkpoint->highWater);
                    $encoded[$partition] = $next->encode();
                    $accepted = array_values(array_unique($accepted));
                    $pageAccepted = array_values(array_unique($pageAccepted));

                    if ($pageAccepted !== []) {
                        $stats = ['pages' => $pages, 'activities' => $activities, 'accepted' => count($accepted)];
                        $this->runs->recordProgress($run, $attempt, ['slack' => $encoded], $stats, $accepted);
                        $this->checkpoints->commit($stream, $request->capability, $partition, new CheckpointValue('slack.cursor-high-water', '1', $next->encode()), ($request->expectedVersions[$partition] ?? 0) + $partitionPages - 1, $run, $pageAccepted, $attempt);
                    }

                    $checkpoint = $next;
                    $complete = ! $page->hasMore;
                } while (! $complete && $partitionPages < $request->maxPages);

                if (! $complete) {
                    break;
                }
            }

            $stats = ['pages' => $pages, 'activities' => $activities, 'accepted' => count($accepted)];

            if ($complete) {
                $this->runs->succeedAttempt($run, $attempt, $stats, $accepted);
            } else {
                $this->runs->failAttempt($run, $attempt, new RunFailure('slack_page_budget', 'Slack import paused at its bounded page budget.', true), $stats, $accepted, true, [['partitions' => $request->partitions]], warningCount: 1, errorCount: 0);
            }
        } catch (Throwable $failure) {
            $retryable = $failure instanceof SlackRateLimited || ! $failure instanceof InvalidArgumentException;
            $this->runs->failAttempt($run, $attempt, new RunFailure($failure instanceof SlackRateLimited ? 'rate_limited' : 'slack_import', $failure->getMessage(), $retryable), acceptedReferences: $accepted, backoffUntil: $failure instanceof SlackRateLimited ? $failure->retryAt : null);
            throw $failure;
        }

        return new SlackImportResult($pages, $activities, $complete, $encoded, $accepted);
    }
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Throwable;

final readonly class ImportGitHubActivities
{
    public function __construct(
        private GitHubActivitySources $sources,
        private ConnectorInstallations $installations,
        private SourceStreams $streams,
        private IngestionRuns $runs,
        private IngestionCheckpoints $checkpoints,
        private GitHubActivitySubmitter $submitter,
    ) {}

    public function import(GitHubImportRequest $request): GitHubImportResult
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
            throw new InvalidArgumentException('GitHub import stream, run, attempt, source, and account must share one installation.');
        }

        $current = $this->checkpoints->latest($stream, $request->capability, $request->repository);
        $cursor = $current?->value->value;
        $accepted = [];
        $pages = 0;
        $activities = 0;

        try {
            do {
                $previousCursor = $cursor;
                $page = $source->page($request->repository, $cursor === '' ? null : $cursor, $request->pageSize);
                $pages++;

                foreach ($page->activities as $activity) {
                    if (strcasecmp($activity->repository, $request->repository) !== 0) {
                        throw new InvalidArgumentException('GitHub source returned activity outside the requested repository.');
                    }

                    $accepted[] = $this->submitter->submit(
                        $activity,
                        $installation->id,
                        $installation->externalAccountId,
                        new DateTimeImmutable,
                        'poll',
                        $run->id,
                        $attempt->id,
                    );
                    $activities++;
                }

                $cursor = $page->endCursor;

                if ($page->hasNextPage && ($cursor === null || $cursor === $previousCursor)) {
                    throw new InvalidArgumentException('GitHub pagination must advance its GraphQL cursor.');
                }
            } while ($page->hasNextPage);

            $accepted = array_values(array_unique($accepted));
            $stats = ['pages' => $pages, 'activities' => $activities, 'accepted' => count($accepted)];
            $this->runs->recordProgress($run, $attempt, ['repository' => $request->repository, 'cursor' => $cursor], $stats, $accepted);
            $checkpoint = $accepted === []
                ? $current
                : $this->checkpoints->commit(
                    $stream,
                    $request->capability,
                    $request->repository,
                    new CheckpointValue('github.graphql.cursor', '1', $cursor ?? ''),
                    $request->expectedCheckpointVersion,
                    $run,
                    $accepted,
                    $attempt,
                );
            $this->runs->succeedAttempt($run, $attempt, $stats, $accepted);
        } catch (Throwable $failure) {
            $this->runs->failAttempt($run, $attempt, new RunFailure(
                $failure instanceof GitHubRateLimited ? 'rate_limited' : 'github_activity_import',
                $failure->getMessage(),
                ! $failure instanceof InvalidArgumentException,
                array_filter([
                    'repository' => $request->repository,
                    'retry_at' => $failure instanceof GitHubRateLimited ? $failure->retryAt->format(DATE_ATOM) : null,
                ]),
            ), acceptedReferences: array_values(array_unique($accepted)), backoffUntil: $failure instanceof GitHubRateLimited ? $failure->retryAt : null);

            throw $failure;
        }

        return new GitHubImportResult($pages, $activities, $cursor, $accepted, $checkpoint);
    }
}

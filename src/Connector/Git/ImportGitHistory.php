<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Throwable;

final readonly class ImportGitHistory
{
    public function __construct(
        private GitRepositorySources $sources,
        private SourceStreams $streams,
        private IngestionRuns $runs,
        private IngestionCheckpoints $checkpoints,
        private EnvelopeSubmitter $submitter,
        private GitChangeDetector $changes,
    ) {}

    public function import(GitImportRequest $request): GitImportResult
    {
        $source = $this->sources->get($request->sourceReference);
        $stream = $this->streams->find($request->streamId);
        $run = $this->runs->find($request->runId);
        $attempt = $this->runs->attempt($request->attemptId);

        if ($stream === null || $run === null || $attempt === null
            || $stream->sourceInstallationId !== $run->sourceInstallationId
            || $attempt->runId !== $run->id
        ) {
            throw new InvalidArgumentException('Git import stream, run, and attempt must share one source installation.');
        }

        $accepted = [];

        try {
            $current = $this->checkpoints->latest($stream, Capability::IncrementalSync, $request->ref);
            $previousHead = $current?->value->value;
            $snapshot = $source->snapshot($request->ref, $previousHead);
            $fileChanges = $this->changes->detect($snapshot->previousTree, $snapshot->tree);
            $envelopes = $this->envelopes($source->repository(), $snapshot, $fileChanges, (string) $run->sourceInstallationId, $run->id);

            foreach ($envelopes as $envelope) {
                $outcome = $this->submitter->submit($envelope, $attempt->id);
                $acceptedId = $outcome->acceptedId();

                if (! $outcome->isAuthoritative() || $acceptedId === null) {
                    throw new InvalidArgumentException('Funes did not accept the complete Git snapshot.');
                }

                $accepted[] = $acceptedId;
            }

            $accepted = array_values(array_unique($accepted));
            $stats = [
                'commits' => count($snapshot->commits),
                'files' => count($snapshot->tree),
                'changes' => count($fileChanges),
                'blame_ranges' => count($snapshot->blame),
                'accepted' => count($accepted),
            ];
            $this->runs->recordProgress($run, $attempt, ['ref' => $request->ref, 'sha' => $snapshot->headSha], $stats, $accepted);
            $checkpoint = $this->checkpoints->commit(
                $stream,
                Capability::IncrementalSync,
                $request->ref,
                new CheckpointValue('git.sha1', '1', $snapshot->headSha),
                $request->expectedCheckpointVersion,
                $run,
                $accepted,
                $attempt,
            );
            $this->runs->succeedAttempt($run, $attempt, $stats, $accepted);
        } catch (Throwable $failure) {
            $this->runs->failAttempt(
                $run,
                $attempt,
                new RunFailure('git_import', $failure->getMessage(), ! $failure instanceof InvalidArgumentException, [
                    'source_reference' => $request->sourceReference,
                    'ref' => $request->ref,
                ]),
                acceptedReferences: array_values(array_unique($accepted)),
            );

            throw $failure;
        }

        return new GitImportResult(
            $snapshot->headSha,
            $snapshot->forcePushed(),
            count($snapshot->commits),
            count($snapshot->tree),
            count($fileChanges),
            count($snapshot->blame),
            $accepted,
            $checkpoint,
        );
    }

    /**
     * @param  list<GitFileChange>  $changes
     * @return list<ObservationEnvelope>
     */
    private function envelopes(
        GitRepository $repository,
        GitSnapshot $snapshot,
        array $changes,
        string $installationId,
        string $runId,
    ): array {
        $capturedAt = $snapshot->capturedAt;
        $provenance = new Provenance('git-history', '1.0.0', $installationId, $capturedAt, $runId);
        $envelopes = [
            $this->envelope($repository, 'repository', $repository->reference, [
                'reference' => $repository->reference,
                'name' => $repository->name,
                'remote_url' => $repository->remoteUrl,
            ], $snapshot, $provenance, $capturedAt),
            $this->envelope($repository, 'ref', $snapshot->ref.'@'.$snapshot->headSha, [
                'ref' => $snapshot->ref,
                'head_sha' => $snapshot->headSha,
            ], $snapshot, $provenance, $capturedAt),
        ];

        if ($snapshot->previousHeadSha !== null && $snapshot->previousHeadSha !== $snapshot->headSha) {
            $envelopes[] = $this->envelope($repository, 'ref-movement', $snapshot->ref.'/'.$snapshot->previousHeadSha.'..'.$snapshot->headSha, [
                'ref' => $snapshot->ref,
                'head_sha' => $snapshot->headSha,
                'previous_head_sha' => $snapshot->previousHeadSha,
                'force_pushed' => $snapshot->forcePushed(),
            ], $snapshot, $provenance, $capturedAt);
        }

        foreach ($snapshot->commits as $commit) {
            $envelopes[] = $this->envelope($repository, 'commit', $commit->sha, [
                'sha' => $commit->sha,
                'parents' => $commit->parents,
                'author' => ['name' => $commit->authorName, 'email' => $commit->authorEmail],
                'authored_at' => $commit->authoredAt->format(DATE_ATOM),
                'committed_at' => $commit->committedAt->format(DATE_ATOM),
                'message' => $commit->message,
            ], $snapshot, $provenance, $commit->committedAt);
        }

        $envelopes[] = $this->envelope($repository, 'tree', $snapshot->headSha, [
            'commit_sha' => $snapshot->headSha,
            'entries' => array_map(
                static fn (GitTreeEntry $entry): array => [
                    'path' => $entry->path,
                    'blob_sha' => $entry->blobSha,
                    'mode' => $entry->mode,
                ],
                $snapshot->tree,
            ),
        ], $snapshot, $provenance, $capturedAt);

        foreach ($snapshot->tree as $entry) {
            $envelopes[] = $this->envelope($repository, 'file', $snapshot->headSha.'/'.$entry->path, [
                'commit_sha' => $snapshot->headSha,
                'path' => $entry->path,
                'blob_sha' => $entry->blobSha,
                'mode' => $entry->mode,
                'content' => $entry->content,
            ], $snapshot, $provenance, $capturedAt);
        }

        foreach ($changes as $change) {
            $envelopes[] = $this->envelope($repository, 'change', $snapshot->headSha.'/'.$change->kind->value.'/'.$change->path, [
                'commit_sha' => $snapshot->headSha,
                'kind' => $change->kind->value,
                'path' => $change->path,
                'previous_path' => $change->previousPath,
                'previous_blob_sha' => $change->previousBlobSha,
                'blob_sha' => $change->blobSha,
            ], $snapshot, $provenance, $capturedAt);
        }

        if ($snapshot->diff !== '') {
            $envelopes[] = $this->envelope($repository, 'diff', ($snapshot->previousHeadSha ?? 'root').'..'.$snapshot->headSha, [
                'from_sha' => $snapshot->previousHeadSha,
                'to_sha' => $snapshot->headSha,
                'patch' => $snapshot->diff,
            ], $snapshot, $provenance, $capturedAt);
        }

        foreach ($snapshot->blame as $blame) {
            $envelopes[] = $this->envelope($repository, 'blame', $snapshot->headSha.'/'.$blame->path.'/'.$blame->startLine.'-'.$blame->endLine, [
                'path' => $blame->path,
                'start_line' => $blame->startLine,
                'end_line' => $blame->endLine,
                'commit_sha' => $blame->commitSha,
                'author' => ['name' => $blame->authorName, 'email' => $blame->authorEmail],
            ], $snapshot, $provenance, $capturedAt);
        }

        return $envelopes;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function envelope(
        GitRepository $repository,
        string $kind,
        string $identity,
        array $payload,
        GitSnapshot $snapshot,
        Provenance $provenance,
        DateTimeImmutable $occurredAt,
    ): ObservationEnvelope {
        return new ObservationEnvelope(
            sourceReference: $repository->reference,
            sourceName: $repository->name,
            resourceReference: $repository->reference.'/'.$kind.'/'.hash('sha256', $identity),
            observedAt: $provenance->capturedAt,
            payload: json_encode($payload, JSON_THROW_ON_ERROR),
            provenance: $provenance,
            contentType: 'application/json',
            stream: $snapshot->ref,
            eventType: 'git.'.$kind,
            providerId: $identity,
            providerRevision: $snapshot->headSha,
            extensions: [new ExtensionMetadata('git.history', 1, ['kind' => $kind])],
            occurredAt: $occurredAt,
        );
    }
}

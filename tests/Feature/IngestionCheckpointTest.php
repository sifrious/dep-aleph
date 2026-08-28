<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\CheckpointConflict;
use Sifrious\Aleph\Ingestion\CheckpointRule;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRun;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

function checkpointFixture(): array
{
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Checkpoint source');
    $stream = app(SourceStreams::class)->create(
        $installation->id,
        'channel:C1',
        'slack_channel',
        'C1',
    );
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        'slack:channel/C1',
        Capability::IncrementalSync,
        [],
        connectorId: 'slack',
        sourceInstallationId: $installation->id,
    );
    $attempt = $runs->beginAttempt($run);
    $accepted = acceptCheckpointObservation($run, 'message:1');
    $runs->recordProgress($run, $attempt, [], ['accepted' => 1], [$accepted]);

    return [$stream, $run, $attempt, $accepted];
}

function acceptCheckpointObservation(IngestionRun $run, string $resource): string
{
    $record = app(EnvelopeSubmitter::class)->submit(new ObservationEnvelope(
        sourceReference: $run->sourceReference,
        sourceName: 'Slack channel C1',
        resourceReference: 'slack:'.$resource,
        observedAt: new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
        payload: $resource,
        provenance: new Provenance(
            'slack',
            '1.0.0',
            (string) $run->sourceInstallationId,
            new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
            $run->id,
        ),
    ));

    return (string) $record->acceptedId();
}

function cursor(int $position, string $value, string $serializer = '1'): CheckpointValue
{
    return new CheckpointValue('slack.cursor', $serializer, $value, CheckpointRule::Monotonic, $position);
}

it('commits an initial typed checkpoint through accepted Funes history', function (): void {
    [$stream, $run, $attempt, $accepted] = checkpointFixture();

    $checkpoint = app(IngestionCheckpoints::class)->commit(
        $stream,
        Capability::IncrementalSync,
        'history',
        cursor(100, 'cursor-100'),
        0,
        $run,
        [$accepted],
        $attempt,
    );

    expect($checkpoint->version)->toBe(1)
        ->and($checkpoint->value->format)->toBe('slack.cursor')
        ->and($checkpoint->value->serializerVersion)->toBe('1')
        ->and($checkpoint->value->value)->toBe('cursor-100')
        ->and($checkpoint->acceptedReferences)->toBe([$accepted])
        ->and($checkpoint->runId)->toBe($run->id)
        ->and($checkpoint->attemptId)->toBe($attempt->id);
});

it('rejects a competing optimistic version', function (): void {
    [$stream, $run, $attempt, $accepted] = checkpointFixture();
    $checkpoints = app(IngestionCheckpoints::class);
    $checkpoints->commit($stream, Capability::IncrementalSync, 'history', cursor(100, 'cursor-100'), 0, $run, [$accepted], $attempt);

    expect(fn () => $checkpoints->commit(
        $stream,
        Capability::IncrementalSync,
        'history',
        cursor(110, 'cursor-110'),
        0,
        $run,
        [$accepted],
        $attempt,
    ))->toThrow(CheckpointConflict::class, 'current version is 1');
});

it('does not advance through a reference Funes rejected or never accepted', function (): void {
    [$stream, $run, $attempt] = checkpointFixture();
    $runs = app(IngestionRuns::class);
    $runs->recordProgress($run, $attempt, [], ['accepted' => 1], ['01MISSINGFUNESREFERENCE000']);

    expect(fn () => app(IngestionCheckpoints::class)->commit(
        $stream,
        Capability::IncrementalSync,
        'history',
        cursor(100, 'cursor-100'),
        0,
        $run,
        ['01MISSINGFUNESREFERENCE000'],
        $attempt,
    ))->toThrow(CheckpointConflict::class, 'Funes has not accepted');
});

it('isolates versions by stream capability and partition', function (): void {
    [$stream, $run, $attempt, $accepted] = checkpointFixture();
    $checkpoints = app(IngestionCheckpoints::class);

    $history = $checkpoints->commit($stream, Capability::IncrementalSync, 'history', cursor(100, 'history-100'), 0, $run, [$accepted], $attempt);
    $threads = $checkpoints->commit($stream, Capability::IncrementalSync, 'threads', cursor(3, 'threads-3'), 0, $run, [$accepted], $attempt);
    $runs = app(IngestionRuns::class);
    $healthRun = $runs->start('slack:channel/C1', Capability::CheckHealth, [], connectorId: 'slack', sourceInstallationId: $stream->sourceInstallationId);
    $healthAttempt = $runs->beginAttempt($healthRun);
    $healthAccepted = acceptCheckpointObservation($healthRun, 'health:1');
    $runs->recordProgress($healthRun, $healthAttempt, [], ['accepted' => 1], [$healthAccepted]);
    $health = $checkpoints->commit($stream, Capability::CheckHealth, 'history', new CheckpointValue('health.time', '1', '2026-08-28T10:00:00Z'), 0, $healthRun, [$healthAccepted], $healthAttempt);

    expect($history->version)->toBe(1)
        ->and($threads->version)->toBe(1)
        ->and($health->version)->toBe(1)
        ->and($checkpoints->latest($stream, Capability::IncrementalSync, 'threads')?->value->value)->toBe('threads-3');
});

it('retains serializer versions and complete ordered history', function (): void {
    [$stream, $run, $attempt, $firstAccepted] = checkpointFixture();
    $checkpoints = app(IngestionCheckpoints::class);
    $first = $checkpoints->commit($stream, Capability::IncrementalSync, 'history', cursor(100, 'cursor-100'), 0, $run, [$firstAccepted], $attempt);
    $secondAccepted = acceptCheckpointObservation($run, 'message:2');
    app(IngestionRuns::class)->recordProgress($run, $attempt, [], ['accepted' => 2], [$firstAccepted, $secondAccepted]);
    $second = $checkpoints->commit($stream, Capability::IncrementalSync, 'history', cursor(200, '{"cursor":"200"}', '2'), 1, $run, [$firstAccepted, $secondAccepted], $attempt);
    $history = $checkpoints->history($stream, Capability::IncrementalSync, 'history');

    expect(array_map(fn ($checkpoint) => $checkpoint->id, $history))->toBe([$first->id, $second->id])
        ->and(array_map(fn ($checkpoint) => $checkpoint->version, $history))->toBe([1, 2])
        ->and($history[1]->value->serializerVersion)->toBe('2')
        ->and($history[1]->acceptedReferences)->toBe([$firstAccepted, $secondAccepted]);
});

it('enforces monotonic movement while replace checkpoints may move freely', function (): void {
    [$stream, $run, $attempt, $accepted] = checkpointFixture();
    $checkpoints = app(IngestionCheckpoints::class);
    $checkpoints->commit($stream, Capability::IncrementalSync, 'history', cursor(100, 'cursor-100'), 0, $run, [$accepted], $attempt);

    expect(fn () => $checkpoints->commit($stream, Capability::IncrementalSync, 'history', cursor(99, 'cursor-99'), 1, $run, [$accepted], $attempt))
        ->toThrow(CheckpointConflict::class, 'must advance');

    $first = $checkpoints->commit($stream, Capability::IncrementalSync, 'snapshot', new CheckpointValue('snapshot.json', '1', '{"page":9}'), 0, $run, [$accepted], $attempt);
    $second = $checkpoints->commit($stream, Capability::IncrementalSync, 'snapshot', new CheckpointValue('snapshot.json', '1', '{"page":2}'), 1, $run, [$accepted], $attempt);

    expect($first->version)->toBe(1)
        ->and($second->version)->toBe(2)
        ->and($second->value->value)->toBe('{"page":2}');
});

it('replays an identical commit without adding history', function (): void {
    [$stream, $run, $attempt, $accepted] = checkpointFixture();
    $checkpoints = app(IngestionCheckpoints::class);
    $value = cursor(100, 'cursor-100');
    $first = $checkpoints->commit($stream, Capability::IncrementalSync, 'history', $value, 0, $run, [$accepted], $attempt);
    $replay = $checkpoints->commit($stream, Capability::IncrementalSync, 'history', $value, 0, $run, [$accepted], $attempt);

    expect($replay->id)->toBe($first->id)
        ->and($checkpoints->history($stream, Capability::IncrementalSync, 'history'))->toHaveCount(1);
});

it('lists only active streams and refuses a disabled stream commit', function (): void {
    [$stream, $run, $attempt, $accepted] = checkpointFixture();
    $streams = app(SourceStreams::class);

    expect($streams->active($stream->sourceInstallationId))->toHaveCount(1);

    $streams->disable($stream);
    $disabled = $streams->find($stream->id);

    expect($streams->active($stream->sourceInstallationId))->toBe([])
        ->and(fn () => app(IngestionCheckpoints::class)->commit(
            $disabled,
            Capability::IncrementalSync,
            'history',
            cursor(100, 'cursor-100'),
            0,
            $run,
            [$accepted],
            $attempt,
        ))->toThrow(CheckpointConflict::class, 'disabled');
});

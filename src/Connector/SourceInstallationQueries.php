<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use DateTimeImmutable;
use Sifrious\Aleph\Connector\Health\ConnectorHealthQueries;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRunQueries;
use Sifrious\Aleph\Ingestion\IngestionSchedules;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Ingestion\SourceStreamStatuses;

final readonly class SourceInstallationQueries
{
    public function __construct(
        private ConnectorInstallations $installations,
        private ConnectorHealthQueries $health,
        private SourceStreams $streams,
        private SourceStreamStatuses $statuses,
        private IngestionCheckpoints $checkpoints,
        private IngestionSchedules $schedules,
        private IngestionRunQueries $runs,
    ) {}

    /** @return list<ConnectorInstallation> */
    public function all(): array
    {
        return $this->installations->all();
    }

    public function find(string $id, DateTimeImmutable $now): ?SourceInstallationState
    {
        $installation = $this->installations->find($id);

        if ($installation === null) {
            return null;
        }

        $streams = array_map(function ($stream) use ($now): array {
            return [
                ...$stream->toArray(),
                'status' => $this->statuses->find($stream, $now)->toArray(),
                'checkpoints' => array_map(
                    static fn ($checkpoint): array => $checkpoint->toArray(),
                    $this->checkpoints->latestForStream($stream),
                ),
            ];
        }, $this->streams->active($id));

        return new SourceInstallationState(
            $installation,
            $this->health->forInstallation($id, $now),
            $streams,
            $this->schedules->forInstallation($id),
            $this->runs->forInstallation($id, 10),
            $now,
        );
    }
}

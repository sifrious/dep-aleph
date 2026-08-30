<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchScoreTabIngestionRequest
{
    private function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public LaunchAuthorization $authorization,
        public ?string $path,
        public ?LocalScoreTabFilePayload $file,
    ) {}

    public static function fromPath(
        string $sourceInstallationId,
        string $sourceReference,
        string $path,
        LaunchAuthorization $authorization,
    ): self {
        return new self($sourceInstallationId, $sourceReference, $authorization, $path, null);
    }

    public static function fromFile(
        string $sourceInstallationId,
        string $sourceReference,
        LocalScoreTabFilePayload $file,
        LaunchAuthorization $authorization,
    ): self {
        return new self($sourceInstallationId, $sourceReference, $authorization, null, $file);
    }
}

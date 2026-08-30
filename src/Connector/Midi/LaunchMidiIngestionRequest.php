<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchMidiIngestionRequest
{
    private function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public LaunchAuthorization $authorization,
        public ?string $path,
        public ?LocalMidiFilePayload $file,
    ) {}

    public static function fromPath(
        string $sourceInstallationId,
        string $sourceReference,
        string $path,
        LaunchAuthorization $authorization,
    ): self {
        return new self(
            $sourceInstallationId,
            $sourceReference,
            $authorization,
            $path,
            null,
        );
    }

    public static function fromFile(
        string $sourceInstallationId,
        string $sourceReference,
        LocalMidiFilePayload $file,
        LaunchAuthorization $authorization,
    ): self {
        return new self(
            $sourceInstallationId,
            $sourceReference,
            $authorization,
            null,
            $file,
        );
    }
}

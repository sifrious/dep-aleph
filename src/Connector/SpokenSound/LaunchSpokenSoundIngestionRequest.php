<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\SpokenSound;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchSpokenSoundIngestionRequest
{
    /**
     * @param  array<string, mixed>  $containerMetadata
     */
    private function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public LaunchAuthorization $authorization,
        public ?string $path,
        public ?LocalSpokenSoundFilePayload $file,
        public ?float $durationSeconds,
        public array $containerMetadata,
    ) {}

    /**
     * @param  array<string, mixed>  $containerMetadata
     */
    public static function fromPath(
        string $sourceInstallationId,
        string $sourceReference,
        string $path,
        LaunchAuthorization $authorization,
        ?float $durationSeconds = null,
        array $containerMetadata = [],
    ): self {
        return new self(
            $sourceInstallationId,
            $sourceReference,
            $authorization,
            $path,
            null,
            $durationSeconds,
            $containerMetadata,
        );
    }

    /**
     * @param  array<string, mixed>  $containerMetadata
     */
    public static function fromFile(
        string $sourceInstallationId,
        string $sourceReference,
        LocalSpokenSoundFilePayload $file,
        LaunchAuthorization $authorization,
        ?float $durationSeconds = null,
        array $containerMetadata = [],
    ): self {
        return new self(
            $sourceInstallationId,
            $sourceReference,
            $authorization,
            null,
            $file,
            $durationSeconds,
            $containerMetadata,
        );
    }
}

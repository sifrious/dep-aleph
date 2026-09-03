<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

use Sifrious\Aleph\Ingestion\IngestLanguage;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchLocalVideoIngestionRequest
{
    private function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public LaunchAuthorization $authorization,
        public ?string $path,
        public ?LocalVideoFilePayload $file,
        public IngestLanguage $language = IngestLanguage::Any,
    ) {}

    public static function fromPath(
        string $sourceInstallationId,
        string $sourceReference,
        string $path,
        LaunchAuthorization $authorization,
        IngestLanguage $language = IngestLanguage::Any,
    ): self {
        return new self($sourceInstallationId, $sourceReference, $authorization, $path, null, $language);
    }

    public static function fromFile(
        string $sourceInstallationId,
        string $sourceReference,
        LocalVideoFilePayload $file,
        LaunchAuthorization $authorization,
        IngestLanguage $language = IngestLanguage::Any,
    ): self {
        return new self($sourceInstallationId, $sourceReference, $authorization, null, $file, $language);
    }
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum QueueClass: string
{
    case Ingest = 'ingest';
    case Normalize = 'normalize';
    case Media = 'media';
    case Agentic = 'agentic';

    public static function for(Capability $capability): self
    {
        return match ($capability) {
            Capability::DownloadArtifact => self::Media,
            default => self::Ingest,
        };
    }
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class LaunchAuthorization
{
    private function __construct(
        public bool $granted,
        public string $actorReference,
        public string $decisionReference,
        public ?string $reason,
    ) {}

    public static function granted(string $actorReference, string $decisionReference): self
    {
        return new self(true, $actorReference, $decisionReference, null);
    }

    public static function denied(
        string $actorReference,
        string $decisionReference,
        string $reason,
    ): self {
        return new self(false, $actorReference, $decisionReference, $reason);
    }
}

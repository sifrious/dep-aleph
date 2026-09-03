<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

enum IngestLanguage: string
{
    case Php = 'php';
    case Python = 'python';
    case Any = 'any';

    public function isConcrete(): bool
    {
        return $this !== self::Any;
    }

    public static function fromInput(string $value): self
    {
        $normalized = strtolower(trim($value));

        return self::tryFrom($normalized)
            ?? throw new InvalidArgumentException("Unknown ingest language [{$value}]. Expected php, python, or any.");
    }
}

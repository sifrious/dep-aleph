<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

enum NormalizationStatus: string
{
    case Succeeded = 'succeeded';
    case Empty = 'empty';
    case Partial = 'partial';
    case Unsupported = 'unsupported';
    case Malformed = 'malformed';
    case Invalid = 'invalid';
    case Failed = 'failed';

    public function producedCandidates(): bool
    {
        return $this === self::Succeeded || $this === self::Partial;
    }

    public function isFailure(): bool
    {
        return match ($this) {
            self::Succeeded, self::Empty, self::Partial => false,
            default => true,
        };
    }
}

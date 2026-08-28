<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Acceptance;

enum SubmissionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Replayed = 'replayed';
    case Rejected = 'rejected';
    case InFlight = 'in_flight';
    case TransportFailed = 'transport_failed';

    public function isAuthoritative(): bool
    {
        return $this === self::Accepted || $this === self::Replayed;
    }

    public function shouldRetry(): bool
    {
        return match ($this) {
            self::TransportFailed, self::InFlight, self::Pending => true,
            self::Accepted, self::Replayed, self::Rejected => false,
        };
    }
}

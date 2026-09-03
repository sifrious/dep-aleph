<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use RuntimeException;

final class GmailHistoryExpired extends RuntimeException
{
    public static function forHistoryId(string $historyId): self
    {
        return new self("Gmail history [{$historyId}] has expired. Start a new full synchronization.");
    }
}

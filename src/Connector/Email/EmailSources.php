<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use InvalidArgumentException;

final class EmailSources
{
    /** @var array<string, EmailSource> */
    private array $sources = [];

    public function register(EmailSource $source): void
    {
        $this->sources[$source->sourceReference()] = $source;
    }

    public function get(string $reference): EmailSource
    {
        return $this->sources[$reference]
            ?? throw new InvalidArgumentException("Email source [{$reference}] is not registered.");
    }
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

interface EmailSource
{
    public function sourceReference(): string;

    public function checkpointType(): string;

    public function page(?string $checkpoint, int $limit): EmailPage;
}

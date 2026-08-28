<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

interface CommunicationSource
{
    public function sourceReference(): string;

    public function provider(): CommunicationProvider;

    public function checkpointType(): string;

    public function page(?string $checkpoint, int $limit): CommunicationPage;
}

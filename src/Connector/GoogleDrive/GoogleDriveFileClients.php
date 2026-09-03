<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

final class GoogleDriveFileClients
{
    /** @var array<string, GoogleDriveFileClient> */
    private array $clients = [];

    public function register(string $sourceReference, GoogleDriveFileClient $client): void
    {
        $this->clients[$sourceReference] = $client;
    }

    public function for(string $sourceReference, GoogleDriveFileClient $default): GoogleDriveFileClient
    {
        return $this->clients[$sourceReference] ?? $default;
    }
}

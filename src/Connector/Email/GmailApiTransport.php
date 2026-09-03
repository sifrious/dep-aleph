<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

interface GmailApiTransport
{
    /**
     * @param  array<string, bool|int|string>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array;
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

interface SlackWebApiTransport
{
    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    public function get(string $method, SlackTokenSecret $token, array $query): array;
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

interface LinearGraphqlTransport
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $document, array $variables): array;
}

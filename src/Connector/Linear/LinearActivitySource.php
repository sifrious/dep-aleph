<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

interface LinearActivitySource
{
    public function sourceReference(): string;

    public function page(LinearStream $stream, ?string $cursor, int $limit): LinearActivityPage;
}

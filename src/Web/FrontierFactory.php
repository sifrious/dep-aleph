<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Illuminate\Database\ConnectionInterface;
use Sifrious\Aleph\Ingestion\IngestionRun;

final readonly class FrontierFactory
{
    public function __construct(private ConnectionInterface $connection) {}

    public function for(WebSource $source, IngestionRun $run): Frontier
    {
        return new Frontier($this->connection, $source->canonicalizer(), $run->id);
    }
}

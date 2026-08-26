<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Illuminate\Database\ConnectionInterface;

final readonly class FrontierFactory
{
    public function __construct(private ConnectionInterface $connection) {}

    public function for(WebSource $source, CrawlRun $run): Frontier
    {
        return new Frontier($this->connection, $source->canonicalizer(), $run->id);
    }
}

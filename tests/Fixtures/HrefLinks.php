<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Tests\Fixtures;

use Sifrious\Aleph\Web\FetchResult;
use Sifrious\Aleph\Web\LinkSource;

final class HrefLinks implements LinkSource
{
    /**
     * @return list<string>
     */
    public function linksFrom(FetchResult $result): array
    {
        preg_match_all('/href="([^"]+)"/', (string) $result->body, $matches);

        return array_values($matches[1]);
    }
}

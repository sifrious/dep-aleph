<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

final readonly class NoLinks implements LinkSource
{
    /**
     * @return list<string>
     */
    public function linksFrom(FetchResult $result): array
    {
        return [];
    }
}

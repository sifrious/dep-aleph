<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

interface LinkSource
{
    /**
     * @return list<string>
     */
    public function linksFrom(FetchResult $result): array;
}

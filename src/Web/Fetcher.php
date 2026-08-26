<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

interface Fetcher
{
    public function fetch(FetchRequest $request): FetchResult;
}

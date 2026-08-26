<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

final readonly class FetchRequest
{
    public function __construct(
        public CanonicalUrl $url,
        public HttpMethod $method = HttpMethod::Get,
    ) {}
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Contracts;

use Sifrious\Aleph\Normalization\Normalizer;

interface Normalizes
{
    /**
     * @return list<Normalizer>
     */
    public function normalizers(): array;
}

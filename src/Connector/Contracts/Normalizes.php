<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Contracts;

use Sifrious\Aleph\Connector\Values\NormalizedRecord;
use Sifrious\Aleph\Connector\Values\RawRecord;

interface Normalizes
{
    public function normalize(RawRecord $record): NormalizedRecord;
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Contracts;

use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ExtractedContent;

interface ExtractsContent
{
    public function extractContent(Artifact $artifact): ExtractedContent;
}

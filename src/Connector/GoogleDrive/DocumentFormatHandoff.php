<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

interface DocumentFormatHandoff
{
    public function handOff(DocumentFormatHandoffRequest $request): DocumentFormatHandoffResult;
}

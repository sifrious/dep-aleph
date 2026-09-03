<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

interface DocumentFormatter
{
    public function supports(string $mediaType): bool;

    public function format(DocumentFormatHandoffRequest $request): FormattedDocument;
}

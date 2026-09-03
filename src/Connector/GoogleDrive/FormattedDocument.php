<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

final readonly class FormattedDocument
{
    public function __construct(
        public string $formatter,
        public string $version,
        public string $text,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extractionResult(DocumentFormatHandoffRequest $request): array
    {
        return [
            'kind' => 'document_text',
            'formatter' => $this->formatter,
            'version' => $this->version,
            'source' => [
                'artifact_reference' => $request->artifactReference,
                'filename' => $request->filename,
                'media_type' => $request->mediaType,
                'sha256' => $request->checksum,
                'bytes' => $request->bytes,
            ],
            'text' => $this->text,
        ];
    }
}

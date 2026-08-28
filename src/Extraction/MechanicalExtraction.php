<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

use InvalidArgumentException;

final readonly class MechanicalExtraction
{
    /**
     * @param  array<string, mixed>|null  $result
     * @param  list<DiscoveredReference>  $discoveries
     */
    public function __construct(
        public ObservationFormat $format,
        public string $extractor,
        public string $version,
        public ?array $result,
        public array $discoveries,
        public ?string $failure = null,
    ) {
        if (($result === null) === ($failure === null)) {
            throw new InvalidArgumentException('A mechanical extraction must contain either a result or a failure.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

use InvalidArgumentException;

/**
 * Opaque derived score/tab representation produced by an optional free local model.
 * Graph schema ownership stays with Funes MME-2202; this payload is stored via the
 * generic extracted-representation store (Funes MME-1438).
 */
final readonly class ScoreTabModelDerivation
{
    /**
     * @param  array<string, mixed>  $representation
     */
    public function __construct(
        public string $modelName,
        public string $modelVersion,
        public array $representation,
    ) {
        if (trim($modelName) === '' || trim($modelVersion) === '') {
            throw new InvalidArgumentException('A score/tab model derivation requires a model name and version.');
        }
    }
}

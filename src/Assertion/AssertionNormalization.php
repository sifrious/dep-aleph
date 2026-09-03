<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Assertion;

use Sifrious\Funes\Assertion\AbstractHistoricalAssertion;
use Sifrious\ReferenceContract\CrossPackageReference;

final readonly class AssertionNormalization
{
    /** @param list<string> $omittedFields */
    public function __construct(
        public AbstractHistoricalAssertion $assertion,
        public CrossPackageReference $rawSource,
        public array $omittedFields = [],
        public ?float $confidence = null,
    ) {
        if ($confidence !== null && ($confidence < 0 || $confidence > 1)) {
            throw new AssertionNormalizationException('Assertion confidence must be between zero and one.');
        }
    }

    public function isLossy(): bool
    {
        return $this->omittedFields !== [];
    }
}

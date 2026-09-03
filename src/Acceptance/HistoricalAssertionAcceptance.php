<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Acceptance;

use Sifrious\Aleph\Assertion\AssertionNormalization;
use Sifrious\Aleph\Assertion\HistoricalAssertionAdapters;
use Sifrious\Aleph\Assertion\ProviderAssertionInput;
use Sifrious\Funes\Assertion\AcceptedAssertion;
use Sifrious\Funes\Assertion\HistoricalAssertionStore;

final readonly class HistoricalAssertionAcceptance
{
    public function __construct(private HistoricalAssertionAdapters $adapters, private HistoricalAssertionStore $store) {}

    public function normalize(ProviderAssertionInput $input): AssertionNormalization
    {
        return $this->adapters->for($input->provider)->normalize($input);
    }

    public function submit(ProviderAssertionInput $input): AcceptedAssertion
    {
        $normalized = $this->normalize($input);

        return $this->store->append($normalized->assertion, $input->authorization);
    }
}

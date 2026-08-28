<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class BackfillRequest
{
    /**
     * @param  list<string>  $partitions
     */
    public function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public string $scope,
        public BackfillRange $range,
        public array $partitions,
        public bool $force,
        public string $normalizerVersion,
        public BackfillRateLimit $rateLimit,
        public ContinuationBudget $budget,
        public string $idempotencyKey,
        public LaunchAuthorization $authorization,
    ) {}
}

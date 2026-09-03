<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

final readonly class Frontier
{
    public function __construct(
        private ConnectionInterface $connection,
        private UrlCanonicalizer $canonicalizer,
        private string $runId,
        private string $sourceReference,
    ) {}

    public function record(
        CanonicalUrl $url,
        string $requestedUrl,
        int $depth,
        DiscoveryOrigin $origin,
        ?int $parentId,
        FrontierState $state,
        ?SkipReason $skipReason = null,
        ?string $finalUrl = null,
    ): ?int {
        $hash = $url->hash();

        $inserted = $this->table()->insertOrIgnore([
            'run_id' => $this->runId,
            'parent_id' => $parentId,
            'canonical_url' => $url->value,
            'canonical_hash' => $hash,
            'requested_url' => $requestedUrl,
            'host' => $url->host,
            'depth' => $depth,
            'origin' => $origin->value,
            'state' => $state->value,
            'skip_reason' => $skipReason?->value,
            'final_url' => $finalUrl,
            'created_at' => Carbon::now(),
        ]);

        if ($inserted === 0) {
            return null;
        }

        $id = $this->table()
            ->where('run_id', $this->runId)
            ->where('canonical_hash', $hash)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    public function knownFinal(CanonicalUrl $requested): ?CanonicalUrl
    {
        $value = $this->connection->table('aleph_web_redirect_aliases')
            ->where('source_reference', $this->sourceReference())
            ->where('requested_hash', $requested->hash())
            ->value('final_url');

        return is_string($value) ? $this->canonicalizer->canonicalize($value) : null;
    }

    public function rememberRedirect(
        FrontierCandidate $candidate,
        CanonicalUrl $final,
        AcceptedRetrieval $accepted,
    ): void {
        if ($candidate->url->hash() === $final->hash()) {
            return;
        }

        $now = Carbon::now();
        $this->connection->table('aleph_web_redirect_aliases')->upsert([[
            'source_reference' => $this->sourceReference(),
            'requested_url' => $candidate->url->value,
            'requested_hash' => $candidate->url->hash(),
            'final_url' => $final->value,
            'final_hash' => $final->hash(),
            'observation_id' => $accepted->observationId,
            'observed_at' => $accepted->observedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['source_reference', 'requested_hash'], [
            'requested_url',
            'final_url',
            'final_hash',
            'observation_id',
            'observed_at',
            'updated_at',
        ]);
    }

    public function claimNext(): ?FrontierCandidate
    {
        $row = $this->table()
            ->where('run_id', $this->runId)
            ->where('state', FrontierState::Pending->value)
            ->orderBy('depth')
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $url = $this->canonicalizer->canonicalize((string) $row->canonical_url);

        if ($url === null) {
            $this->table()->where('id', $row->id)->update([
                'state' => FrontierState::Failed->value,
                'failure_message' => 'Stored canonical url could not be parsed.',
            ]);

            return $this->claimNext();
        }

        $this->table()->where('id', $row->id)->update(['state' => FrontierState::Fetching->value]);

        return new FrontierCandidate(
            id: (int) $row->id,
            url: $url,
            requestedUrl: (string) $row->requested_url,
            depth: (int) $row->depth,
            origin: DiscoveryOrigin::from((string) $row->origin),
            parentId: $row->parent_id === null ? null : (int) $row->parent_id,
        );
    }

    public function markFetched(
        FrontierCandidate $candidate,
        FetchResult $result,
        AcceptedRetrieval $accepted,
    ): void {
        $this->table()->where('id', $candidate->id)->update([
            'state' => FrontierState::Fetched->value,
            'final_url' => $result->finalUrl,
            'http_status' => $result->status,
            'content_type' => $result->contentType,
            'observation_id' => $accepted->observationId,
            'observation_disposition' => $accepted->disposition->value,
            'payload_hash' => $accepted->payloadHash,
            'byte_size' => $accepted->byteSize,
            'extractor' => $accepted->extractor,
            'extraction_version' => $accepted->extractionVersion,
            'extraction_status' => $accepted->extractionStatus->value,
            'extraction_error' => $accepted->extractionError,
            'observed_at' => $accepted->observedAt,
            'ingested_at' => $accepted->ingestedAt,
        ]);
    }

    public function markFailed(FrontierCandidate $candidate, FetchResult $result): void
    {
        $this->table()->where('id', $candidate->id)->update([
            'state' => FrontierState::Failed->value,
            'final_url' => $result->finalUrl,
            'http_status' => $result->status,
            'failure' => $result->failure?->value,
            'failure_message' => $result->failureMessage,
            'observed_at' => $result->retrievedAt,
        ]);
    }

    public function releaseClaimed(): int
    {
        return $this->table()
            ->where('run_id', $this->runId)
            ->where('state', FrontierState::Fetching->value)
            ->update(['state' => FrontierState::Pending->value]);
    }

    public function countUnsuccessful(): int
    {
        return $this->table()
            ->where('run_id', $this->runId)
            ->where('state', FrontierState::Fetched->value)
            ->where(fn (Builder $query) => $query->where('http_status', '<', 200)->orWhere('http_status', '>=', 300))
            ->count();
    }

    public function countByState(FrontierState $state): int
    {
        return $this->table()
            ->where('run_id', $this->runId)
            ->where('state', $state->value)
            ->count();
    }

    /**
     * @return array<string, int>
     */
    public function skippedByReason(): array
    {
        $rows = $this->table()
            ->where('run_id', $this->runId)
            ->where('state', FrontierState::Skipped->value)
            ->whereNotNull('skip_reason')
            ->selectRaw('skip_reason, count(*) as total')
            ->groupBy('skip_reason')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->skip_reason] = (int) $row->total;
        }

        ksort($counts);

        return $counts;
    }

    public function isEmpty(): bool
    {
        return ! $this->table()->where('run_id', $this->runId)->exists();
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_frontier_candidates');
    }

    private function sourceReference(): string
    {
        return $this->sourceReference;
    }
}

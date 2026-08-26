<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final readonly class CrawlRuns
{
    public function __construct(private ConnectionInterface $connection) {}

    public function start(WebSource $source): CrawlRun
    {
        $run = new CrawlRun(
            id: (string) Str::ulid(),
            source: $source->key,
            status: RunStatus::Running,
            limits: $source->limits,
            startedAt: new DateTimeImmutable,
        );

        $this->table()->insert([
            'id' => $run->id,
            'source' => $run->source,
            'status' => $run->status->value,
            'limits' => json_encode($run->limits->toArray(), JSON_THROW_ON_ERROR),
            'started_at' => $run->startedAt,
        ]);

        return $run;
    }

    public function latestRunning(string $source): ?CrawlRun
    {
        $row = $this->table()
            ->where('source', $source)
            ->where('status', RunStatus::Running->value)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $limits = json_decode((string) $row->limits, true, 512, JSON_THROW_ON_ERROR);

        return new CrawlRun(
            id: (string) $row->id,
            source: (string) $row->source,
            status: RunStatus::from((string) $row->status),
            limits: new CrawlLimits(
                maxPages: is_array($limits) && is_int($limits['max_pages'] ?? null) ? $limits['max_pages'] : 1,
                maxDepth: is_array($limits) && is_int($limits['max_depth'] ?? null) ? $limits['max_depth'] : 0,
            ),
            startedAt: new DateTimeImmutable((string) $row->started_at),
        );
    }

    public function complete(CrawlRun $run, CrawlSummary $summary): void
    {
        $this->finish($run, RunStatus::Completed, $summary->toArray());
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    public function abort(CrawlRun $run, array $totals): void
    {
        $this->finish($run, RunStatus::Aborted, $totals);
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function finish(CrawlRun $run, RunStatus $status, array $totals): void
    {
        $this->table()->where('id', $run->id)->update([
            'status' => $status->value,
            'totals' => json_encode($totals, JSON_THROW_ON_ERROR),
            'finished_at' => Carbon::now(),
        ]);
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_crawl_runs');
    }
}

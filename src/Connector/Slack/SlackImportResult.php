<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

final readonly class SlackImportResult
{
    /**
     * @param  array<string, string>  $checkpoints
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(public int $pages, public int $activities, public bool $complete, public array $checkpoints, public array $acceptedReferences) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['pages' => $this->pages, 'activities' => $this->activities, 'complete' => $this->complete, 'checkpoints' => $this->checkpoints, 'accepted_references' => $this->acceptedReferences];
    }
}

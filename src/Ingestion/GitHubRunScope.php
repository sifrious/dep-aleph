<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum GitHubRunScope: string
{
    case All = 'all';
    case Project = 'project';
    case Repository = 'repo';
    case PullRequest = 'pull-request';
    case Watch = 'watch';
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

enum GitHubActivityKind: string
{
    case Repository = 'repository';
    case PullRequest = 'pull_request';
    case Review = 'review';
    case Comment = 'comment';
    case Reaction = 'reaction';
    case Contributor = 'contributor';
}

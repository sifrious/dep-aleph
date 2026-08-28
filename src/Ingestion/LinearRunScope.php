<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum LinearRunScope: string
{
    case All = 'all';
    case Workspace = 'workspace';
    case Project = 'project';
}

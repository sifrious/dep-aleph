<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

enum LinearStream: string
{
    case Projects = 'projects';
    case Issues = 'issues';
    case Milestones = 'milestones';
    case Updates = 'updates';
    case Reports = 'reports';
    case Tasks = 'tasks';
    case Links = 'links';
}

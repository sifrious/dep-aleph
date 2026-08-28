<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

enum LinearActivityKind: string
{
    case Project = 'project';
    case Issue = 'issue';
    case Milestone = 'milestone';
    case Update = 'update';
    case Report = 'report';
    case Task = 'task';
    case Link = 'link';
}

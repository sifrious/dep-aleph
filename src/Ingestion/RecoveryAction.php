<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum RecoveryAction: string
{
    case Start = 'start';
    case Resume = 'resume';
    case Retry = 'retry';
    case Restart = 'restart';
    case ProvideCredentials = 'provide_credentials';
    case UserAction = 'user_action';
    case None = 'none';
}

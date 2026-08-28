<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

enum EmailProvider: string
{
    case Gmail = 'gmail';
    case MicrosoftGraph = 'microsoft_graph';
    case Imap = 'imap';
}

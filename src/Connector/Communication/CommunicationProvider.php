<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

enum CommunicationProvider: string
{
    case Telegram = 'telegram';
    case Sms = 'sms';
    case Discord = 'discord';
}

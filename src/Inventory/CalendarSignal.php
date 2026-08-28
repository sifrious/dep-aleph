<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Inventory;

enum CalendarSignal: string
{
    case Path = 'path';
    case EmbeddedInCalendar = 'embedded_in_calendar';
}

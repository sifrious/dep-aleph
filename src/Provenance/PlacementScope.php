<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Provenance;

enum PlacementScope: string
{
    case Sentence = 'sentence';
    case Paragraph = 'paragraph';
    case Section = 'section';
    case Document = 'document';
}

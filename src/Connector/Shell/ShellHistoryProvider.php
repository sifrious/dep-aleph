<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

enum ShellHistoryProvider: string
{
    case Zsh = 'zsh_history';
    case Atuin = 'atuin';
    case ClaudeBash = 'claude_bash';
}

<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

final readonly class ShellRedactionPolicy
{
    public function apply(ShellCommandObservation $observation): RedactedShellCommand
    {
        $reasons = [];
        $command = $this->redact($observation->command, $reasons);
        $output = $observation->output === null ? null : $this->redact($observation->output, $reasons);
        $reasons = array_values(array_unique($reasons));

        return new RedactedShellCommand(
            $command,
            $output,
            hash('sha256', $observation->command),
            $reasons === [] ? RedactionDecision::Retained : RedactionDecision::Redacted,
            $reasons,
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    private function redact(string $value, array &$reasons): string
    {
        $patterns = [
            'credential_assignment' => '/\b([A-Z0-9_]*(?:TOKEN|SECRET|PASSWORD|API_KEY|AUTHORIZATION)[A-Z0-9_]*)=(?:"[^"]*"|\'[^\']*\'|[^\s]+)/i',
            'bearer_token' => '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            'provider_token' => '/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9_-]{20,})\b/',
            'url_credentials' => '/(?<=:\/\/)[^\s\/@:]+:[^\s\/@]+@/',
        ];

        foreach ($patterns as $reason => $pattern) {
            $matched = false;
            $value = preg_replace_callback($pattern, static function (array $match) use (&$matched, $reason): string {
                $matched = true;

                if ($reason === 'credential_assignment') {
                    return ($match[1] ?? 'SECRET').'=[REDACTED]';
                }

                return $reason === 'url_credentials' ? '[REDACTED]@' : '[REDACTED]';
            }, $value) ?? $value;

            if ($matched) {
                $reasons[] = $reason;
            }
        }

        return $value;
    }
}

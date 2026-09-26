<?php

declare(strict_types=1);

namespace FortKnox\IRC;

final class IrcProtocolParser
{
    public function parse(string $line): ?IrcMessage
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        if (!str_starts_with($line, ':')) {
            return null;
        }

        $space = strpos($line, ' ');

        if ($space === false) {
            return null;
        }

        $prefix = substr($line, 1, $space - 1);
        $remaining = substr($line, $space + 1);

        $parts = explode(' :', $remaining, 2);

        $commandAndParams = trim($parts[0]);
        $trailing = $parts[1] ?? '';

        $tokens = preg_split(
            '/\s+/',
            $commandAndParams
        );

        if ($tokens === false || count($tokens) < 2) {
            return null;
        }

        $command = strtoupper($tokens[0]);
        $channel = $tokens[1];

        if ($command !== 'PRIVMSG') {
            return null;
        }

        if ($trailing === '') {
            return null;
        }

        $sender = $this->extractNick($prefix);

        return new IrcMessage(
            sender: $sender,
            channel: $channel,
            text: $trailing
        );
    }

    private function extractNick(string $prefix): string
    {
        $position = strpos($prefix, '!');

        if ($position === false) {
            return $prefix;
        }

        return substr($prefix, 0, $position);
    }
}

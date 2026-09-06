<?php

declare(strict_types=1);

namespace FortKnox\IRC;

final class IrcMessage
{
    public function __construct(
        private readonly string $sender,
        private readonly string $channel,
        private readonly string $text,
    ) {
    }

    public function sender(): string
    {
        return $this->sender;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function text(): string
    {
        return $this->text;
    }
}

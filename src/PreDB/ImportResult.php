<?php

declare(strict_types=1);

namespace FortKnox\PreDB;

final class ImportResult
{
    public function __construct(
        public readonly array $release,
        public readonly array $events = [],
    ) {
    }

    public function imported(): bool
    {
        return true;
    }

    public function releaseId(): int|string
    {
        return $this->release['id'] ?? 0;
    }

    public function release(): object
    {
        return (object) [
            'id' => $this->release['id'] ?? 0,
            'releaseName' => fn() => $this->release['release_name'] ?? ($this->release['name'] ?? ''),
        ];
    }
}

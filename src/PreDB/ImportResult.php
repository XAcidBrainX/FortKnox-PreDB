<?php

declare(strict_types=1);

namespace FortKnox\PreDB;

final class ImportResult
{
    public function __construct(
        private readonly bool $imported,
        private readonly int $releaseId,
        private readonly ?int $groupId,
        private readonly string $eventType,
        private readonly ParsedRelease $release,
    ) {
    }

    public function imported(): bool
    {
        return $this->imported;
    }

    public function duplicate(): bool
    {
        return !$this->imported;
    }

    public function releaseId(): int
    {
        return $this->releaseId;
    }

    public function groupId(): ?int
    {
        return $this->groupId;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function release(): ParsedRelease
    {
        return $this->release;
    }
}

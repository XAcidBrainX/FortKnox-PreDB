<?php

declare(strict_types=1);

namespace FortKnox\Core;

final class Application
{
    public function __construct(
        private readonly string $name = 'FortKnox PreDB'
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return '0.1.0-dev';
    }
}

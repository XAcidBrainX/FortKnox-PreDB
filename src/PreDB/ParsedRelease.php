<?php

declare(strict_types=1);

namespace FortKnox\PreDB;

final class ParsedRelease
{
    public function __construct(
        private readonly string $releaseName,
        private readonly ?string $title,
        private readonly ?string $group,
        private readonly ?string $category,
        private readonly ?int $year,
        private readonly ?int $season,
        private readonly ?int $episode,
        private readonly ?string $resolution,
        private readonly ?string $source,
        private readonly ?string $language,
        private readonly ?string $codec,
    ) {
    }

    public function releaseName(): string
    {
        return $this->releaseName;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function group(): ?string
    {
        return $this->group;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    public function year(): ?int
    {
        return $this->year;
    }

    public function season(): ?int
    {
        return $this->season;
    }

    public function episode(): ?int
    {
        return $this->episode;
    }

    public function resolution(): ?string
    {
        return $this->resolution;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    public function codec(): ?string
    {
        return $this->codec;
    }
}

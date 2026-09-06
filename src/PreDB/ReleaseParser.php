<?php

declare(strict_types=1);

namespace FortKnox\PreDB;

final class ReleaseParser
{
    public function parse(string $releaseName): ParsedRelease
    {
        $group = $this->detectGroup($releaseName);
        $category = $this->detectCategory($releaseName);

        [$season, $episode] = $this->detectEpisode($releaseName);

        $resolution = $this->detectResolution($releaseName);
        $source = $this->detectSource($releaseName);

        return new ParsedRelease(
            releaseName: $releaseName,
            group: $group,
            category: $category,
            season: $season,
            episode: $episode,
            resolution: $resolution,
            source: $source,
        );
    }

    private function detectGroup(string $releaseName): ?string
    {
        if (!str_contains($releaseName, '-')) {
            return null;
        }

        $parts = explode('-', $releaseName);

        $group = end($parts);

        if ($group === false || $group === '') {
            return null;
        }

        return $group;
    }

    private function detectCategory(string $releaseName): ?string
    {
        if (preg_match('/S\d{1,2}E\d{1,3}/i', $releaseName)) {
            return 'TV';
        }

        if (preg_match('/\b(19|20)\d{2}\b/', str_replace('.', ' ', $releaseName))) {
            return 'MOVIE';
        }

        return null;
    }

    private function detectEpisode(string $releaseName): array
    {
        if (
            preg_match(
                '/S(\d{1,2})E(\d{1,3})/i',
                $releaseName,
                $matches
            )
        ) {
            return [
                (int) $matches[1],
                (int) $matches[2],
            ];
        }

        return [null, null];
    }

    private function detectResolution(string $releaseName): ?string
    {
        if (
            preg_match(
                '/\b(2160p|1080p|720p|576p|480p)\b/i',
                str_replace('.', ' ', $releaseName),
                $matches
            )
        ) {
            return strtolower($matches[1]);
        }

        return null;
    }

    private function detectSource(string $releaseName): ?string
    {
        $sources = [
            'WEB-DL',
            'WEBRip',
            'BluRay',
            'BDRip',
            'DVDRip',
            'HDTV',
            'WEB',
        ];

        foreach ($sources as $source) {
            if (stripos($releaseName, $source) !== false) {
                return $source;
            }
        }

        return null;
    }
}

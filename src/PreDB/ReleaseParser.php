<?php

declare(strict_types=1);

namespace FortKnox\PreDB;

use FortKnox\PreDB\Parser\CodecDetector;
use FortKnox\PreDB\Parser\EpisodeDetector;
use FortKnox\PreDB\Parser\LanguageDetector;
use FortKnox\PreDB\Parser\ResolutionDetector;
use FortKnox\PreDB\Parser\SourceDetector;

final class ReleaseParser
{
    public function __construct(
        private readonly ResolutionDetector $resolutionDetector = new ResolutionDetector(),
        private readonly SourceDetector $sourceDetector = new SourceDetector(),
        private readonly CodecDetector $codecDetector = new CodecDetector(),
        private readonly LanguageDetector $languageDetector = new LanguageDetector(),
        private readonly EpisodeDetector $episodeDetector = new EpisodeDetector(),
    ) {
    }

    public function parse(string $releaseName): ParsedRelease
    {
        $releaseName = trim($releaseName);

        $group = $this->detectGroup($releaseName);
        $title = $this->detectTitle($releaseName);
        $year = $this->detectYear($releaseName);

        [$season, $episode] = $this->episodeDetector->detect(
            $releaseName
        );

        $resolution = $this->resolutionDetector->detect(
            $releaseName
        );

        $source = $this->sourceDetector->detect(
            $releaseName
        );

        $language = $this->languageDetector->detect(
            $releaseName
        );

        $codec = $this->codecDetector->detect(
            $releaseName
        );

        $category = $this->detectCategory(
            $year,
            $season,
            $episode
        );

        return new ParsedRelease(
            releaseName: $releaseName,
            title: $title,
            group: $group,
            category: $category,
            year: $year,
            season: $season,
            episode: $episode,
            resolution: $resolution,
            source: $source,
            language: $language,
            codec: $codec,
        );
    }

    private function detectGroup(
        string $releaseName
    ): ?string {
        if (
            preg_match(
                '/-([A-Za-z0-9][A-Za-z0-9_-]{1,31})$/',
                $releaseName,
                $matches
            ) === 1
        ) {
            return $matches[1];
        }

        return null;
    }

    private function detectYear(
        string $releaseName
    ): ?int {
        if (
            preg_match(
                '/(?:^|[._ -])(19\d{2}|20\d{2})(?:[._ -]|$)/',
                $releaseName,
                $matches
            ) === 1
        ) {
            return (int) $matches[1];
        }

        return null;
    }

    private function detectCategory(
        ?int $year,
        ?int $season,
        ?int $episode
    ): ?string {
        if ($season !== null || $episode !== null) {
            return 'TV';
        }

        if ($year !== null) {
            return 'MOVIE';
        }

        return null;
    }

    private function detectTitle(
        string $releaseName
    ): ?string {
        $nameWithoutGroup = preg_replace(
            '/-[A-Za-z0-9][A-Za-z0-9_-]{1,31}$/',
            '',
            $releaseName
        );

        if ($nameWithoutGroup === null) {
            $nameWithoutGroup = $releaseName;
        }

        $tokens = preg_split(
            '/[._ -]+/',
            $nameWithoutGroup,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ($tokens === false || $tokens === []) {
            return null;
        }

        $titleTokens = [];

        foreach ($tokens as $token) {
            if ($this->isMetadataToken($token)) {
                break;
            }

            $titleTokens[] = $token;
        }

        if ($titleTokens === []) {
            return null;
        }

        return implode(' ', $titleTokens);
    }

    private function isMetadataToken(
        string $token
    ): bool {
        if (
            preg_match(
                '/^(19\d{2}|20\d{2})$/',
                $token
            ) === 1
        ) {
            return true;
        }

        if (
            preg_match(
                '/^S\d{1,2}E\d{1,3}$/i',
                $token
            ) === 1
        ) {
            return true;
        }

        if (
            preg_match(
                '/^(2160p|1080p|720p|576p|480p)$/i',
                $token
            ) === 1
        ) {
            return true;
        }

        foreach ([
            'WEB-DL',
            'WEBRip',
            'BluRay',
            'BDRip',
            'DVDRip',
            'HDTV',
            'WEB',
            'GERMAN',
            'DEUTSCH',
            'ENGLISH',
            'FRENCH',
            'SPANISH',
            'ITALIAN',
            'DUTCH',
            'MULTI',
            'x265',
            'x264',
            'h265',
            'h264',
            'HEVC',
            'AV1',
            'XviD',
        ] as $metadata) {
            if (strcasecmp($token, $metadata) === 0) {
                return true;
            }
        }

        return false;
    }
}

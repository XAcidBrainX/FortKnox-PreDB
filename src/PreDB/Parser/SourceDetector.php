<?php

declare(strict_types=1);

namespace FortKnox\PreDB\Parser;

final class SourceDetector
{
    private const SOURCES = [
        'WEB-DL',
        'WEBRip',
        'BluRay',
        'BDRip',
        'DVDRip',
        'HDTV',
        'WEB',
    ];

    public function detect(string $releaseName): ?string
    {
        foreach (self::SOURCES as $source) {
            if (stripos($releaseName, $source) !== false) {
                return $source;
            }
        }

        return null;
    }
}

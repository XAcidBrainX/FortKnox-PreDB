<?php

declare(strict_types=1);

namespace FortKnox\PreDB\Parser;

final class ResolutionDetector
{
    public function detect(string $releaseName): ?string
    {
        if (
            preg_match(
                '/(?:^|[._ -])(2160p|1080p|720p|576p|480p)(?:[._ -]|$)/i',
                $releaseName,
                $matches
            ) === 1
        ) {
            return strtolower($matches[1]);
        }

        return null;
    }
}

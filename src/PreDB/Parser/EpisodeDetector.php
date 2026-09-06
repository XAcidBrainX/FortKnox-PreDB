<?php

declare(strict_types=1);

namespace FortKnox\PreDB\Parser;

final class EpisodeDetector
{
    /**
     * @return array{0:?int,1:?int}
     */
    public function detect(string $releaseName): array
    {
        if (
            preg_match(
                '/S(\d{1,2})E(\d{1,3})/i',
                $releaseName,
                $matches
            ) === 1
        ) {
            return [
                (int) $matches[1],
                (int) $matches[2],
            ];
        }

        return [null, null];
    }
}

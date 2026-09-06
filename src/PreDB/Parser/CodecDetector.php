<?php

declare(strict_types=1);

namespace FortKnox\PreDB\Parser;

final class CodecDetector
{
    private const CODECS = [
        'x265',
        'x264',
        'h265',
        'h264',
        'HEVC',
        'AV1',
        'XviD',
    ];

    public function detect(string $releaseName): ?string
    {
        foreach (self::CODECS as $codec) {
            if (
                preg_match(
                    '/(?:^|[._ -])' .
                    preg_quote($codec, '/') .
                    '(?:[._ -]|$)/i',
                    $releaseName
                ) === 1
            ) {
                return $codec;
            }
        }

        return null;
    }
}

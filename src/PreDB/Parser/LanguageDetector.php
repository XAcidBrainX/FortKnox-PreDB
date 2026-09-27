<?php

declare(strict_types=1);

namespace FortKnox\PreDB\Parser;

final class LanguageDetector
{
    private const LANGUAGES = [
        'GERMAN',
        'DEUTSCH',
        'ENGLISH',
        'FRENCH',
        'SPANISH',
        'ITALIAN',
        'DUTCH',
        'MULTI',
    ];

    public function detect(string $releaseName): ?string
    {
        foreach (self::LANGUAGES as $language) {
            if (
                preg_match(
                    '/(?:^|[._ -])' .
                    preg_quote($language, '/') .
                    '(?:[._ -]|$)/i',
                    $releaseName
                ) === 1
            ) {
                return strtoupper($language);
            }
        }

        return null;
    }
}

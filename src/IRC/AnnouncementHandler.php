<?php

declare(strict_types=1);

namespace FortKnox\IRC;

use FortKnox\PreDB\ImportResult;
use FortKnox\PreDB\ImportService;

final class AnnouncementHandler
{
    public function __construct(
        private readonly ImportService $importService,
    ) {
    }

    public function handle(IrcMessage $message): ?ImportResult
    {
        $releaseName = $this->extractReleaseName(
            $message->text()
        );

        if ($releaseName === null) {
            return null;
        }

        return $this->importService->import(
            $releaseName
        );
    }

    private function extractReleaseName(string $text): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        /*
         * Remove IRC formatting.
         *
         * Supports:
         * - IRC color codes
         * - bold
         * - underline
         * - reverse
         * - italic
         * - reset
         */
        $text = $this->stripIrcFormatting($text);

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        /*
         * Ignore IRC commands / CTCP.
         */
        if (
            str_starts_with($text, '/')
            || str_starts_with($text, "\x01")
        ) {
            return null;
        }

        /*
         * A release name must have a final -GROUP suffix.
         */
        if (
            preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]{2,254}-[A-Za-z0-9][A-Za-z0-9_-]{1,31}$/',
                $text
            ) !== 1
        ) {
            return null;
        }

        /*
         * Prevent obvious non-release messages.
         */
        if (
            !str_contains($text, '.')
            && !preg_match(
                '/S\d{1,2}E\d{1,3}/i',
                $text
            )
        ) {
            return null;
        }

        return $text;
    }

    private function stripIrcFormatting(string $text): string
    {
        /*
         * IRC color:
         *
         * \x03
         * followed by optional foreground/background
         * color numbers.
         */
        $text = preg_replace(
            '/\x03(?:\d{1,2}(?:,\d{1,2})?)?/',
            '',
            $text
        ) ?? $text;

        /*
         * Other common IRC formatting control codes:
         *
         * \x02 Bold
         * \x0F Reset
         * \x16 Reverse
         * \x1D Italic
         * \x1F Underline
         */
        $text = str_replace(
            [
                "\x02",
                "\x0F",
                "\x16",
                "\x1D",
                "\x1F",
            ],
            '',
            $text
        );

        return $text;
    }
}

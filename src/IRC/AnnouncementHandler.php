<?php

declare(strict_types=1);

namespace FortKnox\IRC;

use FortKnox\PreDB\ImportResult;
use FortKnox\PreDB\ImportService;
use FortKnox\Notifications\WebhookService;

final class AnnouncementHandler
{
    public function __construct(
        private readonly ImportService $importService,
        private readonly ?WebhookService $webhookService = null,
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

        $result = $this->importService->import(
            $releaseName
        );

        return $result;
    }

    private function extractReleaseName(string $text): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $text = $this->stripIrcFormatting($text);
        $text = trim($text);

        if ($text === '' || str_starts_with($text, '/') || str_starts_with($text, "\x01")) {
            return null;
        }

        preg_match_all('/[A-Za-z0-9][A-Za-z0-9._-]{2,250}-[A-Za-z0-9][A-Za-z0-9_-]{1,31}/', $text, $matches);

        if (!empty($matches[0])) {
            foreach ($matches[0] as $candidate) {
                if (str_contains($candidate, '.') || preg_match('/S\d{1,2}E\d{1,3}/i', $candidate)) {
                    return $candidate;
                }
            }
            return $matches[0][0];
        }

        return null;
    }

    private function stripIrcFormatting(string $text): string
    {
        $text = preg_replace(
            '/\x03(?:\d{1,2}(?:,\d{1,2})?)?/',
            '',
            $text
        ) ?? $text;

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

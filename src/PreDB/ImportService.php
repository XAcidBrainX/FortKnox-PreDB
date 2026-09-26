<?php

declare(strict_types=1);

namespace FortKnox\PreDB;

use FortKnox\Notifications\WebhookService;

final class ImportService
{
    public function __construct(
        private readonly ReleaseParser $parser,
        private readonly ReleaseRepository $repository,
        private readonly ?WebhookService $webhookService = null,
    ) {
    }

    public function import(string $releaseName): ?ImportResult
    {
        $releaseName = trim($releaseName);

        if ($releaseName === '') {
            return null;
        }

        // Prüfen ob Release bereits existiert
        $existing = $this->repository->findByName($releaseName);
        if ($existing !== null) {
            return null;
        }

        $parsed = $this->parser->parse($releaseName);
        $groupId = $this->repository->findOrCreateGroup($parsed->group());
        
        try {
            $releaseId = $this->repository->createRelease($parsed, $groupId);
            $this->repository->addEvent($releaseId, 'announce', 'Release announced via IRC', 'irc');

            // IRC Announce Queue
            try {
                $sec = $parsed->category() ?: 'PRE';
                $msg = chr(3) . "03[PRE]" . chr(3) . " " . chr(3) . "07[" . $sec . "]" . chr(3) . " " . chr(2) . $releaseName . chr(2);
                $this->repository->queueIrcAnnounce(1, '#predb', $msg);
            } catch (\Throwable $e) {
                fwrite(STDERR, "[IRC Outbox Error] " . $e->getMessage() . "
");
            }

        } catch (\Throwable) {
            return null;
        }

        // Release-Daten als Array laden
        $releaseData = $this->repository->findByName($releaseName);
        if ($releaseData === null) {
            return null;
        }

        // Webhook an Discord senden
        if ($this->webhookService !== null) {
            try {
                $category = $releaseData['category'] ?? 'Unknown';
                $group = $parsed->group() ?? 'Unknown';
                $this->webhookService->send(
                    "Neues Release: {$releaseName}",
                    "Kategorie: {$category}\nGruppe: {$group}",
                    'release'
                );
            } catch (\Throwable $e) {
                error_log("Webhook Error: " . $e->getMessage());
            }
        }

        return new ImportResult($releaseData, []);
    }
}

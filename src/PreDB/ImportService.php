<?php

declare(strict_types=1);

namespace FortKnox\PreDB;

use PDO;

final class ImportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ReleaseParser $parser,
        private readonly ReleaseRepository $repository,
    ) {
    }

    public function import(string $releaseName): ImportResult
    {
        $releaseName = trim($releaseName);

        if ($releaseName === '') {
            throw new \InvalidArgumentException(
                'Release name must not be empty.'
            );
        }

        $parsed = $this->parser->parse($releaseName);

        /*
         * Existing release = duplicate announcement.
         */
        $existing = $this->repository->findByName(
            $parsed->releaseName()
        );

        if ($existing !== null) {
            $releaseId = (int) $existing['id'];

            $this->pdo->beginTransaction();

            try {
                $this->repository->addEvent(
                    $releaseId,
                    'dupe',
                    'Duplicate release announcement',
                    'fortknox'
                );

                $this->pdo->commit();
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $exception;
            }

            return new ImportResult(
                imported: false,
                releaseId: $releaseId,
                groupId: isset($existing['group_id'])
                    ? (int) $existing['group_id']
                    : null,
                eventType: 'dupe',
                release: $parsed,
            );
        }

        /*
         * Release creation is atomic.
         */
        $this->pdo->beginTransaction();

        try {
            $groupId = $this->repository->findOrCreateGroup(
                $parsed->group()
            );

            $releaseId = $this->repository->createRelease(
                $parsed,
                $groupId
            );

            $this->repository->addEvent(
                $releaseId,
                'announce',
                'Release imported',
                'fortknox'
            );

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return new ImportResult(
            imported: true,
            releaseId: $releaseId,
            groupId: $groupId,
            eventType: 'announce',
            release: $parsed,
        );
    }
}

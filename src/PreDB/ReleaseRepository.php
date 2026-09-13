<?php

declare(strict_types=1);

namespace FortKnox\PreDB;

use PDO;
use PDOException;
use InvalidArgumentException;

final class ReleaseRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                id,
                name,
                title,
                category,
                year,
                group_id,
                season,
                episode,
                resolution,
                language,
                codec,
                size_bytes,
                source,
                nuke,
                created_at,
                updated_at
             FROM releases
             WHERE name = :name
             LIMIT 1'
        );

        $stmt->execute([
            'name' => $name,
        ]);

        $release = $stmt->fetch(PDO::FETCH_ASSOC);

        return $release !== false ? $release : null;
    }

    public function findOrCreateGroup(?string $group): ?int
    {
        if ($group === null || $group === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM release_groups
             WHERE name = :name
             LIMIT 1'
        );

        $stmt->execute([
            'name' => $group,
        ]);

        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO release_groups (name)
                 VALUES (:name)'
            );

            $stmt->execute([
                'name' => $group,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            /*
             * Race condition protection:
             * another importer may have created the group
             * between SELECT and INSERT.
             */
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            $stmt = $this->pdo->prepare(
                'SELECT id
                 FROM release_groups
                 WHERE name = :name
                 LIMIT 1'
            );

            $stmt->execute([
                'name' => $group,
            ]);

            $id = $stmt->fetchColumn();

            return $id !== false ? (int) $id : null;
        }
    }

    public function createRelease(
        ParsedRelease $release,
        ?int $groupId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO releases (
                name,
                title,
                category,
                year,
                group_id,
                season,
                episode,
                resolution,
                language,
                codec,
                size_bytes,
                source,
                nuke
             ) VALUES (
                :name,
                :title,
                :category,
                :year,
                :group_id,
                :season,
                :episode,
                :resolution,
                :language,
                :codec,
                :size_bytes,
                :source,
                0
             )'
        );

        $stmt->execute([
            'name' => $release->releaseName(),
            'title' => $release->title(),
            'category' => $release->category(),
            'year' => $release->year(),
            'group_id' => $groupId,
            'season' => $release->season(),
            'episode' => $release->episode(),
            'resolution' => $release->resolution(),
            'language' => $release->language(),
            'codec' => $release->codec(),
            'size_bytes' => null,
            'source' => $release->source(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addEvent(
        int $releaseId,
        string $eventType,
        ?string $message = null,
        ?string $source = null
    ): int {
        $allowedTypes = [
            'announce',
            'dupe',
            'nuke',
            'unnuke',
            'update',
        ];

        if (!in_array($eventType, $allowedTypes, true)) {
            throw new InvalidArgumentException(
                "Unsupported release event type: {$eventType}"
            );
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO release_events (
                release_id,
                event_type,
                message,
                source
             ) VALUES (
                :release_id,
                :event_type,
                :message,
                :source
             )'
        );

        $stmt->execute([
            'release_id' => $releaseId,
            'event_type' => $eventType,
            'message' => $message,
            'source' => $source,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
    public function queueIrcAnnounce(int $networkId, string $channel, string $message): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO irc_outbox (network_id, channel, message) VALUES (?, ?, ?)");
        $stmt->execute([$networkId, $channel, $message]);
    }
    public function nukeReleaseByName(string $name, string $reason, string $source = 'irc'): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM releases WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $releaseId = $stmt->fetchColumn();

        if (!$releaseId) {
            return false;
        }

        $upd = $this->pdo->prepare("UPDATE releases SET status = 'nuked', nuke_reason = ?, updated_at = NOW() WHERE id = ?");
        $upd->execute([$reason, $releaseId]);

        $this->addEvent((int)$releaseId, 'nuke', $reason, $source);
        return true;
    }
}

<?php

declare(strict_types=1);

namespace FortKnox\Web\Controller;

use FortKnox\Database\Connection;
use FortKnox\Web\Response\JsonResponse;
use PDO;
use PDOException;

final class DashboardController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function index(): void
    {
        try {
            $pdo = $this->connection->get();

            $releases = (int) $pdo
                ->query('SELECT COUNT(*) FROM releases')
                ->fetchColumn();

            $groups = (int) $pdo
                ->query('SELECT COUNT(*) FROM release_groups')
                ->fetchColumn();

            $events = (int) $pdo
                ->query('SELECT COUNT(*) FROM release_events')
                ->fetchColumn();

            $nuked = (int) $pdo
                ->query('SELECT COUNT(*) FROM releases WHERE nuke = 1')
                ->fetchColumn();

            $categoryStatement = $pdo->query(
                'SELECT
                    COALESCE(category, \'UNKNOWN\') AS category,
                    COUNT(*) AS count
                 FROM releases
                 GROUP BY category
                 ORDER BY count DESC, category ASC'
            );

            $categories = $categoryStatement->fetchAll();

            $recentStatement = $pdo->query(
                'SELECT
                    r.id,
                    r.name,
                    r.title,
                    r.category,
                    r.year,
                    r.group_id,
                    g.name AS group_name,
                    r.season,
                    r.episode,
                    r.resolution,
                    r.language,
                    r.codec,
                    r.source,
                    r.nuke,
                    r.created_at,
                    r.updated_at
                 FROM releases r
                 LEFT JOIN release_groups g
                    ON g.id = r.group_id
                 ORDER BY r.id DESC
                 LIMIT 10'
            );

            $recentReleases = $recentStatement->fetchAll();

            JsonResponse::send([
                'success' => true,
                'stats' => [
                    'releases' => $releases,
                    'groups' => $groups,
                    'events' => $events,
                    'nuked' => $nuked,
                ],
                'categories' => $categories,
                'recent_releases' => $recentReleases,
            ]);
        } catch (PDOException $exception) {
            JsonResponse::send([
                'success' => false,
                'error' => 'Unable to load dashboard data.',
            ], 500);
        }
    }
}

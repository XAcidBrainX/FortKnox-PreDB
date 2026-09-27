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

            // Vorgefertigte Stats (Cron: fortknox-refresh-stats) – kein Live-COUNT
            $statsRow = $pdo->query('SELECT * FROM dashboard_stats WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            if (!$statsRow) {
                $statsRow = [
                    'total_releases'  => 0,
                    'total_groups'    => 0,
                    'total_events'    => 0,
                    'total_nuked'     => 0,
                    'categories_json' => '[]',
                ];
            }

            $releases = (int) $statsRow['total_releases'];
            $groups   = (int) $statsRow['total_groups'];
            $events   = (int) $statsRow['total_events'];
            $nuked    = (int) $statsRow['total_nuked'];

            $categories = json_decode($statsRow['categories_json'] ?? '[]', true) ?: [];
            $categories = array_map(static function (array $c): array {
                return [
                    'category' => $c['category'] ?? 'UNKNOWN',
                    'count'    => (int) ($c['count'] ?? $c['cnt'] ?? 0),
                ];
            }, $categories);

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

<?php

declare(strict_types=1);

namespace FortKnox\Web\Controller;

use FortKnox\Database\Connection;
use FortKnox\Web\Response\JsonResponse;
use PDO;

final class ReleaseController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function index(): void
    {
        $pdo = $this->connection->get();

        $stmt = $pdo->query(
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
             ORDER BY id DESC
             LIMIT 100'
        );

        $releases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        JsonResponse::send([
            'success' => true,
            'count' => count($releases),
            'releases' => $releases,
        ]);
    }

    public function show(int $id): void
    {
        $pdo = $this->connection->get();

        $stmt = $pdo->prepare(
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
        r.size_bytes,
        r.source,
        r.nuke,
        r.created_at,
        r.updated_at
     FROM releases r
     LEFT JOIN release_groups g ON g.id = r.group_id
     WHERE r.id = :id
     LIMIT 1'
);

        $stmt->execute([
            'id' => $id,
        ]);

        $release = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($release === false) {
            JsonResponse::send([
                'success' => false,
                'error' => 'Release not found',
                'id' => $id,
            ], 404);

            return;
        }

        $eventStmt = $pdo->prepare(
    'SELECT
        id,
        event_type,
        message,
        source,
        created_at
     FROM release_events
     WHERE release_id = :release_id
     ORDER BY id DESC'
);

$eventStmt->execute([
    'release_id' => $id,
]);

$events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

JsonResponse::send([
    'success' => true,
    'release' => $release,
    'events' => $events,
]);
    }

    public function search(array $query): void
    {
        $pdo = $this->connection->get();

        $conditions = [];
        $params = [];

        if (!empty($query['q'])) {
            $conditions[] = '(
                name LIKE :search_name
                OR title LIKE :search_title
                OR category LIKE :search_category
                OR language LIKE :search_language
                OR codec LIKE :search_codec
                OR source LIKE :search_source
            )';

            $search = '%' . $query['q'] . '%';

            $params['search_name'] = $search;
            $params['search_title'] = $search;
            $params['search_category'] = $search;
            $params['search_language'] = $search;
            $params['search_codec'] = $search;
            $params['search_source'] = $search;
        }

        if (!empty($query['category'])) {
            $conditions[] = 'category = :category';
            $params['category'] = $query['category'];
        }

        if (!empty($query['year'])) {
            $conditions[] = 'year = :year';
            $params['year'] = (int) $query['year'];
        }

        if (!empty($query['group'])) {
            $conditions[] = 'group_id = (
                SELECT id
                FROM release_groups
                WHERE name = :group
                LIMIT 1
            )';

            $params['group'] = $query['group'];
        }

        if (!empty($query['resolution'])) {
            $conditions[] = 'resolution = :resolution';
            $params['resolution'] = $query['resolution'];
        }

        if (!empty($query['language'])) {
            $conditions[] = 'language = :language';
            $params['language'] = $query['language'];
        }

        if (!empty($query['codec'])) {
            $conditions[] = 'codec = :codec';
            $params['codec'] = $query['codec'];
        }

        $where = '';

        if ($conditions !== []) {
            $where = ' WHERE ' . implode(' AND ', $conditions);
        }

        /*
         * Count total matching releases.
         */
        $countSql = 'SELECT COUNT(*)
                     FROM releases' . $where;

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);

        $total = (int) $countStmt->fetchColumn();

        /*
         * Sorting.
         */
        $allowedSorts = [
            'id',
            'name',
            'title',
            'year',
            'category',
            'resolution',
            'language',
            'codec',
            'created_at',
            'updated_at',
        ];

        $sort = (string) ($query['sort'] ?? 'id');

        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }

        $order = strtoupper(
            (string) ($query['order'] ?? 'DESC')
        );

        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        /*
         * Pagination.
         */
        $page = max(
            1,
            (int) ($query['page'] ?? 1)
        );

        $limit = min(
            100,
            max(
                1,
                (int) ($query['limit'] ?? 25)
            )
        );

        $offset = ($page - 1) * $limit;

        $pages = $total > 0
            ? (int) ceil($total / $limit)
            : 0;

        /*
         * Main query.
         */
        $sql = 'SELECT
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
                FROM releases'
            . $where
            . sprintf(
                ' ORDER BY %s %s LIMIT %d OFFSET %d',
                $sort,
                $order,
                $limit,
                $offset
            );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $releases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        JsonResponse::send([
            'success' => true,
            'count' => count($releases),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
            'sort' => $sort,
            'order' => $order,
            'query' => $query,
            'releases' => $releases,
        ]);
    }
public function events(int $releaseId): void
{
    $pdo = $this->connection->get();

    $releaseStmt = $pdo->prepare(
        'SELECT id, name
         FROM releases
         WHERE id = :id
         LIMIT 1'
    );

    $releaseStmt->execute([
        'id' => $releaseId,
    ]);

    $release = $releaseStmt->fetch(PDO::FETCH_ASSOC);

    if ($release === false) {
        JsonResponse::send([
            'success' => false,
            'error' => 'Release not found',
            'id' => $releaseId,
        ], 404);

        return;
    }

    $eventStmt = $pdo->prepare(
        'SELECT
            id,
            release_id,
            event_type,
            message,
            source,
            created_at
         FROM release_events
         WHERE release_id = :release_id
         ORDER BY id DESC'
    );

    $eventStmt->execute([
        'release_id' => $releaseId,
    ]);

    $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

    JsonResponse::send([
        'success' => true,
        'release' => $release,
        'count' => count($events),
        'events' => $events,
    ]);
}

public function nuke(int $id): void
{
    try {
        $pdo = $this->connection->get();

        $statement = $pdo->prepare(
            'SELECT id, nuke
             FROM releases
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $id,
        ]);

        $release = $statement->fetch();

        if ($release === false) {
            JsonResponse::send([
                'success' => false,
                'error' => 'Release not found.',
            ], 404);

            return;
        }

        if ((int) $release['nuke'] === 1) {
            JsonResponse::send([
                'success' => false,
                'error' => 'Release is already nuked.',
            ], 409);

            return;
        }

        $pdo->beginTransaction();

        $update = $pdo->prepare(
            'UPDATE releases
             SET nuke = 1
             WHERE id = :id'
        );

        $update->execute([
            'id' => $id,
        ]);

        $event = $pdo->prepare(
            'INSERT INTO release_events
                (release_id, event_type, message, source)
             VALUES
                (:release_id, :event_type, :message, :source)'
        );

        $event->execute([
            'release_id' => $id,
            'event_type' => 'nuke',
            'message' => 'Release nuked',
            'source' => 'web',
        ]);

        $pdo->commit();

        JsonResponse::send([
            'success' => true,
            'message' => 'Release nuked.',
            'release_id' => $id,
        ]);
    } catch (PDOException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        JsonResponse::send([
            'success' => false,
            'error' => 'Unable to nuke release.',
        ], 500);
    }
}

public function unnuke(int $id): void
{
    try {
        $pdo = $this->connection->get();

        $statement = $pdo->prepare(
            'SELECT id, nuke
             FROM releases
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $id,
        ]);

        $release = $statement->fetch();

        if ($release === false) {
            JsonResponse::send([
                'success' => false,
                'error' => 'Release not found.',
            ], 404);

            return;
        }

        if ((int) $release['nuke'] === 0) {
            JsonResponse::send([
                'success' => false,
                'error' => 'Release is not nuked.',
            ], 409);

            return;
        }

        $pdo->beginTransaction();

        $update = $pdo->prepare(
            'UPDATE releases
             SET nuke = 0
             WHERE id = :id'
        );

        $update->execute([
            'id' => $id,
        ]);

        $event = $pdo->prepare(
            'INSERT INTO release_events
                (release_id, event_type, message, source)
             VALUES
                (:release_id, :event_type, :message, :source)'
        );

        $event->execute([
            'release_id' => $id,
            'event_type' => 'unnuke',
            'message' => 'Release unnuked',
            'source' => 'web',
        ]);

        $pdo->commit();

        JsonResponse::send([
            'success' => true,
            'message' => 'Release unnuked.',
            'release_id' => $id,
        ]);
    } catch (PDOException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        JsonResponse::send([
            'success' => false,
            'error' => 'Unable to unnuke release.',
        ], 500);
    }
    }
    
    public function dupe(int $id): void
    {
        try {
            $pdo = $this->connection->get();

            $statement = $pdo->prepare(
                'SELECT id
                 FROM releases
                 WHERE id = :id
                 LIMIT 1'
            );

            $statement->execute([
                'id' => $id,
            ]);

            $release = $statement->fetch();

            if ($release === false) {
                JsonResponse::send([
                    'success' => false,
                    'error' => 'Release not found.',
                ], 404);

                return;
            }

            $event = $pdo->prepare(
                'INSERT INTO release_events
                    (release_id, event_type, message, source)
                 VALUES
                    (:release_id, :event_type, :message, :source)'
            );

            $event->execute([
                'release_id' => $id,
                'event_type' => 'dupe',
                'message' => 'Release marked as dupe',
                'source' => 'web',
            ]);

            JsonResponse::send([
                'success' => true,
                'message' => 'Release marked as dupe.',
                'release_id' => $id,
            ]);
        } catch (PDOException $exception) {
            JsonResponse::send([
                'success' => false,
                'error' => 'Unable to mark release as dupe.',
            ], 500);
        }
    }
}


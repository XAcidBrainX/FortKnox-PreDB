<?php

declare(strict_types=1);

namespace FortKnox\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationPath
    ) {
    }

    public function run(): void
    {
        $this->createMigrationTable();

        $files = glob($this->migrationPath . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $version = basename($file, '.sql');

            if ($this->alreadyApplied($version)) {
                continue;
            }

            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new RuntimeException(
                    "Unable to read migration: {$file}"
                );
            }

            $this->pdo->beginTransaction();

            try {
                $this->pdo->exec($sql);

                $statement = $this->pdo->prepare(
                    'INSERT INTO migrations (version) VALUES (:version)'
                );

                $statement->execute([
                    'version' => $version,
                ]);

                $this->pdo->commit();

                echo "Applied migration: {$version}" . PHP_EOL;
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();

                throw $exception;
            }
        }
    }

    private function createMigrationTable(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                version VARCHAR(255) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migrations_version (version)
            ) ENGINE=InnoDB
            DEFAULT CHARACTER SET utf8mb4
            COLLATE utf8mb4_unicode_ci
            SQL
        );
    }

    private function alreadyApplied(string $version): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM migrations WHERE version = :version LIMIT 1'
        );

        $statement->execute([
            'version' => $version,
        ]);

        return $statement->fetchColumn() !== false;
    }
}

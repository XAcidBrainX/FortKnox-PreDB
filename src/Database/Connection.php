<?php

declare(strict_types=1);

namespace FortKnox\Database;

use PDO;
use RuntimeException;

final class Connection
{
    private ?PDO $pdo = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function get(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = $this->config['host'] ?? $this->config['DB_HOST'] ?? '127.0.0.1';
        $port = (int) ($this->config['port'] ?? $this->config['DB_PORT'] ?? 3306);
        $database = $this->config['database'] ?? $this->config['DB_DATABASE'] ?? '';
        $username = $this->config['username'] ?? $this->config['DB_USERNAME'] ?? '';
        $password = $this->config['password'] ?? $this->config['DB_PASSWORD'] ?? '';
        $charset = $this->config['charset'] ?? $this->config['DB_CHARSET'] ?? 'utf8mb4';

        if ($database === '' || $username === '') {
            throw new RuntimeException(
                'FortKnox database configuration is incomplete.'
            );
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $this->pdo;
    }
}

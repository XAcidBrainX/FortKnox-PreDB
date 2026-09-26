<?php

declare(strict_types=1);

namespace FortKnox\Web\Controller;

use PDO;
use FortKnox\Database\Connection;

final class AuthController
{
    public function __construct(
        private readonly string $adminUser,
        private readonly string $adminPasswordHash,
        private readonly ?Connection $connection = null
    ) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }

    public function login(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];

        $user = (string) ($data['username'] ?? '');
        $pass = (string) ($data['password'] ?? '');

        if ($this->adminUser !== '' && $user === $this->adminUser && password_verify($pass, $this->adminPasswordHash)) {
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_user'] = $user;
            echo json_encode(['success' => true, 'user' => $user]);
            return;
        }

        http_response_code(401);
        echo json_encode(['error' => 'Ungültige Anmeldedaten.']);
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
    }

    public function status(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $authenticated = !empty($_SESSION['admin_authenticated']);
        echo json_encode([
            'authenticated' => $authenticated,
            'user' => $authenticated ? ($_SESSION['admin_user'] ?? 'admin') : null,
        ]);
    }

    public function stats(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!self::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $totalReleases = 0;
        $todayReleases = 0;
        $dbSizeBytes = 0;
        $activeBotsCount = 0;

        if ($this->connection !== null) {
            try {
                $pdo = $this->connection->get();
                $totalReleases = (int) $pdo->query('SELECT COUNT(*) FROM releases')->fetchColumn();
                $todayReleases = (int) $pdo->query('SELECT COUNT(*) FROM releases WHERE DATE(created_at) = CURDATE()')->fetchColumn();

                $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
                $stmt = $pdo->prepare('
                    SELECT SUM(data_length + index_length) 
                    FROM information_schema.tables 
                    WHERE table_schema = ?
                ');
                $stmt->execute([$dbName]);
                $dbSizeBytes = (int) $stmt->fetchColumn();

                $networks = $pdo->query('SELECT id FROM irc_networks WHERE is_enabled = 1')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($networks as $netId) {
                    $status = trim((string) @shell_exec("systemctl is-active fortknox-irc@{$netId}.service 2>/dev/null"));
                    if ($status === 'active') {
                        $activeBotsCount++;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $latestBackup = 'Keins';
        $backups = glob('/root/FortKnox-PreDB/backups/fortknox_*.sql.gz');
        if ($backups) {
            usort($backups, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $latestBackup = basename($backups[0]) . ' (' . round(filesize($backups[0]) / 1024 / 1024, 2) . ' MB)';
        }

        echo json_encode([
            'active_bots' => $activeBotsCount,
            'total_releases' => $totalReleases,
            'today_releases' => $todayReleases,
            'db_size_mb' => round($dbSizeBytes / 1024 / 1024, 2),
            'latest_backup' => $latestBackup,
        ]);
    }

    public function listBots(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!self::check() || $this->connection === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $pdo = $this->connection->get();
        $bots = $pdo->query('SELECT id, name, host, port, tls, nick, username, realname, channels, is_enabled FROM irc_networks ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bots as &$bot) {
            $id = (int) $bot['id'];
            $service = "fortknox-irc@{$id}.service";
            $status = trim((string) @shell_exec("systemctl is-active {$service} 2>/dev/null"));
            $bot['service_status'] = $status === 'active' ? 'active' : ($status ?: 'inactive');
        }

        echo json_encode(['bots' => $bots]);
    }

    public function saveBot(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!self::check() || $this->connection === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $pdo = $this->connection->get();

        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $name = trim((string) ($data['name'] ?? 'Neues Netzwerk'));
        $host = trim((string) ($data['host'] ?? ''));
        $port = (int) ($data['port'] ?? 6697);
        $tls = !empty($data['tls']) ? 1 : 0;
        $nick = trim((string) ($data['nick'] ?? 'FK-Bot'));
        $user = trim((string) ($data['username'] ?? 'fortknox'));
        $real = trim((string) ($data['realname'] ?? 'FortKnox PreDB'));
        $pass = trim((string) ($data['password'] ?? ''));
        $channels = trim((string) ($data['channels'] ?? '#predb'));

        if ($host === '' || $nick === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Host und Nickname dürfen nicht leer sein.']);
            return;
        }

        if ($id) {
            $sql = 'UPDATE irc_networks SET name = ?, host = ?, port = ?, tls = ?, nick = ?, username = ?, realname = ?, channels = ?';
            $params = [$name, $host, $port, $tls, $nick, $user, $real, $channels];
            if ($pass !== '') {
                $sql .= ', password = ?';
                $params[] = $pass;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $id;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO irc_networks (name, host, port, tls, nick, username, realname, password, channels, is_enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ');
            $stmt->execute([$name, $host, $port, $tls, $nick, $user, $real, $pass !== '' ? $pass : null, $channels]);
            $id = (int) $pdo->lastInsertId();
            shell_exec("systemctl enable fortknox-irc@{$id}.service 2>/dev/null");
        }

        echo json_encode(['success' => true, 'id' => $id]);
    }

    public function toggleBotService(int $id, string $action): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!self::check() || $this->connection === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $service = "fortknox-irc@{$id}.service";
        $pdo = $this->connection->get();

        if ($action === 'start') {
            $pdo->prepare('UPDATE irc_networks SET is_enabled = 1 WHERE id = ?')->execute([$id]);
            shell_exec("systemctl enable --now {$service} 2>/dev/null");
        } elseif ($action === 'stop') {
            $pdo->prepare('UPDATE irc_networks SET is_enabled = 0 WHERE id = ?')->execute([$id]);
            shell_exec("systemctl stop {$service} 2>/dev/null");
        } elseif ($action === 'restart') {
            shell_exec("systemctl restart {$service} 2>/dev/null");
        }

        echo json_encode(['success' => true]);
    }

    public function deleteBot(int $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!self::check() || $this->connection === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        shell_exec("systemctl stop fortknox-irc@{$id}.service 2>/dev/null");
        shell_exec("systemctl disable fortknox-irc@{$id}.service 2>/dev/null");

        $pdo = $this->connection->get();
        $pdo->prepare('DELETE FROM irc_networks WHERE id = ?')->execute([$id]);

        echo json_encode(['success' => true]);
    }

    public static function check(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($_SESSION['admin_authenticated']);
    }
}

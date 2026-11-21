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

        // Accounts managed in the Admin panel must also be able to sign in.
        if ($this->connection !== null && $user !== '' && $pass !== '') {
            $stmt = $this->connection->get()->prepare(
                'SELECT id, username, password_hash, role FROM admin_users WHERE username = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$user]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($account && password_verify($pass, (string) $account['password_hash'])) {
                $_SESSION['admin_authenticated'] = true;
                $_SESSION['admin_user'] = (string) $account['username'];
                $_SESSION['admin_role'] = (string) $account['role'];
                $this->connection->get()->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')
                    ->execute([(int) $account['id']]);
                echo json_encode(['success' => true, 'user' => $user]);
                return;
            }
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
        $backupPattern = dirname(__DIR__, 3) . '/storage/backups/*.{sql.gz,tar.gz,sql}';
        $backups = glob($backupPattern, GLOB_BRACE) ?: [];
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
        $bots = $pdo->query('SELECT id, name, host, port, tls, nick, username, realname, channels, is_enabled, enable_announce FROM irc_networks ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bots as &$bot) {
            $id = (int) $bot['id'];
            $service = "fortknox-irc@{$id}.service";
            $status = "";
            try {
                $status = trim((string) @shell_exec("systemctl is-active {$service} 2>/dev/null"));
            } catch (\Throwable $t) {}
            // Rohstatus zurückgeben (active, inactive, failed, activating, ...),
            // damit das Panel Fehlerzustände korrekt anzeigen kann.
            $bot['service_status'] = $status !== '' ? $status : 'unknown';

            $enabled = '';
            try {
                $enabled = trim((string) @shell_exec("systemctl is-enabled {$service} 2>/dev/null"));
            } catch (\Throwable $t) {}
            $bot['service_enabled'] = $enabled === 'enabled';
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
        $announce = array_key_exists('enable_announce', $data)
            ? (!empty($data['enable_announce']) ? 1 : 0)
            : null;

        if ($host === '' || $nick === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Host und Nickname dürfen nicht leer sein.']);
            return;
        }

        $activated = null;
        $restarted = false;
        $warning = null;

        if ($id) {
            $sql = 'UPDATE irc_networks SET name = ?, host = ?, port = ?, tls = ?, nick = ?, username = ?, realname = ?, channels = ?';
            $params = [$name, $host, $port, $tls, $nick, $user, $real, $channels];
            if ($pass !== '') {
                $sql .= ', password = ?';
                $params[] = $pass;
            }
            if ($announce !== null) {
                $sql .= ', enable_announce = ?';
                $params[] = $announce;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $id;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Laufende Instanz neu starten, damit geänderte Einstellungen
            // (Host, Nick, Channels, Passwort, ...) sofort übernommen werden.
            $service = "fortknox-irc@{$id}.service";
            $status = trim((string) @shell_exec("systemctl is-active {$service} 2>/dev/null"));
            if ($status === 'active') {
                $restart = $this->runSystemctl(['restart', $service]);
                if ($restart['code'] === 0) {
                    $restarted = true;
                } else {
                    $warning = $this->serviceError($restart, 'Einstellungen gespeichert, aber der Bot konnte nicht neu gestartet werden.');
                }
            }
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO irc_networks (name, host, port, tls, nick, username, realname, password, channels, is_enabled, enable_announce)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ');
            $stmt->execute([$name, $host, $port, $tls, $nick, $user, $real, $pass !== '' ? $pass : null, $channels, $announce ?? 1]);
            $id = (int) $pdo->lastInsertId();

            // Neuen Bot sofort aktivieren (enable + start), nicht nur für den Boot vormerken.
            $activation = $this->runSystemctl(['enable', '--now', "fortknox-irc@{$id}.service"]);
            if ($activation['code'] !== 0) {
                $pdo->prepare('UPDATE irc_networks SET is_enabled = 0 WHERE id = ?')->execute([$id]);
                $this->runSystemctl(['disable', "fortknox-irc@{$id}.service"]);

                echo json_encode([
                    'success' => true,
                    'id' => $id,
                    'activated' => false,
                    'warning' => $this->serviceError($activation, 'Bot wurde gespeichert, konnte aber nicht gestartet werden.'),
                ]);
                return;
            }

            $activated = true;
            $restarted = true;
        }

        echo json_encode([
            'success' => true,
            'id' => $id,
            'activated' => $activated,
            'restarted' => $restarted,
            'warning' => $warning,
        ]);
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
        $result = null;
        $warning = null;

        if ($action === 'start') {
            $pdo->prepare('UPDATE irc_networks SET is_enabled = 1 WHERE id = ?')->execute([$id]);
            $result = $this->runSystemctl(['enable', '--now', $service]);
            if ($result['code'] !== 0) {
                $pdo->prepare('UPDATE irc_networks SET is_enabled = 0 WHERE id = ?')->execute([$id]);
                $this->runSystemctl(['disable', $service]);
            }
        } elseif ($action === 'stop') {
            $pdo->prepare('UPDATE irc_networks SET is_enabled = 0 WHERE id = ?')->execute([$id]);
            // Erst aus dem Boot-Start nehmen, dann stoppen. "disable --now" ist von
            // der sudoers-Regel nicht abgedeckt, daher zwei einzelne Aufrufe.
            $disable = $this->runSystemctl(['disable', $service]);
            $result = $this->runSystemctl(['stop', $service]);
            if ($result['code'] === 0 && $disable['code'] !== 0) {
                $warning = $this->serviceError($disable, 'Bot gestoppt, konnte aber nicht dauerhaft deaktiviert werden.');
            }
        } elseif ($action === 'restart') {
            $result = $this->runSystemctl(['restart', $service]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion.']);
            return;
        }

        if ($result !== null && $result['code'] !== 0) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $this->serviceError($result, "Aktion '{$action}' fehlgeschlagen."),
            ]);
            return;
        }

        echo json_encode(['success' => true, 'action' => $action, 'warning' => $warning]);
    }

    public function deleteBot(int $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!self::check() || $this->connection === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $service = "fortknox-irc@{$id}.service";
        $this->runSystemctl(['disable', $service]);
        $this->runSystemctl(['stop', $service]);

        $pdo = $this->connection->get();
        $pdo->prepare('DELETE FROM irc_networks WHERE id = ?')->execute([$id]);

        echo json_encode(['success' => true]);
    }

    /**
     * Führt systemctl für eine FortKnox-IRC-Instanz über sudo aus.
     * Die sudoers-Regel erlaubt www-data ausschließlich fortknox-irc@*.
     *
     * @param list<string> $arguments
     * @return array{code:int,out:string,err:string}
     */
    private function runSystemctl(array $arguments): array
    {
        $command = array_merge(['/usr/bin/sudo', '-n', '/bin/systemctl'], $arguments);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return ['code' => -1, 'out' => '', 'err' => 'systemctl konnte nicht gestartet werden.'];
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'out' => trim($stdout), 'err' => trim($stderr)];
    }

    /**
     * @param array{code:int,out:string,err:string} $result
     */
    private function serviceError(array $result, string $fallback): string
    {
        $detail = trim($result['err'] !== '' ? $result['err'] : $result['out']);
        $detail = trim((string) preg_replace('/\s+/', ' ', $detail));

        if ($detail === '') {
            return $fallback;
        }

        return $fallback . ' ' . substr($detail, 0, 300);
    }

    public static function check(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($_SESSION['admin_authenticated']);
    }
}

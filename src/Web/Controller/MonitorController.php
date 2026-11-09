<?php

declare(strict_types=1);

namespace FortKnox\Web\Controller;

use PDO;
use FortKnox\Database\Connection;

final class MonitorController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $logDirectory
    ) {
    }

    public function trackVisit(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false]);
            return;
        }

        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($userAgent === '' || preg_match('/bot|crawler|spider|preview|curl|wget|python-requests/i', $userAgent)) {
            echo json_encode(['success' => true, 'counted' => false]);
            return;
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            echo json_encode(['success' => true, 'counted' => false]);
            return;
        }

        $storage = dirname(__DIR__, 3) . '/storage';
        $keyFile = $storage . '/visitor-hmac.key';
        $masterKey = @file_get_contents($keyFile);
        if ($masterKey === false || strlen($masterKey) < 32) {
            $handle = @fopen($keyFile, 'x');
            if ($handle !== false) {
                $generated = random_bytes(32);
                fwrite($handle, $generated);
                fclose($handle);
                @chmod($keyFile, 0600);
                $masterKey = $generated;
            } else {
                $masterKey = @file_get_contents($keyFile);
            }
        }

        if ($masterKey === false || strlen($masterKey) < 32) {
            http_response_code(503);
            echo json_encode(['success' => false]);
            return;
        }

        $day = date('Y-m-d');
        $dailyKey = hash_hmac('sha256', $day, $masterKey, true);
        $visitorHash = hash_hmac('sha256', $ip . "\0" . $userAgent, $dailyKey);

        try {
            $pdo = $this->connection->get();
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO daily_visitors (visit_date, visitor_hash) VALUES (?, ?)'
            );
            $stmt->execute([$day, $visitorHash]);

            // Keep only 35 days of pseudonymous visitor identifiers.
            if (random_int(1, 100) === 1) {
                $pdo->exec('DELETE FROM daily_visitors WHERE visit_date < CURDATE() - INTERVAL 35 DAY');
            }
            echo json_encode(['success' => true, 'counted' => $stmt->rowCount() === 1]);
        } catch (\Throwable) {
            http_response_code(503);
            echo json_encode(['success' => false]);
        }
    }

    public function stats(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!\FortKnox\Web\Controller\AuthController::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $pdo = $this->connection->get();
        $count = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        $networks = $pdo->query('SELECT id, name, is_enabled FROM irc_networks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($networks as &$network) {
            $id = (int) $network['id'];
            $status = trim((string) @shell_exec('systemctl is-active ' . escapeshellarg("fortknox-irc@{$id}.service") . ' 2>/dev/null'));
            $network['service_status'] = $status === 'active' ? 'active' : 'inactive';
        }
        unset($network);

        // FiSH status exposes metadata only; encrypted_key is never selected or returned.
        $fishStatus = ['available' => false, 'networks' => [], 'last_exchange' => null, 'last_error' => null];
        try {
            $keyRows = $pdo->query(
                'SELECT network_id, channel, updated_at FROM irc_channel_keys ORDER BY network_id, channel'
            )->fetchAll(PDO::FETCH_ASSOC);
            $fishStatus['available'] = true;
            foreach ($networks as $network) {
                $networkId = (int) $network['id'];
                $channels = [];
                foreach ($keyRows as $keyRow) {
                    if ((int) $keyRow['network_id'] !== $networkId) {
                        continue;
                    }
                    $channels[] = [
                        'channel' => (string) $keyRow['channel'],
                        'key_configured' => true,
                        'mode' => 'CBC',
                        'updated_at' => $keyRow['updated_at'],
                    ];
                }
                $fishStatus['networks'][] = [
                    'network_id' => $networkId,
                    'network' => (string) $network['name'],
                    'supported' => $networkId === 1,
                    'channels' => $channels,
                ];
            }

            $fishLogPath = $this->logDirectory . '/irc-1.log';
            if (is_file($fishLogPath) && is_readable($fishLogPath)) {
                $fishLines = array_slice(file($fishLogPath, FILE_IGNORE_NEW_LINES) ?: [], -3000);
                foreach (array_reverse($fishLines) as $fishLine) {
                    if ($fishStatus['last_exchange'] === null
                        && preg_match('/channel key stored for (#[^ ]+) .*peer ([^ )]+)/', $fishLine, $match)) {
                        $fishStatus['last_exchange'] = ['channel' => $match[1], 'peer' => $match[2]];
                    }
                    if ($fishStatus['last_error'] === null
                        && stripos($fishLine, '[FiSH]') !== false
                        && preg_match('/failed|ignored|decryption failed|no FiSH key/i', $fishLine)) {
                        $fishStatus['last_error'] = trim($fishLine);
                    }
                    if ($fishStatus['last_exchange'] !== null && $fishStatus['last_error'] !== null) {
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            // Keep the main monitoring endpoint available if the FiSH migration is absent.
            $fishStatus['available'] = false;
        }

        $outbox = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($pdo->query("SELECT status, COUNT(*) AS amount FROM irc_outbox GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $outbox[(string) $row['status']] = (int) $row['amount'];
        }
        $sources = $pdo->query("SELECT COUNT(*) AS total, SUM(enabled = 1) AS enabled, SUM(last_status REGEXP 'error|fail|timeout') AS with_issues FROM external_sources")->fetch(PDO::FETCH_ASSOC) ?: [];
        $daily = $pdo->query("SELECT DATE(created_at) AS day, COUNT(*) AS amount FROM releases WHERE created_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(created_at) ORDER BY day ASC")->fetchAll(PDO::FETCH_ASSOC);
        $visitorsToday = (int) $pdo->query('SELECT COUNT(*) FROM daily_visitors WHERE visit_date = CURDATE()')->fetchColumn();
        $visitorsDaily = $pdo->query("SELECT visit_date AS day, COUNT(*) AS amount FROM daily_visitors WHERE visit_date >= CURDATE() - INTERVAL 29 DAY GROUP BY visit_date ORDER BY visit_date ASC")->fetchAll(PDO::FETCH_ASSOC);
        $events = $pdo->query('SELECT event_type, COUNT(*) AS amount FROM release_events WHERE created_at >= NOW() - INTERVAL 24 HOUR GROUP BY event_type')->fetchAll(PDO::FETCH_ASSOC);
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $sizeStmt = $pdo->prepare('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = ?');
        $sizeStmt->execute([$dbName]);

        echo json_encode([
            'generated_at' => date(DATE_ATOM),
            'releases_total' => $count('SELECT COUNT(*) FROM releases'),
            'releases_today' => $count('SELECT COUNT(*) FROM releases WHERE DATE(created_at) = CURDATE()'),
            'releases_7d' => $count('SELECT COUNT(*) FROM releases WHERE created_at >= NOW() - INTERVAL 7 DAY'),
            'events_24h' => array_sum(array_map(static fn($r) => (int) $r['amount'], $events)),
            'db_size_mb' => round((int) $sizeStmt->fetchColumn() / 1024 / 1024, 2),
            'outbox' => $outbox,
            'sources' => ['total' => (int) ($sources['total'] ?? 0), 'enabled' => (int) ($sources['enabled'] ?? 0), 'with_issues' => (int) ($sources['with_issues'] ?? 0)],
            'networks' => $networks,
            'fish' => $fishStatus,
            'releases_by_day' => $daily,
            'visitors_today' => $visitorsToday,
            'visitors_by_day' => $visitorsDaily,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function saveFishKey(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!AuthController::check()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Nicht angemeldet.']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ungültige Anfrage.']);
            return;
        }
        $networkId = (int) ($input['network_id'] ?? 0);
        $channel = trim((string) ($input['channel'] ?? ''));
        $key = (string) ($input['key'] ?? '');
        $mode = strtolower(trim((string) ($input['mode'] ?? 'cbc')));
        if ($networkId < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ungültige Netzwerk-ID.']);
            return;
        }
        if (!preg_match('/^[#&][A-Za-z0-9._\\-\\[\\]{}\\\\^|]+$/', $channel)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ungültiger IRC-Channelname.']);
            return;
        }
        if (!in_array($mode, ['cbc', 'ecb'], true) || $key === '' || strlen($key) > 56 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Modus ungültig oder Schlüssel nicht zwischen 1 und 56 Bytes bzw. enthält Steuerzeichen.']);
            return;
        }
        try {
            $pdo = $this->connection->get();
            $exists = $pdo->prepare('SELECT COUNT(*) FROM irc_networks WHERE id = ?');
            $exists->execute([$networkId]);
            if ((int) $exists->fetchColumn() !== 1) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'IRC-Netzwerk nicht gefunden.']);
                return;
            }
            // Ensure the channel is part of this bot's JOIN list as well as having a key.
            $networkStmt = $pdo->prepare('SELECT channels FROM irc_networks WHERE id = ?');
            $networkStmt->execute([$networkId]);
            $configuredChannels = (string) $networkStmt->fetchColumn();
            $channelEntries = preg_split('/[,\r\n]+/', $configuredChannels) ?: [];
            $alreadyConfigured = false;
            foreach ($channelEntries as $entry) {
                $entryChannel = trim(explode(':', trim($entry), 2)[0]);
                if ($entryChannel !== '' && strcasecmp($entryChannel, $channel) === 0) {
                    $alreadyConfigured = true;
                    break;
                }
            }
            if (!$alreadyConfigured) {
                $channelEntries[] = $channel;
                $pdo->prepare('UPDATE irc_networks SET channels = ? WHERE id = ?')
                    ->execute([implode("\n", array_filter(array_map('trim', $channelEntries))), $networkId]);
            }
            $vault = new \FortKnox\IRC\FiSHKeyVault(dirname(__DIR__, 3));
            (new \FortKnox\IRC\FiSHKeyStore($pdo, $vault, $networkId))
                ->store($channel, ($mode === 'cbc' ? 'cbc:' : '') . $key);
            echo json_encode([
                'success' => true,
                'channel' => $channel,
                'mode' => strtoupper($mode),
                'message' => 'FiSH-Schlüssel verschlüsselt gespeichert. Der Channel wurde bei Bedarf zur Bot-JOIN-Liste hinzugefügt. Bitte diesen Bot neu starten.',
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'FiSH-Schlüssel konnte nicht gespeichert werden. Prüfe Server-Log und Vault-Konfiguration.']);
        }
    }

    public function logs(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!\FortKnox\Web\Controller\AuthController::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $pdo = $this->connection->get();
        $networks = $pdo->query('SELECT id, name FROM irc_networks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $requested = (string) ($_GET['network_id'] ?? 'all');
        $limit = max(20, min(500, (int) ($_GET['lines'] ?? 200)));
        $allowed = array_map(static fn($n) => (string) $n['id'], $networks);
        if ($requested !== 'all' && !in_array($requested, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Unbekannte Netzwerk-ID.']);
            return;
        }
        $selected = $requested === 'all' ? $networks : array_values(array_filter($networks, static fn($n) => (string) $n['id'] === $requested));
        $logs = [];
        foreach ($selected as $network) {
            $id = (int) $network['id'];
            $path = $this->logDirectory . "/irc-{$id}.log";
            $lines = [];
            if (is_file($path) && is_readable($path)) {
                $raw = file($path, FILE_IGNORE_NEW_LINES);
                $lines = array_slice($raw ?: [], -$limit);
            }
            foreach ($lines as $line) {
                // Defense in depth: never expose authentication payloads in the admin log viewer.
                $line = preg_replace('/(PRIVMSG\s+\S+\s+:\s*(?:IDENTIFY|RECOVER)\s+)\S+/i', '$1[REDACTED]', $line) ?? $line;
                $line = preg_replace('/(^|\s)PASS\s+\S+/i', '$1PASS [REDACTED]', $line) ?? $line;
                $logs[] = ['network_id' => $id, 'network' => (string) $network['name'], 'line' => $line];
            }
        }
        $events = $pdo->query('SELECT event_type, message, source, created_at FROM release_events ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['networks' => $networks, 'logs' => $logs, 'events' => $events, 'log_files_available' => count(array_filter($selected, fn($n) => is_readable($this->logDirectory . '/irc-' . (int) $n['id'] . '.log')))], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}

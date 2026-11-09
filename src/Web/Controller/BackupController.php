<?php

declare(strict_types=1);

namespace FortKnox\Web\Controller;

use FortKnox\Database\Connection;

final class BackupController
{
    private string $backupDir;
    private readonly array $dbConfig;

    public function __construct(
        private readonly Connection $connection,
        array $dbConfig
    ) {
        // public/index.php passes parsed .env keys (DB_HOST, DB_DATABASE, ...).
        // Accept both the .env names and normalized names for compatibility.
        $this->dbConfig = [
            'host' => (string) ($dbConfig['DB_HOST'] ?? $dbConfig['host'] ?? '127.0.0.1'),
            'port' => (int) ($dbConfig['DB_PORT'] ?? $dbConfig['port'] ?? 3306),
            'database' => (string) ($dbConfig['DB_DATABASE'] ?? $dbConfig['database'] ?? ''),
            'username' => (string) ($dbConfig['DB_USERNAME'] ?? $dbConfig['username'] ?? ''),
            'password' => (string) ($dbConfig['DB_PASSWORD'] ?? $dbConfig['password'] ?? ''),
        ];
        $this->backupDir = dirname(__DIR__, 3) . '/storage/backups';
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0775, true);
        }
    }

    private function getDumpBinary(): string
    {
        $check = trim((string) shell_exec('which mariadb-dump 2>/dev/null'));
        if ($check !== '') {
            return 'mariadb-dump';
        }
        return 'mysqldump';
    }

    private function createClientDefaultsFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fk-mariadb-');
        if ($path === false) {
            throw new \RuntimeException('Temporäre Datenbank-Konfiguration konnte nicht angelegt werden.');
        }

        $lines = ['[client]'];
        foreach (['user' => 'username', 'password' => 'password', 'host' => 'host', 'port' => 'port'] as $option => $key) {
            $value = (string) ($this->dbConfig[$key] ?? '');
            if ($value === '' && $option !== 'password') {
                continue;
            }
            $escaped = str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\"', '\n', '\r'], $value);
            $lines[] = $option . '="' . $escaped . '"';
        }

        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            @unlink($path);
            throw new \RuntimeException('Temporäre Datenbank-Konfiguration konnte nicht geschrieben werden.');
        }
        @chmod($path, 0600);
        return $path;
    }

    public function listBackups(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!AuthController::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $files = [];
        $scan = glob($this->backupDir . '/*.{sql.gz,tar.gz,sql}', GLOB_BRACE) ?: [];
        foreach ($scan as $file) {
            $files[] = [
                'name' => basename($file),
                'size' => round(filesize($file) / 1024 / 1024, 2) . ' MB',
                'created_at' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }

        usort($files, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        $dumpBin = $this->getDumpBinary();
        $isRunning = (int) shell_exec("pgrep -x '{$dumpBin}' >/dev/null 2>&1; echo $?") === 0;

        echo json_encode(['success' => true, 'backups' => $files, 'dump_running' => $isRunning]);
    }

    public function createConfigBackup(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!AuthController::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $date = date('Y-m-d_H-i-s');
        $filename = "config_backup_{$date}.sql.gz";
        $target = $this->backupDir . '/' . $filename;
        $partial = $target . '.part';
        $defaultsFile = $this->createClientDefaultsFile();
        $errorFile = tempnam(sys_get_temp_dir(), 'fk-dump-error-');

        try {
            $pdo = $this->connection->get();
            $allTables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $ignoreTables = ['releases', 'predb', 'pre_releases', 'releases_archive'];
            $tablesToDump = array_values(array_filter(
                $allTables,
                fn($table) => !in_array(strtolower((string) $table), $ignoreTables, true)
            ));

            if (!$tablesToDump) {
                http_response_code(500);
                echo json_encode(['error' => 'Keine Tabellen für das Konfigurationsbackup gefunden.']);
                return;
            }

            $tablesArg = implode(' ', array_map('escapeshellarg', $tablesToDump));
            $dumpBin = escapeshellarg($this->getDumpBinary());
            $defaultsArg = escapeshellarg('--defaults-extra-file=' . $defaultsFile);
            $database = escapeshellarg($this->dbConfig['database']);
            $stderr = $errorFile !== false ? ' 2>' . escapeshellarg($errorFile) : ' 2>/dev/null';
            $command = $dumpBin . ' ' . $defaultsArg . ' --single-transaction --quick '
                . $database . ' ' . $tablesArg . $stderr . ' | gzip -c > ' . escapeshellarg($partial);

            exec('/bin/bash -o pipefail -c ' . escapeshellarg($command), $output, $returnCode);
            exec('gzip -t ' . escapeshellarg($partial) . ' 2>/dev/null', $gzipOutput, $gzipCode);

            if ($returnCode === 0 && $gzipCode === 0 && is_file($partial) && filesize($partial) > 50) {
                @chmod($partial, 0600);
                if (!@rename($partial, $target)) {
                    @unlink($partial);
                    http_response_code(500);
                    echo json_encode(['error' => 'Backup konnte nicht finalisiert werden.']);
                    return;
                }
                echo json_encode(['success' => true, 'file' => $filename]);
            } else {
                $detail = $errorFile && is_file($errorFile) ? trim((string) file_get_contents($errorFile)) : '';
                @unlink($partial);
                http_response_code(500);
                echo json_encode(['error' => 'Datenbank-Dump fehlgeschlagen.' . ($detail !== '' ? ' ' . substr($detail, 0, 240) : '')]);
            }
        } finally {
            @unlink($defaultsFile);
            if ($errorFile !== false) @unlink($errorFile);
        }
    }

    public function startFullDump(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!AuthController::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $date = date('Y-m-d_H-i-s');
        $target = $this->backupDir . "/fulldb_backup_{$date}.sql.gz";
        $partial = $target . '.part';
        $defaultsFile = $this->createClientDefaultsFile();

        $dumpBin = escapeshellarg($this->getDumpBinary());
        $defaultsArg = escapeshellarg('--defaults-extra-file=' . $defaultsFile);
        $database = escapeshellarg($this->dbConfig['database']);
        $cleanup = 'rm -f ' . escapeshellarg($defaultsFile) . ' ' . escapeshellarg($partial);
        $script = 'umask 077; trap ' . escapeshellarg($cleanup) . ' EXIT; '
            . $dumpBin . ' ' . $defaultsArg . ' --single-transaction --quick '
            . $database . ' | gzip -c > ' . escapeshellarg($partial)
            . ' && gzip -t ' . escapeshellarg($partial)
            . ' && mv ' . escapeshellarg($partial) . ' ' . escapeshellarg($target);

        $background = 'nohup /bin/bash -o pipefail -c ' . escapeshellarg($script)
            . ' >/dev/null 2>&1 </dev/null &';
        exec($background);

        echo json_encode([
            'success' => true,
            'message' => 'Vollständiger Datenbank-Dump wurde im Hintergrund gestartet.',
            'file' => basename($target),
        ]);
    }

    public function downloadBackup(string $file): void
    {
        if (!AuthController::check()) {
            http_response_code(401);
            exit('Unauthorized');
        }

        $cleanFile = basename($file);
        if (!preg_match('/\.(?:sql\.gz|tar\.gz|sql)$/i', $cleanFile)) {
            http_response_code(400);
            exit('Ungültiger Backup-Dateityp.');
        }
        $path = $this->backupDir . '/' . $cleanFile;

        if (!is_file($path)) {
            http_response_code(404);
            exit('Backup nicht gefunden.');
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $cleanFile . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function deleteBackup(string $file): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!AuthController::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $cleanFile = basename($file);
        if (!preg_match('/\.(?:sql\.gz|tar\.gz|sql)$/i', $cleanFile)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ungültiger Backup-Dateityp.']);
            return;
        }
        $path = $this->backupDir . '/' . $cleanFile;

        if (is_file($path)) {
            @unlink($path);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Datei existiert nicht.']);
        }
    }
}

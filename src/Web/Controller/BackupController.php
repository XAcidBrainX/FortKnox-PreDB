<?php

declare(strict_types=1);

namespace FortKnox\Web\Controller;

use FortKnox\Database\Connection;

final class BackupController
{
    private string $backupDir;

    public function __construct(
        private readonly Connection $connection,
        private readonly array $dbConfig
    ) {
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
        $isRunning = (int) shell_exec("pgrep -f '{$dumpBin}' | wc -l") > 0;

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

        $user = escapeshellarg($this->dbConfig['username'] ?? 'root');
        $pass = escapeshellarg($this->dbConfig['password'] ?? '');
        $db = escapeshellarg($this->dbConfig['database'] ?? 'fortknox');
        $dumpBin = $this->getDumpBinary();

        // Alle Tabellen außer den Massendaten-Tabellen ermitteln
        $pdo = $this->connection->get();
        $stmt = $pdo->query("SHOW TABLES");
        $allTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $ignoreTables = ['releases', 'predb', 'pre_releases', 'releases_archive'];
        $tablesToDump = array_filter($allTables, fn($t) => !in_array(strtolower($t), $ignoreTables, true));

        if (empty($tablesToDump)) {
            $tablesArg = 'admin_users';
        } else {
            $tablesArg = implode(' ', array_map('escapeshellarg', $tablesToDump));
        }

        $cmd = "{$dumpBin} -u{$user} -p{$pass} {$db} {$tablesArg} 2>&1 | gzip > " . escapeshellarg($target);
        exec($cmd, $output, $ret);

        if (file_exists($target) && filesize($target) > 50) {
            echo json_encode(['success' => true, 'file' => $filename]);
        } else {
            @unlink($target);
            http_response_code(500);
            echo json_encode(['error' => 'Dump fehlgeschlagen: ' . implode(' ', array_slice($output, 0, 3))]);
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

        $user = escapeshellarg($this->dbConfig['username'] ?? 'root');
        $pass = escapeshellarg($this->dbConfig['password'] ?? '');
        $db = escapeshellarg($this->dbConfig['database'] ?? 'fortknox');
        $dumpBin = $this->getDumpBinary();

        $cmd = "nohup sh -c '{$dumpBin} --single-transaction --quick -u{$user} -p{$pass} {$db} | gzip > {$target}' > /dev/null 2>&1 &";
        exec($cmd);

        echo json_encode(['success' => true, 'message' => 'Vollständiges Backup wurde im Hintergrund gestartet.']);
    }

    public function downloadBackup(string $file): void
    {
        if (!AuthController::check()) {
            http_response_code(401);
            exit('Unauthorized');
        }

        $cleanFile = basename($file);
        $path = $this->backupDir . '/' . $cleanFile;

        if (!file_exists($path)) {
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
        $path = $this->backupDir . '/' . $cleanFile;

        if (file_exists($path)) {
            @unlink($path);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Datei existiert nicht.']);
        }
    }
}

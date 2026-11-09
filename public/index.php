<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use FortKnox\Database\Connection;
use FortKnox\Web\Controller\AuthController;
use FortKnox\Web\Controller\DashboardController;
use FortKnox\Web\Controller\ReleaseController;
use FortKnox\Web\Controller\BackupController;
use FortKnox\Web\Controller\MonitorController;
use FortKnox\Web\Router;

$envFile = dirname(__DIR__) . '/.env';

if (!is_readable($envFile)) {
    http_response_code(500);
    echo 'Missing .env file.';
    exit(1);
}

$env = parse_ini_file($envFile);

if ($env === false) {
    http_response_code(500);
    echo 'Unable to read .env file.';
    exit(1);
}

$connection = new Connection([
    'host' => $env['DB_HOST'] ?? '127.0.0.1',
    'port' => (int) ($env['DB_PORT'] ?? 3306),
    'database' => $env['DB_DATABASE'] ?? '',
    'username' => $env['DB_USERNAME'] ?? '',
    'password' => $env['DB_PASSWORD'] ?? '',
    'charset' => $env['DB_CHARSET'] ?? 'utf8mb4',
]);

$releaseController = new ReleaseController($connection);
$backupController = new BackupController($connection, $env);
$monitorController = new MonitorController($connection, dirname(__DIR__) . '/storage/logs');
$dashboardController = new DashboardController($connection);
$authController = new AuthController(
    (string) ($env['ADMIN_USER'] ?? 'admin'),
    (string) ($env['ADMIN_PASSWORD_HASH'] ?? ''),
    $connection
);

$router = new Router();

// Public Read-APIs
$router->get('/api/releases', fn() => $releaseController->index());
$router->get('/api/releases/search', fn () => $releaseController->search($_GET));
$router->get('/api/releases/live', fn () => $releaseController->live($_GET));
$router->get('/api/releases/{id}', fn (string $id) => $releaseController->show((int) $id));
$router->get('/api/releases/{id}/events', fn (string $id) => $releaseController->events((int) $id));
$router->get('/api/dashboard', fn() => $dashboardController->index());
$router->post('/api/visits/track', fn() => $monitorController->trackVisit());

// Auth APIs
$router->post('/api/auth/login', fn() => $authController->login());
$router->post('/api/auth/logout', fn() => $authController->logout());
$router->get('/api/auth/status', fn() => $authController->status());
$router->get('/api/admin/stats', fn() => $authController->stats());

// Admin Protected Actions
$requireAuth = function (callable $action) {
    return function (...$args) use ($action) {
        if (!AuthController::check()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        return $action(...$args);
    };
};

// Admin monitoring: authenticated read-only statistics and IRC logs.
$router->get('/api/admin/monitor/stats', $requireAuth(fn() => $monitorController->stats()));
$router->get('/api/admin/monitor/logs', $requireAuth(fn() => $monitorController->logs()));
$router->post('/api/admin/monitor/fish-key', $requireAuth(fn() => $monitorController->saveFishKey()));

// Bot Management APIs
$router->get('/api/admin/bots', $requireAuth(fn() => $authController->listBots()));
$router->post('/api/admin/bots', $requireAuth(fn() => $authController->saveBot()));
$router->post('/api/admin/bots/{id}/start', $requireAuth(fn (string $id) => $authController->toggleBotService((int) $id, 'start')));
$router->post('/api/admin/bots/{id}/stop', $requireAuth(fn (string $id) => $authController->toggleBotService((int) $id, 'stop')));
$router->post('/api/admin/bots/{id}/restart', $requireAuth(fn (string $id) => $authController->toggleBotService((int) $id, 'restart')));
$router->post('/api/admin/bots/{id}/delete', $requireAuth(fn (string $id) => $authController->deleteBot((int) $id)));
// --- External Sources Admin Routes ---
$router->get('/api/admin/sources', $requireAuth(function () use ($connection) {
    header('Content-Type: application/json');
    $pdo = $connection->get();
    $stmt = $pdo->query('SELECT * FROM external_sources ORDER BY id ASC');
    echo json_encode(['success' => true, 'sources' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
}));

$router->post('/api/admin/sources', $requireAuth(function () use ($connection) {
    header('Content-Type: application/json');
    $pdo = $connection->get();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = !empty($input['id']) ? (int) $input['id'] : null;
    $name = trim((string) ($input['name'] ?? ''));
    $url = trim((string) ($input['url'] ?? ''));
    $type = trim((string) ($input['type'] ?? 'rss'));
    $enabled = isset($input['enabled']) ? (int) $input['enabled'] : 1;
    $interval = (int) ($input['sync_interval_min'] ?? 5);

    if ($name === '' || $url === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Name und URL sind erforderlich.']);
        return;
    }

    if ($id) {
        $stmt = $pdo->prepare('UPDATE external_sources SET name = ?, url = ?, type = ?, enabled = ?, sync_interval_min = ? WHERE id = ?');
        $stmt->execute([$name, $url, $type, $enabled, $interval, $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO external_sources (name, url, type, enabled, sync_interval_min) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $url, $type, $enabled, $interval]);
    }
    echo json_encode(['success' => true]);
}));

$router->post('/api/admin/sources/{id}/toggle', $requireAuth(function (string $id) use ($connection) {
    header('Content-Type: application/json');
    $pdo = $connection->get();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $stmt = $pdo->prepare('UPDATE external_sources SET enabled = ? WHERE id = ?');
    $stmt->execute([$enabled, (int) $id]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/admin/sources/{id}/delete', $requireAuth(function (string $id) use ($connection) {
    header('Content-Type: application/json');
    $pdo = $connection->get();
    $stmt = $pdo->prepare('DELETE FROM external_sources WHERE id = ?');
    $stmt->execute([(int) $id]);
    echo json_encode(['success' => true]);
}));
// Release Moderation APIs
$router->post('/api/releases/{id}/nuke', $requireAuth(fn (string $id) => $releaseController->nuke((int) $id)));
$router->post('/api/releases/{id}/unnuke', $requireAuth(fn (string $id) => $releaseController->unnuke((int) $id)));
$router->post('/api/releases/{id}/dupe', $requireAuth(fn (string $id) => $releaseController->dupe((int) $id)));


// Admin Backups API
$router->get('/api/admin/backups', $requireAuth(fn() => $backupController->listBackups()));
$router->post('/api/admin/backups/config', $requireAuth(fn() => $backupController->createConfigBackup()));
$router->post('/api/admin/backups/full', $requireAuth(fn() => $backupController->startFullDump()));
$router->get('/api/admin/backups/{filename}/download', $requireAuth(fn (string $filename) => $backupController->downloadBackup($filename)));
$router->post('/api/admin/backups/{filename}/delete', $requireAuth(fn (string $filename) => $backupController->deleteBackup($filename)));

// Admin Users API
$router->get('/api/admin/users', $requireAuth(function () use ($connection) {
    header('Content-Type: application/json; charset=utf-8');
    $pdo = $connection->get();
    $stmt = $pdo->query('SELECT id, username, role, is_active, last_login, created_at FROM admin_users ORDER BY id ASC');
    echo json_encode(['success' => true, 'users' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
}));

$router->post('/api/admin/users', $requireAuth(function () use ($connection) {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = !empty($data['id']) ? (int) $data['id'] : 0;
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $role = (string) ($data['role'] ?? 'operator');
    $active = !empty($data['is_active']) ? 1 : 0;
    if ($username === '' || !preg_match('/^[a-zA-Z0-9_.-]{2,64}$/', $username) || !in_array($role, ['admin', 'operator'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Benutzername oder Rolle ist ungültig.']);
        return;
    }
    $pdo = $connection->get();
    if ($id > 0) {
        $check = $pdo->prepare('SELECT username, role, is_active FROM admin_users WHERE id = ?');
        $check->execute([$id]);
        $existing = $check->fetchColumn();
        if ($existing === false) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Benutzer nicht gefunden.']);
            return;
        }
        if ((string) $existing['username'] === (string) ($_SESSION['admin_user'] ?? '') && $active === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Der aktuell angemeldete Benutzer kann sich nicht selbst sperren.']);
            return;
        }
        if ($existing['role'] === 'admin' && ($role !== 'admin' || $active === 0) && (int) $existing['is_active'] === 1) {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
            if ($count <= 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Der letzte aktive Administrator kann nicht herabgestuft oder gesperrt werden.']);
                return;
            }
        }
        $sql = 'UPDATE admin_users SET username = ?, role = ?, is_active = ?';
        $params = [$username, $role, $active];
        if ($password !== '') {
            if (strlen($password) < 10) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Das Passwort muss mindestens 10 Zeichen lang sein.']);
                return;
            }
            $sql .= ', password_hash = ?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = ?';
        $params[] = $id;
        try {
            $pdo->prepare($sql)->execute($params);
        } catch (\PDOException $e) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Der Benutzername ist bereits vergeben.']);
            return;
        }
    } else {
        if (strlen($password) < 10) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ein neues Passwort muss mindestens 10 Zeichen lang sein.']);
            return;
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash, role, is_active) VALUES (?, ?, ?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
            $id = (int) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Der Benutzername ist bereits vergeben.']);
            return;
        }
    }
    echo json_encode(['success' => true, 'id' => $id]);
}));

$router->post('/api/admin/users/{id}/toggle', $requireAuth(function (string $id) use ($connection) {
    header('Content-Type: application/json; charset=utf-8');
    $pdo = $connection->get();
    $stmt = $pdo->prepare('SELECT username, is_active, role FROM admin_users WHERE id = ?');
    $stmt->execute([(int) $id]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Benutzer nicht gefunden.']);
        return;
    }
    $newActive = (int) $user['is_active'] === 1 ? 0 : 1;
    if ($newActive === 0 && (string) $user['username'] === (string) ($_SESSION['admin_user'] ?? '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Der aktuell angemeldete Benutzer kann sich nicht selbst sperren.']);
        return;
    }
    if ($newActive === 0 && $user['role'] === 'admin') {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        if ($count <= 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Der letzte aktive Administrator kann nicht gesperrt werden.']);
            return;
        }
    }
    $pdo->prepare('UPDATE admin_users SET is_active = ? WHERE id = ?')->execute([$newActive, (int) $id]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/admin/users/{id}/delete', $requireAuth(function (string $id) use ($connection) {
    header('Content-Type: application/json; charset=utf-8');
    $pdo = $connection->get();
    $stmt = $pdo->prepare('SELECT username, role FROM admin_users WHERE id = ?');
    $stmt->execute([(int) $id]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Benutzer nicht gefunden.']);
        return;
    }
    if ((string) $user['username'] === (string) ($_SESSION['admin_user'] ?? '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Der aktuell angemeldete Benutzer kann sich nicht selbst löschen.']);
        return;
    }
    if ($user['role'] === 'admin') {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        if ($count <= 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Der letzte aktive Administrator kann nicht gelöscht werden.']);
            return;
        }
    }
    $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([(int) $id]);
    echo json_encode(['success' => true]);
}));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    $router->dispatch($method, $path ?: '/');
} catch (\Throwable $e) {
    file_put_contents("/tmp/php-crash.log", "[ERROR] " . $e->getMessage() . " in " . $e->getFile() . " Zeile " . $e->getLine() . "\n", FILE_APPEND);
    http_response_code(500);
}

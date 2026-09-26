<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use FortKnox\Database\Connection;
use FortKnox\Web\Controller\AuthController;
use FortKnox\Web\Controller\DashboardController;
use FortKnox\Web\Controller\ReleaseController;
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
$dashboardController = new DashboardController($connection);
$authController = new AuthController(
    (string) ($env['ADMIN_USER'] ?? 'admin'),
    (string) ($env['ADMIN_PASSWORD_HASH'] ?? ''),
    $connection
);

$router = new Router();

// Public Read-APIs
$router->get('/api/releases', [$releaseController, 'index']);
$router->get('/api/releases/search', fn () => $releaseController->search($_GET));
$router->get('/api/releases/live', fn () => $releaseController->live($_GET));
$router->get('/api/releases/{id}', fn (string $id) => $releaseController->show((int) $id));
$router->get('/api/releases/{id}/events', fn (string $id) => $releaseController->events((int) $id));
$router->get('/api/dashboard', [$dashboardController, 'index']);

// Auth APIs
$router->post('/api/auth/login', [$authController, 'login']);
$router->post('/api/auth/logout', [$authController, 'logout']);
$router->get('/api/auth/status', [$authController, 'status']);
$router->get('/api/admin/stats', [$authController, 'stats']);

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

// Bot Management APIs
$router->get('/api/admin/bots', $requireAuth([$authController, 'listBots']));
$router->post('/api/admin/bots', $requireAuth([$authController, 'saveBot']));
$router->post('/api/admin/bots/{id}/start', $requireAuth(fn (string $id) => $authController->toggleBotService((int) $id, 'start')));
$router->post('/api/admin/bots/{id}/stop', $requireAuth(fn (string $id) => $authController->toggleBotService((int) $id, 'stop')));
$router->post('/api/admin/bots/{id}/restart', $requireAuth(fn (string $id) => $authController->toggleBotService((int) $id, 'restart')));
$router->post('/api/admin/bots/{id}/delete', $requireAuth(fn (string $id) => $authController->deleteBot((int) $id)));
// --- External Sources Admin Routes ---
$router->get('/api/admin/sources', $requireAuth(function () use ($connection) {
    header('Content-Type: application/json');
    $pdo = $connection->get();
    $stmt = $pdo->query('SELECT * FROM external_sources ORDER BY id ASC');
    echo json_encode(['success' => true, 'sources' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$router->dispatch($method, $path ?: '/');

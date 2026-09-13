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
    (string) ($env['ADMIN_PASSWORD_HASH'] ?? '')
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

$router->post('/api/releases/{id}/nuke', $requireAuth(fn (string $id) => $releaseController->nuke((int) $id)));
$router->post('/api/releases/{id}/unnuke', $requireAuth(fn (string $id) => $releaseController->unnuke((int) $id)));
$router->post('/api/releases/{id}/dupe', $requireAuth(fn (string $id) => $releaseController->dupe((int) $id)));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$router->dispatch($method, $path ?: '/');

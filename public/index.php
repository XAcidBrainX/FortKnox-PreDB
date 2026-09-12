<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use FortKnox\Database\Connection;
use FortKnox\Web\Controller\ReleaseController;
use FortKnox\Web\Router;
use FortKnox\Web\Controller\DashboardController;

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

$releaseController = new ReleaseController(
    $connection
);
$dashboardController = new DashboardController($connection);$dashboardController = new DashboardController($connection);

$router = new Router();

$router->get(
    '/api/releases',
    [$releaseController, 'index']
);

$router->get(
    '/api/releases/search',
    fn () => $releaseController->search($_GET)
);

$router->get(
    '/api/releases/{id}',
    fn (string $id) => $releaseController->show((int) $id)
);

$router->get(
    '/api/releases/{id}/events',
    fn (string $id) => $releaseController->events((int) $id)
);

$router->get(
    '/api/dashboard',
    [$dashboardController, 'index']
);

$router->post(
    '/api/releases/{id}/nuke',
    fn (string $id) => $releaseController->nuke((int) $id)
);

$router->post(
    '/api/releases/{id}/unnuke',
    fn (string $id) => $releaseController->unnuke((int) $id)
);

$router->post(
    '/api/releases/{id}/dupe',
    fn (string $id) => $releaseController->dupe((int) $id)
);

$path = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
);

$method = strtoupper(
    $_SERVER['REQUEST_METHOD'] ?? 'GET'
);

$router->dispatch(
    $method,
    $path ?: '/'
);

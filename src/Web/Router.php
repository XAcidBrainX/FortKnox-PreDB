<?php

declare(strict_types=1);

namespace FortKnox\Web;

use FortKnox\Web\Response\JsonResponse;

final class Router
{
    /**
     * @var array<string, array<string, callable>>
     */
    private array $routes = [];

    public function get(
        string $path,
        callable $handler
    ): void {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(
        string $path,
        callable $handler
    ): void {
        $this->addRoute('POST', $path, $handler);
    }

    private function addRoute(
        string $method,
        string $path,
        callable $handler
    ): void {
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(
        string $method,
        string $path
    ): void {
        $method = strtoupper($method);

        $handler = $this->routes[$method][$path] ?? null;

        if ($handler !== null) {
            $handler();

            return;
        }

        foreach ($this->routes[$method] ?? [] as $route => $routeHandler) {
            $parameters = $this->matchRoute($route, $path);

            if ($parameters === null) {
                continue;
            }

            $routeHandler(...$parameters);

            return;
        }

        JsonResponse::send([
            'success' => false,
            'error' => 'Route not found',
            'method' => $method,
            'path' => $path,
        ], 404);
    }

    /**
     * @return array<int, string>|null
     */
    private function matchRoute(
        string $route,
        string $path
    ): ?array {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $matches): string {
                return '([^/]+)';
            },
            $route
        );

        if ($pattern === null) {
            return null;
        }

        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $path, $matches) !== 1) {
            return null;
        }

        array_shift($matches);

        return $matches;
    }
}

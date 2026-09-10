<?php

declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];
    private array $middlewares = [];

    public function addRoute(string $method, string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'pattern' => $this->convertPathToRegex($path),
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function use(callable|array $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function get(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    public function patch(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middlewares);
    }

    public function delete(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    public function dispatch(Request $request): void
    {
        $requestMethod = $request->getMethod();
        $requestPath = rtrim($request->getPath(), '/');
        if ($requestPath === '') {
            $requestPath = '/';
        }

        // Run global middleware
        foreach ($this->middlewares as $middleware) {
            $this->invokeCallable($middleware, [$request]);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod && !($requestMethod === 'HEAD' && $route['method'] === 'GET')) {
                continue;
            }

            if (preg_match($route['pattern'], $requestPath, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Run route middleware
                foreach ($route['middlewares'] as $middleware) {
                    $this->invokeCallable($middleware, [$request, $params]);
                }

                $this->invokeCallable($route['handler'], [$request, $params]);
                return;
            }
        }

        // No route matched: 404
        Response::error(
            'NOT_FOUND',
            "The requested resource '{$requestMethod} {$requestPath}' was not found.",
            404
        );
    }

    private function convertPathToRegex(string $path): string
    {
        $trimmed = rtrim($path, '/');
        if ($trimmed === '') {
            return '#^/?$#';
        }
        $escaped = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $trimmed);
        return '#^' . $escaped . '/?$#';
    }

    private function invokeCallable(callable|array $callable, array $args = []): mixed
    {
        if (is_array($callable) && is_string($callable[0])) {
            $callable[0] = new $callable[0]();
        }
        return call_user_func_array($callable, $args);
    }
}

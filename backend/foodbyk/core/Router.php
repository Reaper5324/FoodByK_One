<?php

class Router {

    private array $routes = [];

    public function get(string $path, array $action, array $middleware = []): void    { $this->add('GET', $path, $action, $middleware); }
    public function post(string $path, array $action, array $middleware = []): void   { $this->add('POST', $path, $action, $middleware); }
    public function put(string $path, array $action, array $middleware = []): void    { $this->add('PUT', $path, $action, $middleware); }
    public function delete(string $path, array $action, array $middleware = []): void { $this->add('DELETE', $path, $action, $middleware); }

    private function add(string $method, string $path, array $action, array $middleware): void {
        $this->routes[$method][$path] = ['action' => $action, 'middleware' => $middleware];
    }

    public function dispatch(Request $request): Response {
        [$route, $params] = $this->match($request->method, $request->path);

        if (!$route) {
            return Response::error('Not found.', 404);
        }

        // Chain of Responsibility - each middleware runs in order; the
        // first one to return a Response stops the chain right there.
        foreach ($route['middleware'] as $middleware) {
            $result = $middleware->handle($request);
            if ($result !== null) {
                return $result;
            }
        }

        [$controllerClass, $method] = $route['action'];

        try {
            $controller = new $controllerClass();
            return $controller->$method($request, $params);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            // Never leak internals to the client - see AGENTS.md §6 on display_errors.
            return Response::error('An unexpected error occurred.', 500);
        }
    }

    private function match(string $method, string $path): array {
        foreach ($this->routes[$method] ?? [] as $pattern => $route) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
                return [$route, $params];
            }
        }
        return [null, []];
    }
}
<?php

declare(strict_types=1);

namespace PikaRelay\Http;

/**
 * Minimal router. Patterns are literal segments plus {name} placeholders that match one
 * whole segment; placeholders are passed to the handler in order.
 */
final class Router
{
    /** @var array<int, array{0: string, 1: string[], 2: callable}> */
    private $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $trimmed = trim($pattern, '/');
        $this->routes[] = [strtoupper($method), $trimmed === '' ? [] : explode('/', $trimmed), $handler];
    }

    public function dispatch(Request $request): Response
    {
        $segments = $request->segments();
        $allowed = [];
        foreach ($this->routes as $route) {
            list($method, $pattern, $handler) = $route;
            $params = $this->match($pattern, $segments);
            if ($params === null) {
                continue;
            }
            if ($method === $request->method()) {
                return $handler(...$params);
            }
            $allowed[] = $method;
        }
        if ($allowed) {
            throw new HttpException(405, 'Method not allowed', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, 'Not found');
    }

    /**
     * @param string[] $pattern
     * @param string[] $segments
     * @return string[]|null
     */
    private function match(array $pattern, array $segments): ?array
    {
        if (count($pattern) !== count($segments)) {
            return null;
        }
        $params = [];
        foreach ($pattern as $i => $part) {
            if ($part !== '' && $part[0] === '{') {
                $params[] = $segments[$i];
            } elseif ($part !== $segments[$i]) {
                return null;
            }
        }
        return $params;
    }
}

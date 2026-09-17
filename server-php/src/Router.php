<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * A router small enough to read in one sitting.
 *
 * Patterns use `{name}` for one path segment. The matched values are passed to
 * the handler in declaration order.
 */
final class Router
{
    /** @var list<array{method:string, segments:list<string>, handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): self
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): self
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = [
            'method'   => $method,
            'segments' => array_values(array_filter(explode('/', trim($pattern, '/')), static fn ($s) => $s !== '')),
            'handler'  => $handler,
        ];

        return $this;
    }

    /**
     * Dispatch, or return false so the caller can answer 404 its own way.
     *
     * A path that matches a pattern under a different verb answers 405 rather
     * than 404: "you used the wrong verb" and "there is nothing here" are
     * different problems and conflating them costs an hour of debugging.
     */
    public function dispatch(string $method, string $path): bool
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($s) => $s !== ''));
        $pathMatchedOtherVerb = false;

        foreach ($this->routes as $route) {
            if (count($route['segments']) !== count($segments)) {
                continue;
            }

            $args = [];
            $matched = true;
            foreach ($route['segments'] as $i => $expected) {
                if (str_starts_with($expected, '{') && str_ends_with($expected, '}')) {
                    $args[] = $segments[$i];
                    continue;
                }
                if ($expected !== $segments[$i]) {
                    $matched = false;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            if ($route['method'] !== $method) {
                $pathMatchedOtherVerb = true;
                continue;
            }

            ($route['handler'])(...$args);

            return true;
        }

        if ($pathMatchedOtherVerb) {
            Http::error(405, 'method_not_allowed', 'That address does not accept ' . $method . ' requests.');
        }

        return false;
    }
}

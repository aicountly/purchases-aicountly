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
    /** @var list<array{method:string, pattern:string, segments:list<string>, handler:callable}> */
    private array $routes = [];

    /**
     * The route currently being dispatched, as "METHOD pattern" — e.g. `GET v1/dashboards/{view}`.
     *
     * A cross-cutting rule (the rate-limit ceiling in `Controllers\Controller::enter()`) has to be
     * able to say WHICH endpoint it is guarding without every endpoint telling it.
     */
    private static ?string $current = null;

    public static function current(): ?string
    {
        return self::$current;
    }

    /** For the in-process test suite, which calls controllers without dispatching. */
    public static function setCurrent(?string $route): void
    {
        self::$current = $route;
    }

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
            'pattern'  => trim($pattern, '/'),
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

            self::$current = $route['method'] . ' ' . $route['pattern'];
            ($route['handler'])(...$args);

            return true;
        }

        if ($pathMatchedOtherVerb) {
            Http::error(405, 'method_not_allowed', 'That address does not accept ' . $method . ' requests.');
        }

        return false;
    }
}

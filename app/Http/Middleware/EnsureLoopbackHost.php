<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses any request whose Host header isn't a loopback name.
 *
 * Nexus executes arbitrary PHP (/tinker) and edits other projects' .env files,
 * and it is served over plain HTTP on 127.0.0.1. That makes it a DNS-rebinding
 * target: a web page on attacker.example re-resolves its own name to 127.0.0.1,
 * becomes same-origin with this server, reads the CSRF cookie and posts code.
 * The rebound request still carries Host: attacker.example, which is what this
 * catches.
 *
 * Inside the desktop runtime NativePHP's secret cookie already blocks that, but
 * `composer dev` serves the same app through `php artisan serve` with no such
 * guard. Laravel's TrustHosts is no help here: it switches itself off in the
 * local environment, which is exactly where Nexus runs.
 */
class EnsureLoopbackHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isLoopback($this->hostOf($request))) {
            abort(421, 'Nexus only answers on localhost.');
        }

        return $next($request);
    }

    /**
     * The raw Host header, port removed. Read directly rather than through
     * getHost(), which would consult trusted-proxy headers.
     */
    private function hostOf(Request $request): string
    {
        $host = strtolower(trim((string) $request->server->get('HTTP_HOST', '')));

        if (str_starts_with($host, '[')) {
            return substr($host, 0, (strpos($host, ']') ?: strlen($host) - 1) + 1);
        }

        return explode(':', $host, 2)[0];
    }

    private function isLoopback(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '[::1]'
            || (bool) preg_match('/^127(?:\.\d{1,3}){3}$/', $host);
    }
}

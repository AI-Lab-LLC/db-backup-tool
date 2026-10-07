<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * IP allowlist for every web route (login included).
 *
 * config('backup.allowed_ips') — comma-separated IPs / CIDRs (env
 * PANEL_ALLOWED_IPS). Empty = allow everyone. The client IP is
 * $request->ip(), which honours TrustProxies (see bootstrap/app.php).
 */
class RestrictPanelIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = self::allowedList();

        if ($allowed === []) {
            return $next($request);
        }

        $ip = $request->ip();

        if ($ip === null || ! IpUtils::checkIp($ip, $allowed)) {
            abort(403, 'Access denied.');
        }

        return $next($request);
    }

    /**
     * @return string[]
     */
    public static function allowedList(): array
    {
        $raw = config('backup.allowed_ips', '');

        $items = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map('trim', $items), fn ($v) => $v !== ''));
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies (read by Illuminate\Http\Middleware\TrustProxies)
    |--------------------------------------------------------------------------
    |
    | empty (default) = trust nobody: $request->ip() is REMOTE_ADDR as nginx
    |        delivers it. This is the right setting on Forge: its nginx already
    |        resolves the real client IP from Cloudflare (set_real_ip_from +
    |        real_ip_header X-Forwarded-For), so PHP sees the client directly.
    | '*'  = trust the immediate peer (REMOTE_ADDR) and take the client from
    |        the right-most X-Forwarded-For entry it appended. Only for setups
    |        where the proxy does NOT rewrite REMOTE_ADDR.
    | 'a,b' = comma-separated IPs / CIDRs to trust.
    |
    | SECURITY — with '*', PANEL_ALLOWED_IPS can be bypassed when nginx already
    | rewrites REMOTE_ADDR to the client (Forge: set_real_ip_from <Cloudflare>
    | + real_ip_header X-Forwarded-For): '*' then trusts the client itself and
    | honours its forged XFF. Keep it empty on Forge.
    |
    */

    // NEVER null: Laravel's TrustProxies treats null as '*' on *.on-forge.com /
    // *.on-vapor.com hosts, which would let a forged X-Forwarded-For bypass
    // PANEL_ALLOWED_IPS. An empty env value means "trust nobody".
    'proxies' => trim((string) env('TRUSTED_PROXIES', '')) !== '' ? env('TRUSTED_PROXIES') : [],

];

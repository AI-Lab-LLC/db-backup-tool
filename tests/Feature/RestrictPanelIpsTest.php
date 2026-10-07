<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestrictPanelIpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_list_allows_everyone(): void
    {
        config(['backup.allowed_ips' => '']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->get('/login')->assertOk();
    }

    public function test_listed_ip_is_allowed(): void
    {
        config(['backup.allowed_ips' => '198.51.100.7, 203.0.113.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get('/login')->assertOk();
    }

    public function test_other_ip_gets_403_on_login_and_panel(): void
    {
        config(['backup.allowed_ips' => '203.0.113.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->get('/login')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->get('/backups')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->post('/login', [])->assertForbidden();
    }

    public function test_cidr_ranges_work(): void
    {
        config(['backup.allowed_ips' => '10.0.0.0/24,2001:db8::/32']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.200'])->get('/login')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->get('/login')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::5'])->get('/login')->assertOk();
    }

    public function test_default_trusts_no_proxy_so_forwarded_for_is_ignored(): void
    {
        // Default (TRUSTED_PROXIES empty): the client IP is REMOTE_ADDR as nginx
        // delivers it — a forged X-Forwarded-For must not grant access.
        config(['backup.allowed_ips' => '203.0.113.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.66'])
            ->withHeader('X-Forwarded-For', '203.0.113.10')
            ->get('/login')->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Forwarded-For', '198.51.100.66')
            ->get('/login')->assertOk();
    }

    public function test_on_forge_host_does_not_enable_laravel_proxy_fallback(): void
    {
        // Laravel's TrustProxies treats a NULL proxies config as '*' on
        // *.on-forge.com hosts; our config must never be null, so a forged
        // X-Forwarded-For is still ignored on the production hostname.
        config(['backup.allowed_ips' => '203.0.113.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.66'])
            ->withHeader('X-Forwarded-For', '203.0.113.10')
            ->get('http://db-backup-tool-ovgnur0h.on-forge.com/login')->assertForbidden();
    }

    public function test_client_ip_is_taken_from_trusted_proxy_forwarded_for(): void
    {
        config(['backup.allowed_ips' => '203.0.113.10', 'trustedproxy.proxies' => '*']);

        // nginx/Cloudflare (REMOTE_ADDR) forwards the real client.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', '203.0.113.10')
            ->get('/login')->assertOk();

        // A client-supplied XFF in front of the proxy-appended one doesn't help.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', '203.0.113.10, 198.51.100.66')
            ->get('/login')->assertForbidden();
    }

    public function test_health_endpoint_is_not_restricted(): void
    {
        config(['backup.allowed_ips' => '203.0.113.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.66'])->get('/up')->assertOk();
    }
}

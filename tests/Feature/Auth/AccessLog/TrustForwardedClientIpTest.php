<?php

namespace Tests\Feature\Auth\AccessLog;

use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\AccessLog\Entities\AccessLogEntry;

class TrustForwardedClientIpTest extends AuthTestCase
{
    private const CLIENT_IP = '203.0.113.7';

    private const PROXY_IP = '10.0.0.1';

    #[Test]
    public function when_a_forwarded_client_signs_in_then_its_address_is_logged(): void
    {
        // Given a registered user whose request reaches the app through a proxy
        $this->aRegisteredUser();
        $this->forwardedFrom(self::CLIENT_IP, self::PROXY_IP);

        // When they sign in
        $response = $this->postJson('/api/v1/auth/login', ['email' => self::LOGIN_EMAIL, 'password' => self::LOGIN_PASSWORD]);

        // Then the logged address is the client's, not the proxy's
        $response->assertOk();
        $this->assertSame(self::CLIENT_IP, AccessLogEntry::query()->sole()->ip_address);
    }

    private function forwardedFrom(string $clientIp, string $proxyIp): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $proxyIp]);
        $this->withHeader('X-Forwarded-For', $clientIp);
    }
}

<?php

namespace Tests\Feature\Auth\AccessLog;

use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\AccessLog\Entities\AccessLogEntry;

class TrustForwardedClientIpTest extends AuthTestCase
{
    private const CLIENT_IP = '203.0.113.7';

    private const PROXY_IP = '10.0.0.1';

    #[Test]
    public function when_one_forwarded_client_exceeds_the_sign_in_limit_then_it_is_throttled(): void
    {
        // Given one client that has used its ten attempts, each through a different proxy hop
        foreach (range(1, 10) as $hop) {
            $this->attemptSignIn(self::CLIENT_IP, "10.0.0.{$hop}")->assertUnprocessable();
        }

        // When it tries again through yet another hop
        $response = $this->attemptSignIn(self::CLIENT_IP, '10.0.0.11');

        // Then it is throttled as one client
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_another_forwarded_client_signs_in_then_it_has_its_own_limit(): void
    {
        // Given one client that has used its ten attempts through the proxy
        foreach (range(1, 10) as $attempt) {
            $this->attemptSignIn(self::CLIENT_IP, self::PROXY_IP)->assertUnprocessable();
        }

        // When another client signs in through the same proxy
        $response = $this->attemptSignIn('198.51.100.9', self::PROXY_IP);

        // Then it still reaches the sign-in check
        $response->assertUnprocessable();
    }

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

    private function attemptSignIn(string $clientIp, string $proxyIp): TestResponse
    {
        $this->forwardedFrom($clientIp, $proxyIp);

        return $this->postJson('/api/v1/auth/login', []);
    }

    private function forwardedFrom(string $clientIp, string $proxyIp): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $proxyIp]);
        $this->withHeader('X-Forwarded-For', $clientIp);
    }
}

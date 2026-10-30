<?php

namespace Tests\Feature\Auth\Google;

use Closure;
use Tests\TestCase;
use Firebase\JWT\JWT;
use phpseclib3\Crypt\RSA;
use Google\Auth\AccessToken;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use phpseclib3\Crypt\RSA\PrivateKey;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Auth\IO\Google\GoogleAuthIdTokenVerifier;
use App\Foundation\Auth\UseCases\Google\GoogleIdTokenVerifier;
use App\Foundation\Auth\Exceptions\GoogleTokenInvalidException;
use App\Foundation\Auth\Exceptions\GoogleSignInUnavailableException;

/**
 * @testclass-carveout: adapter — certification of the real google/auth ID-token check against
 * locally generated keys; every feature walk stubs the GoogleIdTokenVerifier boundary
 * (AuthTestCase::fakeGoogleIdTokenVerifier), so this is the only place it runs (§2.14 Rule B).
 */
class GoogleAuthIdTokenVerifierTest extends TestCase
{
    protected const CLIENT_ID = 'lesily-web.apps.googleusercontent.com';

    protected const KEY_ID = 'test-key';

    protected const GOOGLE_CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    protected PrivateKey $googleKey;

    protected string $certsLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->googleKey = $this->aNewRsaKey();
        $this->certsLocation = $this->aKeySetFileFor($this->googleKey);
    }

    protected function tearDown(): void
    {
        @unlink($this->certsLocation);

        parent::tearDown();
    }

    #[Test]
    public function when_the_token_is_signed_by_an_unknown_key_then_it_is_refused(): void
    {
        // Given a token signed by a key Google does not publish
        $token = $this->aSignedToken([], $this->aNewRsaKey());

        // Then it is refused as an invalid Google token
        $this->expectException(GoogleTokenInvalidException::class);

        // When the verifier checks it
        $this->verifierFor(self::CLIENT_ID)->verify($token);
    }

    #[Test]
    public function when_the_token_is_not_a_signed_jwt_then_it_is_refused(): void
    {
        // Given a token whose header is not JSON
        $token = JWT::urlsafeB64Encode('not-json').'.e30.c2lnbmF0dXJl';

        // Then it is refused as an invalid Google token
        $this->expectException(GoogleTokenInvalidException::class);

        // When the verifier checks it
        $this->verifierFor(self::CLIENT_ID)->verify($token);
    }

    /** @param  array<string, mixed>  $wrongClaim */
    #[Test]
    #[DataProvider('provideWrongClaims')]
    public function when_a_token_claim_is_wrong_then_it_is_refused(array $wrongClaim): void
    {
        // Given a token Google signed with one wrong claim
        $token = $this->aSignedToken($wrongClaim);

        // Then it is refused as an invalid Google token
        $this->expectException(GoogleTokenInvalidException::class);

        // When the verifier checks it
        $this->verifierFor(self::CLIENT_ID)->verify($token);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function provideWrongClaims(): array
    {
        return [
            'issued for another app' => [['aud' => 'another-app.apps.googleusercontent.com']],
            'without an email' => [['email' => null]],
            'expired' => [['iat' => time() - 7200, 'exp' => time() - 3600]],
            'issued by someone else' => [['iss' => 'https://accounts.example.com']],
        ];
    }

    #[Test]
    public function when_no_client_id_is_configured_then_every_token_is_refused(): void
    {
        // Given a token Google signed, and no Google client id configured yet
        $token = $this->aSignedToken();

        // Then it is refused, whatever its audience
        $this->expectException(GoogleTokenInvalidException::class);

        // When the verifier checks it
        $this->verifierFor('')->verify($token);
    }

    #[Test]
    public function when_the_token_carries_no_name_then_the_identity_is_read_without_one(): void
    {
        // Given a token Google signed without the profile scope's name
        $token = $this->aSignedToken(['name' => null]);

        // When the verifier checks it
        $identity = $this->verifierFor(self::CLIENT_ID)->verify($token);

        // Then the identity is read, its name empty
        $this->assertSame('109876543210', $identity->subject);
        $this->assertSame('', $identity->name);
    }

    /** @param  Closure(): ResponseInterface  $googleAnswers */
    #[Test]
    #[DataProvider('provideKeyFetchFailures')]
    public function when_googles_keys_cannot_be_fetched_then_verification_reports_sign_in_unavailable(Closure $googleAnswers): void
    {
        // Given Google's key endpoint failing to hand out its keys
        $verifier = new GoogleAuthIdTokenVerifier(new AccessToken($googleAnswers), self::CLIENT_ID, self::GOOGLE_CERTS_URL);

        // Then sign-in is reported unavailable, not the token invalid
        $this->expectException(GoogleSignInUnavailableException::class);

        // When the verifier checks a well-signed token
        $verifier->verify($this->aSignedToken());
    }

    #[Test]
    public function when_keys_were_fetched_once_then_a_later_request_reuses_them(): void
    {
        // Given the production verifier, configured for our client, Google's keys and a key cache
        $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'google-certs-'.uniqid();
        config([
            'services.google.client_id' => self::CLIENT_ID,
            'services.google.certs_location' => $this->certsLocation,
            'services.google.certs_cache_path' => $cachePath,
        ]);
        app(GoogleIdTokenVerifier::class)->verify($this->aSignedToken());
        unlink($this->certsLocation);

        // When a later request verifies a token after Google's keys have gone away
        $identity = app(GoogleIdTokenVerifier::class)->verify($this->aSignedToken());

        // Then the keys come from the cache
        $this->assertSame('alice@contoso.lk', $identity->email);
        File::deleteDirectory($cachePath);
    }

    #[Test]
    public function when_the_token_is_valid_then_the_google_identity_is_read(): void
    {
        // Given a token Google signed for our client id
        $token = $this->aSignedToken();

        // When the verifier checks it
        $identity = $this->verifierFor(self::CLIENT_ID)->verify($token);

        // Then the Google account's identity is read from it
        $this->assertSame('109876543210', $identity->subject);
        $this->assertSame('alice@contoso.lk', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('Alice Perera', $identity->name);
    }

    /** @return array<string, array{Closure(): ResponseInterface}> */
    public static function provideKeyFetchFailures(): array
    {
        return [
            'google answers an error' => [fn (): ResponseInterface => new Response(500)],
            'google is unreachable' => [fn (): ResponseInterface => throw new ConnectException('Connection refused', new Request('GET', self::GOOGLE_CERTS_URL))],
        ];
    }

    protected function verifierFor(string $clientId): GoogleAuthIdTokenVerifier
    {
        return new GoogleAuthIdTokenVerifier(new AccessToken, $clientId, $this->certsLocation);
    }

    /** @param  array<string, mixed>  $claimOverrides */
    protected function aSignedToken(array $claimOverrides = [], ?PrivateKey $signingKey = null): string
    {
        return JWT::encode(array_filter([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '109876543210',
            'email' => 'alice@contoso.lk',
            'email_verified' => true,
            'name' => 'Alice Perera',
            'iat' => time(),
            'exp' => time() + 3600,
            ...$claimOverrides,
        ], fn (mixed $claim): bool => $claim !== null), ($signingKey ?? $this->googleKey)->toString('PKCS8'), 'RS256', self::KEY_ID);
    }

    protected function aNewRsaKey(): PrivateKey
    {
        return RSA::createKey(2048);
    }

    protected function aKeySetFileFor(PrivateKey $key): string
    {
        /** @var array{keys: list<array<string, string>>} $keySet */
        $keySet = json_decode($key->getPublicKey()->toString('JWK'), true);
        $path = (string) tempnam(sys_get_temp_dir(), 'google-certs');
        file_put_contents($path, json_encode(['keys' => [[...$keySet['keys'][0], 'kid' => self::KEY_ID, 'alg' => 'RS256']]]));

        return $path;
    }
}

<?php

namespace App\Foundation\Auth\IO\Google;

use DomainException;
use RuntimeException;
use Illuminate\Support\Arr;
use Google\Auth\AccessToken;
use InvalidArgumentException;
use UnexpectedValueException;
use App\Foundation\Auth\UseCases\Google\GoogleIdentity;
use App\Foundation\Auth\UseCases\Google\GoogleIdTokenVerifier;
use App\Foundation\Auth\Exceptions\GoogleTokenInvalidException;
use App\Foundation\Auth\Exceptions\GoogleSignInUnavailableException;

class GoogleAuthIdTokenVerifier implements GoogleIdTokenVerifier
{
    public function __construct(
        protected AccessToken $accessToken,
        protected string $clientId,
        protected string $certsLocation = AccessToken::FEDERATED_SIGNON_CERT_URL,
    ) {}

    public function verify(string $idToken): GoogleIdentity
    {
        // google/auth skips the audience check when the audience is empty.
        if ($this->clientId === '') {
            throw new GoogleTokenInvalidException;
        }

        try {
            /** @var array<string, mixed> $claims */
            $claims = $this->accessToken->verify($idToken, [
                'audience' => $this->clientId,
                'certsLocation' => $this->certsLocation,
                // google/auth's own key has a '|', which its FileSystemCacheItemPool refuses.
                'cacheKey' => 'google_id_token_certs',
                'throwException' => true,
            ]);

            return new GoogleIdentity(
                subject: Arr::string($claims, 'sub'),
                email: Arr::string($claims, 'email'),
                emailVerified: Arr::boolean($claims, 'email_verified'),
                name: Arr::string($claims, 'name', ''),
            );
        } catch (InvalidArgumentException $invalid) {
            throw $this->isBrokenKeySet($invalid) ? $this->unavailable($invalid) : new GoogleTokenInvalidException;
        } catch (UnexpectedValueException|DomainException) {
            throw new GoogleTokenInvalidException;
        } catch (RuntimeException $unreachable) {
            // A non-200 key download and every Guzzle transfer error land here; the token was never judged.
            throw $this->unavailable($unreachable);
        }
    }

    /** google/auth raises these for a key set Google served broken, not for a bad token. */
    protected function isBrokenKeySet(InvalidArgumentException $invalid): bool
    {
        return in_array($invalid->getMessage(), [
            'federated sign-on certs expects "keys" to be set',
            'certs expects "alg" to be set',
            'More than one alg detected in certs',
        ], true);
    }

    protected function unavailable(RuntimeException|InvalidArgumentException $cause): GoogleSignInUnavailableException
    {
        return new GoogleSignInUnavailableException('Google sign-in is temporarily unavailable.', previous: $cause);
    }
}

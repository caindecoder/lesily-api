<?php

namespace App\Foundation\Auth\IO\Google;

use DomainException;
use Illuminate\Support\Arr;
use Google\Auth\AccessToken;
use InvalidArgumentException;
use UnexpectedValueException;
use App\Foundation\Auth\UseCases\Google\GoogleIdentity;
use App\Foundation\Auth\UseCases\Google\GoogleIdTokenVerifier;
use App\Foundation\Auth\Exceptions\GoogleTokenInvalidException;

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
                'throwException' => true,
            ]);

            return new GoogleIdentity(
                subject: Arr::string($claims, 'sub'),
                email: Arr::string($claims, 'email'),
                emailVerified: Arr::boolean($claims, 'email_verified'),
                name: Arr::string($claims, 'name'),
            );
        } catch (UnexpectedValueException|InvalidArgumentException|DomainException) {
            throw new GoogleTokenInvalidException;
        }
    }
}

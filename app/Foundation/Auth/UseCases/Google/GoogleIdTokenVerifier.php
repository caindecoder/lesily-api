<?php

namespace App\Foundation\Auth\UseCases\Google;

use App\Foundation\Auth\Exceptions\GoogleTokenInvalidException;

interface GoogleIdTokenVerifier
{
    /**
     * Reads the identity from a Google ID token signed by Google for our client id and not expired.
     *
     * @throws GoogleTokenInvalidException
     */
    public function verify(string $idToken): GoogleIdentity;
}

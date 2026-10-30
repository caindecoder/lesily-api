<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/** No Lesily account uses this Google-verified email — the app opens signup pre-filled (G5). */
class GoogleAccountNotRegisteredException extends RuntimeException
{
    public function __construct(
        public readonly string $googleEmail,
        public readonly string $googleName,
    ) {
        parent::__construct('No Lesily account uses this Google account. Sign up with a password, then link Google.');
    }

    /**
     * @return array<string, string>
     */
    public function responseExtras(): array
    {
        return ['google_email' => $this->googleEmail, 'google_name' => $this->googleName];
    }
}

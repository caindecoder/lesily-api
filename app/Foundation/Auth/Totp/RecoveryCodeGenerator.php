<?php

namespace App\Foundation\Auth\Totp;

use Illuminate\Support\Str;

/**
 * Generates single-use 2FA recovery codes — distinct, human-transcribable XXXX-XXXX
 * strings. The plaintext is shown to the user exactly once; the interactor bcrypt-
 * hashes each before storing (slice-2fa R2), so codes are never re-displayable.
 */
class RecoveryCodeGenerator
{
    /**
     * @return list<string>
     */
    public function generate(int $count = 10): array
    {
        $codes = [];

        while (count($codes) < $count) {
            $code = $this->newCode();
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    protected function newCode(): string
    {
        return Str::upper(Str::random(4).'-'.Str::random(4));
    }
}

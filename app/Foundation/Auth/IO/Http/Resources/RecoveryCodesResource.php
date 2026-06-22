<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 200 envelope carrying the one-time-display recovery codes (UC-1-006 confirm +
 * UC-1-137 regenerate). Plaintext, shown ONCE; stored bcrypt-hashed and never
 * re-displayable (slice-2fa R2/R8).
 */
class RecoveryCodesResource extends JsonResource
{
    /**
     * @param  list<string>  $codes
     */
    public function __construct(private readonly array $codes)
    {
        parent::__construct($codes);
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(Request $request): array
    {
        return [
            'recovery_codes' => $this->codes,
        ];
    }
}

<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Foundation\Auth\Totp\TwoFactorSetup;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 200 envelope for the enable step (UC-1-006 / api-1-auth-onboarding §2fa/enable):
 * the otpauth:// URI (client renders the QR) + the base32 secret (manual entry).
 * No recovery codes here — those are returned at /2fa/confirm (slice-2fa R8).
 */
class TwoFactorSetupResource extends JsonResource
{
    public function __construct(private readonly TwoFactorSetup $setup)
    {
        parent::__construct($setup);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'otpauth_uri' => $this->setup->otpauthUri,
            'secret' => $this->setup->secret,
        ];
    }
}

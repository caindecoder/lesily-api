<?php

namespace App\Foundation\Auth\Support;

use Illuminate\Http\Request;

/**
 * Resolves the name a freshly minted session token carries in the Active Sessions list
 * (sessions slice). A delivery-layer helper (the User-Agent is an HTTP detail, not a
 * use-case concern — E07): the controller resolves it and passes it onto the command.
 * An explicit client-supplied name wins; absent it the parsed User-Agent names the
 * device; a blank User-Agent falls back to a safe floor so a token is never unnamed.
 */
class DeviceName
{
    public static function resolve(?string $supplied, Request $request): string
    {
        return $supplied ?: ($request->userAgent() ?: 'Unknown device');
    }
}

<?php

namespace App\Foundation\Auth\Support;

use Illuminate\Http\Request;

/**
 * Resolves the name a freshly minted session token carries in the Active Sessions list
 * (sessions slice). A delivery-layer helper (the User-Agent is an HTTP detail, not a
 * use-case concern — E07): the controller resolves it and passes it onto the command.
 * An explicit client-supplied name wins; absent it the parsed User-Agent names the
 * device; a blank User-Agent falls back to a safe floor so a token is never unnamed.
 *
 * $supplied is the raw request input (typed `mixed`): the resolver runs BEFORE the
 * command's `string` validation, so a malformed body (an array, a number) must be
 * coerced to "not supplied" here rather than crashing the typed sink — a non-string
 * client value never reaches a 422.
 */
class DeviceName
{
    public static function resolve(mixed $supplied, Request $request): string
    {
        $supplied = is_string($supplied) ? $supplied : null;

        return $supplied ?: ($request->userAgent() ?: 'Unknown device');
    }
}

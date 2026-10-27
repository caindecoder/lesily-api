<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Http\Request;
use App\Consent\Entities\ConsentRecord;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Http\Exceptions\ConsentRequiredException;

/**
 * Refuses a company request until the member has accepted every live policy
 * version for that company (UC-1-069). Runs after AuthorizeCompany, which puts
 * the company on the request.
 */
class EnsureCurrentConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user — guaranteed by upstream auth:sanctum */
        $user = $request->user();

        if (! ConsentRecord::livePoliciesAcceptedBy($user, $request->company())) {
            throw new ConsentRequiredException;
        }

        return $next($request);
    }
}

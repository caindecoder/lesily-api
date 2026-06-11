<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Http\Services\CompanyContextResolver;
use App\Foundation\Http\Exceptions\CompanyAccessDeniedException;

/**
 * Resolves and validates the company context (cd-foundation-http step 4, after
 * auth:sanctum step 3). Denies the request when the authenticated user is not a
 * member of the resolved company; a missing/unknown company surfaces 404 via the
 * resolver. Runs upstream of the feature + permission gates per api-conventions §7.
 */
class AuthorizeCompany
{
    public function __construct(private readonly CompanyContextResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user — guaranteed by upstream auth:sanctum (cd-foundation-http step 3) */
        $user = $request->user();
        $company = $this->resolver->resolve($request);

        if (! $company->users()->whereKey($user->getKey())->exists()) {
            throw new CompanyAccessDeniedException;
        }

        $request->attributes->set('company', $company);

        return $next($request);
    }
}

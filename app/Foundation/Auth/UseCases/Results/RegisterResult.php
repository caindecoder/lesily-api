<?php

namespace App\Foundation\Auth\UseCases\Results;

/**
 * The interactor's response model (E07) — what crosses back over the use-case
 * boundary to the delivery ring. The controller turns it into HTTP (token issue,
 * resource, status); the interactor never sees those concerns.
 */
class RegisterResult
{
    public function __construct(
        public readonly string $userId,
        public readonly string $companyId,
    ) {}
}

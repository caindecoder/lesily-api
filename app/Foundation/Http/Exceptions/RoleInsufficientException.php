<?php

namespace App\Foundation\Http\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleInsufficientException extends HttpException
{
    public function __construct(
        public string $permission,
        public ?string $userRoleCode = null,
    ) {
        parent::__construct(403, "You do not have permission to {$permission}.");
    }
}

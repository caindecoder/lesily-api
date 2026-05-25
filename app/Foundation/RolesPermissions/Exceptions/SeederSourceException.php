<?php

namespace App\Foundation\RolesPermissions\Exceptions;

use Throwable;
use RuntimeException;

class SeederSourceException extends RuntimeException
{
    public static function missingFile(string $path): self
    {
        return new self("RBAC seeder source file missing or unreadable: {$path}");
    }

    public static function parseFailure(string $path, ?Throwable $cause = null): self
    {
        return new self("RBAC seeder source file unparseable: {$path}", previous: $cause);
    }
}

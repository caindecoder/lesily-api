<?php

namespace App\Foundation\RolesPermissions\Exceptions;

use RuntimeException;

class SeederSourceException extends RuntimeException
{
    public static function missingFile(string $path): self
    {
        return new self("RBAC seeder source file missing or unreadable: {$path}");
    }
}

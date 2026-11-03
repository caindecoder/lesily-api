<?php

namespace App\Foundation\CountryConfig\Exceptions;

use Throwable;
use RuntimeException;

class CountrySourceException extends RuntimeException
{
    public static function missingFile(string $path): self
    {
        return new self("Countries seeder source file missing or unreadable: {$path}");
    }

    public static function parseFailure(string $path, ?Throwable $cause = null): self
    {
        return new self("Countries seeder source file unparseable: {$path}", previous: $cause);
    }
}

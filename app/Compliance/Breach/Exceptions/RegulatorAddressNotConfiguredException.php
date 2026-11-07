<?php

namespace App\Compliance\Breach\Exceptions;

use RuntimeException;

class RegulatorAddressNotConfiguredException extends RuntimeException
{
    public static function forFiling(): self
    {
        return new self('A breach filing was refused: platform.regulator_address is empty (MA-124).');
    }
}

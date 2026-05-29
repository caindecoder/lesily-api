<?php

namespace App\Foundation\Http\Exceptions;

use Exception;

class FeatureUnavailableException extends Exception
{
    public function __construct(public string $upgradeTo, public string $upgradeUrl)
    {
        parent::__construct('This feature is not available on your current plan.');
    }
}

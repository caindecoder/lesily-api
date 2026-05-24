<?php

namespace App\Foundation\Http\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class FeatureUnavailableException extends HttpException
{
    public function __construct(
        public string $upgradeTo,
        public string $upgradeUrl,
    ) {
        parent::__construct(403, 'Plan does not include this feature.');
    }
}

<?php

namespace App\Foundation\Http\Exceptions;

use Exception;

class FeatureUnavailableException extends Exception
{
    public function __construct(public ?string $upgradeTo, public string $upgradeUrl)
    {
        parent::__construct('This feature is not available on your current plan.');
    }

    /**
     * Envelope extras for the 403 response. The upgrade tier is omitted when no
     * plan offers the feature (a fail-closed denial on a deprecated feature).
     *
     * @return array<string, string>
     */
    public function responseExtras(): array
    {
        $extras = ['upgrade_url' => $this->upgradeUrl];

        if ($this->upgradeTo !== null) {
            $extras['upgrade_to'] = $this->upgradeTo;
        }

        return $extras;
    }
}

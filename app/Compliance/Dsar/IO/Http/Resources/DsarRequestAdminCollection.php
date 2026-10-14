<?php

namespace App\Compliance\Dsar\IO\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The platform-admin queue's rows, under the member list's pagination envelope.
 */
class DsarRequestAdminCollection extends DsarRequestCollection
{
    /** @var class-string<JsonResource> */
    public $collects = DsarRequestAdminResource::class;
}

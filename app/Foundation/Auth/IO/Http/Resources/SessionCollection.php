<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The GET /me/sessions list envelope (UC-1-010) — wraps each active session in a
 * SessionResource under the {data: [...]} convention.
 *
 * @property-read Collection<int, PersonalAccessToken> $collection
 */
class SessionCollection extends ResourceCollection
{
    /** @var class-string<JsonResource> */
    public $collects = SessionResource::class;
}

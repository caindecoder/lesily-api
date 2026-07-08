<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use stdClass;
use Illuminate\Support\Collection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The GET /me/companies list envelope (UC-1-034) — plain non-paginated
 * {data: [...]}, name-ordered (slice-companies B4; the SessionCollection
 * non-paginated precedent).
 *
 * @property-read Collection<int, stdClass> $collection
 */
class MyCompaniesCollection extends ResourceCollection
{
    /** @var class-string<JsonResource> */
    public $collects = MyCompanyResource::class;
}

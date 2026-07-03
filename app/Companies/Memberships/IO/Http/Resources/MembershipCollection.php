<?php

namespace App\Companies\Memberships\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use App\Companies\Entities\CompanyUser;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The GET /memberships list envelope (UC-1-017) — MembershipResource rows under
 * {data: [...]} with the api-conventions §8.3 pagination meta
 * {meta: {pagination: {page, per_page, total, last_page}}}, replacing Laravel's
 * default links+meta paginator shape.
 *
 * @property-read Collection<int, CompanyUser> $collection
 */
class MembershipCollection extends ResourceCollection
{
    /** @var class-string<JsonResource> */
    public $collects = MembershipResource::class;

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'pagination' => [
                    'page' => $paginated['current_page'],
                    'per_page' => $paginated['per_page'],
                    'total' => $paginated['total'],
                    'last_page' => $paginated['last_page'],
                ],
            ],
        ];
    }
}

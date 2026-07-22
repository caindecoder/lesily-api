<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The GET /ownership-transfers list envelope (UC-1-126) — OwnershipTransferRequestResource
 * rows under {data: [...]} with the api-conventions §8.3 pagination meta
 * {meta: {pagination: {page, per_page, total, last_page}}}, replacing Laravel's
 * default links+meta paginator shape. Mirrors MembershipCollection (UC-1-017).
 */
class OwnershipTransferRequestCollection extends ResourceCollection
{
    /** @var class-string<JsonResource> */
    public $collects = OwnershipTransferRequestResource::class;

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

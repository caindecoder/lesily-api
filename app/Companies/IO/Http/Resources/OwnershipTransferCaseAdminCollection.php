<?php

namespace App\Companies\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The GET /v1/admin/ownership-transfer-cases list envelope (UC-1-127) — case
 * rows under {data: [...]} with the api-conventions §8.3 pagination meta
 * {meta: {pagination: {page, per_page, total, last_page}}}, replacing
 * Laravel's default links+meta paginator shape (InvoiceCollection /
 * MembershipCollection precedent).
 */
class OwnershipTransferCaseAdminCollection extends ResourceCollection
{
    /** @var class-string<JsonResource> */
    public $collects = OwnershipTransferCaseAdminResource::class;

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

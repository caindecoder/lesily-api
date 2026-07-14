<?php

namespace App\Billing\Invoices\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The GET /invoices list envelope (UC-1-120) — invoice rows under {data: [...]}
 * with the api-conventions §8.3 pagination meta {meta: {pagination: {page,
 * per_page, total, last_page}}}, replacing Laravel's default links+meta
 * paginator shape (MembershipCollection precedent).
 */
class InvoiceCollection extends ResourceCollection
{
    /** @var class-string<JsonResource> */
    public $collects = InvoiceResource::class;

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

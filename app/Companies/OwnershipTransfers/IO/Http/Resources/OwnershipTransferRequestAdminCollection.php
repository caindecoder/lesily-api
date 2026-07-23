<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The GET /admin/ownership-transfers queue envelope (UC-1-127) —
 * OwnershipTransferRequestAdminResource rows under {data: [...]} with the
 * api-conventions §8.3 pagination meta.
 *
 * A sibling of OwnershipTransferRequestCollection rather than a subclass: a
 * tenant-side change must never silently reshape the admin envelope (E09 §5
 * accidental coupling). The repeated paginationInformation() body is the §8.3
 * project contract — its 4th copy; a bounded extraction is filed as an OQ
 * rather than done here (E68 §3 — no cleanup project inside a read increment).
 */
class OwnershipTransferRequestAdminCollection extends ResourceCollection
{
    /** @var class-string<JsonResource> */
    public $collects = OwnershipTransferRequestAdminResource::class;

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

<?php

namespace App\Foundation\AuditTrail\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AuditEntryCollection extends ResourceCollection
{
    /** @var class-string<JsonResource> */
    public $collects = AuditEntryResource::class;

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

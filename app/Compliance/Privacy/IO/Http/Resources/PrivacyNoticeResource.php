<?php

namespace App\Compliance\Privacy\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Compliance\Privacy\UseCases\Notice\PrivacyNotice;

/**
 * @property-read PrivacyNotice $resource
 */
class PrivacyNoticeResource extends JsonResource
{
    /**
     * @return list<array{key: string, heading: mixed, body: array<int, mixed>}>
     */
    public function toArray(Request $request): array
    {
        $items = $this->resource->items();

        return array_map(fn (string $key, array $lines): array => [
            'key' => $key,
            'heading' => __("privacy.notice.{$key}.heading"),
            'body' => $lines,
        ], array_keys($items), $items);
    }
}

<?php

namespace App\Compliance\Privacy\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Compliance\Privacy\Enums\ThirdParty;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Compliance\Privacy\UseCases\Register\ProcessingActivity;

/**
 * @property-read ProcessingActivity $resource
 */
class ProcessingActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activity = $this->resource;
        $key = $activity->key();

        return [
            'key' => $key,
            'module' => __("privacy.modules.{$activity->module}"),
            'activity' => __("privacy.activities.{$key}.name"),
            'data_categories' => array_map(fn (string $category): mixed => __("privacy.data_categories.{$category}"), $activity->dataCategories),
            'purpose' => __("privacy.activities.{$key}.purpose"),
            'legal_basis' => __("privacy.legal_bases.{$activity->legalBasis->value}"),
            'retention' => __("privacy.activities.{$key}.retention"),
            'third_parties' => array_map(fn (ThirdParty $party): mixed => __("privacy.third_parties.{$party->value}"), $activity->thirdParties),
        ];
    }
}

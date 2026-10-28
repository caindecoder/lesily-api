<?php

namespace App\Compliance\Privacy\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Companies\Entities\Company;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Company $resource
 */
class PrivacyNoticeResource extends JsonResource
{
    protected const array ITEMS = [
        'controller',
        'dpo',
        'purposes',
        'legitimate_interests',
        'data_categories',
        'withdraw_consent',
        'recipients',
        'cross_border_transfer',
        'retention',
        'data_subject_rights',
        'complaint',
        'statutory_or_contractual',
        'automated_decisions',
    ];

    /**
     * @return list<array{key: string, heading: mixed, body: list<string>}>
     */
    public function toArray(Request $request): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'heading' => __("privacy.notice.{$key}.heading"),
            'body' => [],
        ], self::ITEMS);
    }
}

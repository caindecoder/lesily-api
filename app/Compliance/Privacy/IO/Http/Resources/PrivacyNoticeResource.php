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
            'body' => $this->bodyOf($key),
        ], self::ITEMS);
    }

    /**
     * @return list<mixed>
     */
    protected function bodyOf(string $key): array
    {
        return match ($key) {
            'controller' => $this->controllerLines(),
            'dpo' => [__('privacy.notice.dpo.body')],
            default => [],
        };
    }

    /**
     * @return list<mixed>
     */
    protected function controllerLines(): array
    {
        $company = $this->resource;
        $owner = $company->owner();

        return [
            __('privacy.notice.controller.company', [
                'name' => $company->name,
                'br_number' => $company->br_number,
                'country' => __("privacy.countries.{$company->country}"),
            ]),
            $owner === null
                ? __('privacy.notice.controller.no_contact')
                : __('privacy.notice.controller.contact', ['name' => $owner->name, 'email' => $owner->email]),
        ];
    }
}

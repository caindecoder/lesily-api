<?php

namespace App\Compliance\Privacy\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Companies\Entities\Company;
use App\Compliance\Privacy\Enums\LegalBasis;
use App\Compliance\Privacy\Enums\ThirdParty;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Compliance\Privacy\UseCases\Register\ProcessingActivity;

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
     * @param  list<ProcessingActivity>  $activities
     */
    public function __construct(Company $company, protected array $activities)
    {
        parent::__construct($company);
    }

    /**
     * @return list<array{key: string, heading: mixed, body: list<mixed>}>
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
            'purposes' => $this->activityLines($key, $this->activities, fn (ProcessingActivity $activity): array => [
                'purpose' => __("privacy.activities.{$activity->key()}.purpose"),
                'basis' => __("privacy.legal_bases.{$activity->legalBasis->value}"),
            ]),
            'legitimate_interests' => $this->activityLines($key, $this->activitiesOnLegitimateInterests(), fn (ProcessingActivity $activity): array => [
                'purpose' => __("privacy.activities.{$activity->key()}.purpose"),
            ]),
            'retention' => $this->activityLines($key, $this->activities, fn (ProcessingActivity $activity): array => [
                'retention' => __("privacy.activities.{$activity->key()}.retention"),
            ]),
            'data_categories' => $this->dataCategoryLines(),
            'recipients' => $this->recipientLines(),
            default => $this->fixedLines($key),
        };
    }

    /**
     * @return list<mixed>
     */
    protected function fixedLines(string $key): array
    {
        /** @var list<string> $lines */
        $lines = __("privacy.notice.{$key}.body");

        return $lines;
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

    /**
     * One line per activity: the item's line worded with the activity's name and $replacements.
     *
     * @param  list<ProcessingActivity>  $activities
     * @param  callable(ProcessingActivity): array<string, mixed>  $replacements
     * @return list<mixed>
     */
    protected function activityLines(string $item, array $activities, callable $replacements): array
    {
        return array_map(fn (ProcessingActivity $activity): mixed => __(
            "privacy.notice.{$item}.line",
            ['activity' => __("privacy.activities.{$activity->key()}.name"), ...$replacements($activity)],
        ), $activities);
    }

    /**
     * @return list<ProcessingActivity>
     */
    protected function activitiesOnLegitimateInterests(): array
    {
        return array_values(array_filter(
            $this->activities,
            fn (ProcessingActivity $activity): bool => $activity->legalBasis === LegalBasis::LegitimateInterests,
        ));
    }

    /**
     * @return list<mixed>
     */
    protected function dataCategoryLines(): array
    {
        $categories = array_merge(...array_map(fn (ProcessingActivity $activity): array => $activity->dataCategories, $this->activities));

        return array_map(fn (string $category): mixed => __("privacy.data_categories.{$category}"), array_values(array_unique($categories)));
    }

    /**
     * @return list<mixed>
     */
    protected function recipientLines(): array
    {
        $parties = array_merge(...array_map(
            fn (ProcessingActivity $activity): array => array_map(fn (ThirdParty $party): string => $party->value, $activity->thirdParties),
            $this->activities,
        ));

        return array_map(fn (string $party): mixed => __("privacy.third_parties.{$party}"), array_values(array_unique($parties)));
    }
}

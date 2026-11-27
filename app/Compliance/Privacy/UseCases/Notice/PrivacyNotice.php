<?php

namespace App\Compliance\Privacy\UseCases\Notice;

use App\Companies\Entities\Company;
use App\Compliance\Privacy\Enums\LegalBasis;
use App\Compliance\Privacy\Enums\ThirdParty;
use App\Compliance\Privacy\UseCases\Register\ProcessingActivity;

class PrivacyNotice
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
     * The fixed items' lines, by key; their words are in lang/en/privacy.php.
     */
    protected const array FIXED_LINES = [
        'withdraw_consent' => ['how'],
        'cross_border_transfer' => ['where'],
        'data_subject_rights' => ['access', 'correction_erasure_objection', 'answer_time'],
        'complaint' => ['authority'],
        'statutory_or_contractual' => ['contract', 'law'],
        'automated_decisions' => ['none'],
    ];

    /**
     * @param  list<ProcessingActivity>  $activities
     */
    public function __construct(protected Company $company, protected array $activities) {}

    /**
     * The 13 Schedule V items in order, each its lines written for a person to read.
     *
     * @return array<string, array<int, mixed>>
     */
    public function items(): array
    {
        return array_combine(self::ITEMS, array_map(fn (string $key): array => $this->linesOf($key), self::ITEMS));
    }

    /**
     * @return array<int, mixed>
     */
    protected function linesOf(string $key): array
    {
        return match ($key) {
            'controller' => $this->controllerLines(),
            'dpo' => $this->dpoLines(),
            'purposes' => $this->activityLines($key, $this->activities, fn (ProcessingActivity $processingActivity): array => [
                'purpose' => __("privacy.activities.{$processingActivity->key()}.purpose"),
                'basis' => __("privacy.legal_bases.{$processingActivity->legalBasis->value}"),
            ]),
            'legitimate_interests' => $this->activityLines($key, $this->activitiesOnLegitimateInterests(), fn (ProcessingActivity $processingActivity): array => [
                'purpose' => __("privacy.activities.{$processingActivity->key()}.purpose"),
            ]),
            'retention' => $this->activityLines($key, $this->activities, fn (ProcessingActivity $processingActivity): array => [
                'retention' => __("privacy.activities.{$processingActivity->key()}.retention"),
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
        return array_map(fn (string $line): mixed => __("privacy.notice.{$key}.lines.{$line}"), self::FIXED_LINES[$key]);
    }

    /**
     * @return list<mixed>
     */
    protected function controllerLines(): array
    {
        $owner = $this->company->owner();

        return [
            __('privacy.notice.controller.company', [
                'name' => $this->company->name,
                'br_number' => $this->company->br_number,
                'country' => __("privacy.countries.{$this->company->country}"),
            ]),
            $owner === null
                ? __('privacy.notice.controller.no_contact')
                : __('privacy.notice.controller.contact', ['name' => $owner->name, 'email' => $owner->email]),
        ];
    }

    /**
     * The company's recorded data protection officer as the contact, or the line saying none
     * is designated.
     *
     * @return list<mixed>
     */
    protected function dpoLines(): array
    {
        $companyDataProtectionOfficer = $this->company->dataProtectionOfficer;

        if ($companyDataProtectionOfficer === null) {
            return [__('privacy.notice.dpo.lines.none_designated')];
        }

        return [$companyDataProtectionOfficer->phone === null
            ? __('privacy.notice.dpo.contact_without_phone', ['name' => $companyDataProtectionOfficer->name, 'email' => $companyDataProtectionOfficer->email])
            : __('privacy.notice.dpo.contact', ['name' => $companyDataProtectionOfficer->name, 'email' => $companyDataProtectionOfficer->email, 'phone' => $companyDataProtectionOfficer->phone])];
    }

    /**
     * One line per activity: the item's line worded with the activity's name and $replacements.
     *
     * @param  array<int, ProcessingActivity>  $activities
     * @param  callable(ProcessingActivity): array<string, mixed>  $replacements
     * @return array<int, mixed>
     */
    protected function activityLines(string $item, array $activities, callable $replacements): array
    {
        return array_map(fn (ProcessingActivity $processingActivity): mixed => __(
            "privacy.notice.{$item}.line",
            ['activity' => __("privacy.activities.{$processingActivity->key()}.name"), ...$replacements($processingActivity)],
        ), $activities);
    }

    /**
     * @return array<int, ProcessingActivity>
     */
    protected function activitiesOnLegitimateInterests(): array
    {
        return array_filter(
            $this->activities,
            fn (ProcessingActivity $processingActivity): bool => $processingActivity->legalBasis === LegalBasis::LegitimateInterests,
        );
    }

    /**
     * @return array<int, mixed>
     */
    protected function dataCategoryLines(): array
    {
        $categories = array_merge(...array_map(fn (ProcessingActivity $processingActivity): array => $processingActivity->dataCategories, $this->activities));

        return array_map(fn (string $category): mixed => __("privacy.data_categories.{$category}"), array_unique($categories));
    }

    /**
     * @return array<int, mixed>
     */
    protected function recipientLines(): array
    {
        $parties = array_merge(...array_map(
            fn (ProcessingActivity $processingActivity): array => array_map(fn (ThirdParty $thirdParty): string => $thirdParty->value, $processingActivity->thirdParties),
            $this->activities,
        ));

        return array_map(fn (string $party): mixed => __("privacy.third_parties.{$party}"), array_unique($parties));
    }
}

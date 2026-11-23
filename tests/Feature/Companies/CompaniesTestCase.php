<?php

namespace Tests\Feature\Companies;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsAuditTrail;
use App\Consent\Events\ConsentRecorded;
use App\Companies\Entities\CompanySettings;
use Tests\Concerns\AssertsCompanyAuditRows;
use Tests\Concerns\InducesProjectorFailures;
use App\Companies\Events\CompanySettingReset;
use App\Companies\Events\CompanyDetailsUpdated;
use App\Companies\Events\CompanySettingsUpdated;
use App\Foundation\CountryConfig\Entities\Country;
use App\Companies\Entities\CompanyDataProtectionOfficer;
use App\Companies\Events\CompanyDataProtectionOfficerSet;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

abstract class CompaniesTestCase extends TestCase
{
    use AssertsAuditTrail, AssertsCompanyAuditRows, InducesProjectorFailures;

    /**
     * A fresh user made a live member of $company under a seeded role — the
     * company_user pivot row the company-scoped gates read — who accepted the
     * live policy versions there.
     */
    protected function aCompanyMember(Company $company, string $roleCode = 'employee'): User
    {
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);
        $this->aConsentRecordedBy($user, $company);

        return $user;
    }

    /**
     * An authenticated member requests a company's details by URL id. $context
     * optionally supplies the X-Company-Context header (the pre-R8 mechanism) —
     * on this param route the URL must win over it.
     */
    protected function viewCompanyDetails(User $user, string $companyId, ?string $context = null): TestResponse
    {
        Sanctum::actingAs($user);

        $headers = $context === null ? [] : ['X-Company-Context' => $context];

        return $this->getJson('/api/v1/companies/'.$companyId, $headers);
    }

    /**
     * A guest (no Sanctum session) requests a company's details by URL id.
     */
    protected function viewCompanyDetailsAsGuest(string $companyId): TestResponse
    {
        return $this->getJson('/api/v1/companies/'.$companyId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function setDataProtectionOfficer(User $user, string $companyId, array $payload): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->putJson('/api/v1/companies/'.$companyId.'/data-protection-officer', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function setDataProtectionOfficerAsGuest(string $companyId, array $payload): TestResponse
    {
        return $this->putJson('/api/v1/companies/'.$companyId.'/data-protection-officer', $payload);
    }

    protected function viewDataProtectionOfficer(User $user, string $companyId): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/companies/'.$companyId.'/data-protection-officer');
    }

    protected function viewDataProtectionOfficerAsGuest(string $companyId): TestResponse
    {
        return $this->getJson('/api/v1/companies/'.$companyId.'/data-protection-officer');
    }

    protected function removeDataProtectionOfficer(User $user, string $companyId): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->deleteJson('/api/v1/companies/'.$companyId.'/data-protection-officer');
    }

    protected function removeDataProtectionOfficerAsGuest(string $companyId): TestResponse
    {
        return $this->deleteJson('/api/v1/companies/'.$companyId.'/data-protection-officer');
    }

    /**
     * The company's DPO row reads back as $expected, and none of its three columns
     * holds a plain value.
     *
     * @param  array{name: string, email: string, phone: ?string}  $expected
     */
    protected function assertDpoStored(Company $company, array $expected): void
    {
        $officer = CompanyDataProtectionOfficer::query()->where('company_id', $company->getKey())->sole();
        $raw = DB::table('company_data_protection_officers')->where('company_id', $company->getKey())->sole();

        $this->assertSame($expected, $officer->contact());
        $this->assertSame([], array_intersect([$raw->name, $raw->email, $raw->phone], array_filter($expected)));
    }

    /**
     * Exactly one $eventClass DPO event, filed under the company, names $actor and a time.
     *
     * @param  class-string  $eventClass
     */
    protected function assertDpoEventRecordedBy(Company $company, string $eventClass, User $actor): void
    {
        [$by, $at] = $eventClass === CompanyDataProtectionOfficerSet::class ? ['setBy', 'setAt'] : ['removedBy', 'removedAt'];

        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', $eventClass)
            ->where("event_properties->{$by}", $actor->getKey())
            ->whereNotNull("event_properties->{$at}")
            ->whereForCompany($company->getKey())
            ->count());
    }

    /**
     * The company has no DPO row and no DPO was ever set on its stream.
     */
    protected function assertNoDpo(Company $company): void
    {
        $this->assertFalse(CompanyDataProtectionOfficer::query()->where('company_id', $company->getKey())->exists());
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $company->getKey())
            ->where('event_class', CompanyDataProtectionOfficerSet::class)
            ->count());
    }

    /**
     * A guest (no Sanctum session) submits a company-details update by URL id.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function updateCompanyDetailsAsGuest(string $companyId, array $payload): TestResponse
    {
        return $this->putJson('/api/v1/companies/'.$companyId, $payload);
    }

    /**
     * An authenticated member submits a company-details update by URL id. The PUT
     * route is param-authoritative (R8), so no X-Company-Context header is sent.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function updateCompanyDetails(User $user, string $companyId, array $payload): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->putJson('/api/v1/companies/'.$companyId, $payload);
    }

    /**
     * A rejected update left the company untouched — the name still reads
     * $expectedName, the settings still follow the country, and the company holds
     * no event beyond the $eventsBefore it was arranged with. The LD / amendment-R
     * state-unchanged discipline for sad paths.
     */
    protected function assertCompanyUnchanged(Company $company, string $expectedName, int $eventsBefore): void
    {
        $company->refresh();
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();

        $this->assertSame($expectedName, $company->name);
        $this->assertNull($settings->timezone);
        $this->assertSame($eventsBefore, LesilyStoredEvent::query()->whereForCompany($company->getKey())->where('event_class', '!=', ConsentRecorded::class)->count());
    }

    /**
     * The exact UC-1-032 CompanyResource wire shape for $company — read back
     * through the models so casts govern the expected types. B7 teeth: no
     * employee_count / legal_hold / owner_id keys exist to be expected.
     *
     * @return array<string, mixed>
     */
    protected function expectedCompanyDetails(Company $company): array
    {
        $company->refresh();
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();
        $country = Country::query()->where('code', $company->country)->sole();

        return [
            'id' => $company->getKey(),
            'name' => $company->name,
            'br_number' => $company->br_number,
            'epf_number' => $company->epf_number,
            'country' => $company->country,
            'plan' => $company->current_billing_plan?->value,
            'status' => $company->status->value,
            'trial_ends_at' => $company->trial_ends_at?->toISOString(),
            'settings' => [
                'timezone' => $settings->timezone ?? $country->timezone,
                'currency' => $settings->currency ?? $country->currency,
                'locale_default' => $settings->locale_default ?? $country->locale_default,
                'fiscal_year_start_month' => $settings->fiscal_year_start_month ?? $country->fiscal_year_start_month,
                'working_days_per_week' => $settings->working_days_per_week ?? $country->working_days_per_week,
                'default_work_hours_per_day' => $settings->default_work_hours_per_day ?? $country->default_work_hours_per_day,
            ],
        ];
    }

    /**
     * A name-only update recorded exactly one CompanyDetailsUpdated event (the
     * settings event stays silent — conditional emission) and the name landed on
     * the read model. One event beyond the $eventsBefore proves nothing else was recorded.
     */
    protected function assertOnlyDetailsEventRecorded(Company $company, string $expectedName, int $eventsBefore): void
    {
        $company->refresh();

        $this->assertSame($expectedName, $company->name);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanyDetailsUpdated::class)
            ->whereForCompany($company->getKey())->count());
        $this->assertSame($eventsBefore + 1, LesilyStoredEvent::query()->whereForCompany($company->getKey())->where('event_class', '!=', ConsentRecorded::class)->count());
    }

    /**
     * A settings-only update recorded exactly one CompanySettingsUpdated event
     * (the details event stays silent — conditional emission) and the settings
     * read model holds $expectedTimezone (null: it still follows the country).
     * One event beyond the $eventsBefore proves nothing else was recorded.
     */
    protected function assertOnlySettingsEventRecorded(Company $company, ?string $expectedTimezone, int $eventsBefore): void
    {
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();

        $this->assertSame($expectedTimezone, $settings->timezone);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanySettingsUpdated::class)
            ->whereForCompany($company->getKey())->count());
        $this->assertSame($eventsBefore + 1, LesilyStoredEvent::query()->whereForCompany($company->getKey())->where('event_class', '!=', ConsentRecorded::class)->count());
    }

    protected function giveOwnSettings(Company $company): void
    {
        $ownValues = Arr::except(CompanySettings::factory()->ownValues()->raw(['company_id' => $company->getKey()]), ['company_id']);

        CompanySettings::query()->where('company_id', $company->getKey())->update($ownValues);
    }

    /**
     * Both edits landed the event-sourced way: a CompanyDetailsUpdated and a
     * CompanySettingsUpdated stored event, each scoped to the company and stamped
     * with the acting user + an updatedAt, carry the new read-model values
     * (amendment R — a direct read-model write would leave these events absent and
     * be silently reverted on a projection replay). The float hours are covered by
     * the read-model assertDatabaseHas (JSON float equality is avoided here).
     */
    protected function assertCompanyUpdateWasEventSourced(Company $company, string $updatedBy): void
    {
        $company->refresh();
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();

        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanyDetailsUpdated::class)
            ->where('event_properties->name', $company->name)
            ->where('event_properties->updatedBy', $updatedBy)
            ->whereNotNull('event_properties->updatedAt')
            ->where('company_id', $company->getKey())
            ->count());

        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanySettingsUpdated::class)
            ->where('event_properties->timezone', $settings->timezone)
            ->where('event_properties->fiscalYearStartMonth', $settings->fiscal_year_start_month)
            ->where('event_properties->workingDaysPerWeek', $settings->working_days_per_week)
            ->where('event_properties->updatedBy', $updatedBy)
            ->whereNotNull('event_properties->updatedAt')
            ->where('company_id', $company->getKey())
            ->count());
    }

    protected function assertSettingFollowsTheCountry(Company $company, string $setting): void
    {
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();

        $this->assertNull($settings->getAttribute($setting));
        $this->assertTrue($settings->followsTheCountry($setting));
        $this->assertSame(Country::query()->where('code', 'LK')->sole()->getAttribute($setting), $settings->valueThatApplies($setting));
    }

    protected function assertResetRecorded(Company $company, string $setting, string $resetBy): void
    {
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanySettingReset::class)
            ->where('event_properties->setting', $setting)
            ->where('event_properties->resetBy', $resetBy)
            ->whereNotNull('event_properties->resetAt')
            ->whereForCompany($company->getKey())
            ->count());
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', CompanySettingsUpdated::class)
            ->whereForCompany($company->getKey())->count());
    }
}

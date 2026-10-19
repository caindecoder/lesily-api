<?php

namespace Tests\Feature\Foundation\AccessLog;

use App\Models\User;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use Tests\Feature\Foundation\WithRolePermissionFixtures;

class FoldRepeatedRefusalsTest extends FoundationTestCase
{
    use WithRolePermissionFixtures;

    /** @var array<string, User> */
    protected array $people;

    /** @var array<string, Company> */
    protected array $companies;

    protected function setUp(): void
    {
        parent::setUp();

        $this->people = ['alice' => User::factory()->create(), 'bob' => User::factory()->create()];
        $this->companies = [
            'acme' => Company::factory()->onPlan(BillingPlan::Free)->create(),
            'globex' => Company::factory()->onPlan(BillingPlan::Free)->create(),
            'initech' => Company::factory()->onPlan(BillingPlan::Free)->create(),
        ];
        $this->joinCompany($this->companies['acme'], $this->people['alice']);
        $this->joinCompany($this->companies['globex'], $this->people['alice']);
        $this->joinCompany($this->companies['acme'], $this->people['bob']);
    }

    /**
     * @param  array<string, string>  $first
     * @param  array<string, string>  $second
     */
    #[Test]
    #[DataProvider('provideRefusalsThatDifferInOneKey')]
    public function when_a_different_refusal_arrives_inside_the_window_then_it_gets_its_own_row(array $first, array $second): void
    {
        // Given one refusal on record
        $this->refuse($first);

        // When a refusal that differs in one key arrives straight after
        $this->refuse($second);

        // Then each keeps a row of its own
        $this->assertCountedRows([1, 1]);
    }

    /** @return array<string, array{array<string, string>, array<string, string>}> */
    public static function provideRefusalsThatDifferInOneKey(): array
    {
        $anonymous = ['method' => 'GET', 'uri' => '/api/_test/authenticated-probe', 'from' => '203.0.113.7'];
        $stranger = ['method' => 'GET', 'uri' => '/api/v1/companies/{in}', 'from' => '203.0.113.7', 'as' => 'alice', 'in' => 'initech'];

        return [
            'another source address' => [$anonymous, [...$anonymous, 'from' => '203.0.113.8']],
            'another route' => [$anonymous, [...$anonymous, 'uri' => '/api/_test/company-context-probe']],
            'another method' => [$stranger, [...$stranger, 'method' => 'PUT']],
        ];
    }

    #[Test]
    public function when_five_minutes_have_passed_since_the_first_refusal_then_a_new_row_is_opened(): void
    {
        // Given one refusal on record, five minutes ago to the second
        $this->freezeTime();
        $this->refuseAnonymously();
        $this->travel(5)->minutes();

        // When the same refusal arrives again
        $response = $this->refuseAnonymously();

        // Then it opens a row of its own
        $response->assertUnauthorized();
        $this->assertCountedRows([1, 1]);
    }

    #[Test]
    public function when_repeats_keep_arriving_then_the_window_still_closes_five_minutes_after_the_first(): void
    {
        // Given refusals at minute zero and minute three
        $this->freezeTime();
        $this->refuseAnonymously();
        $this->travel(3)->minutes();
        $this->refuseAnonymously();
        $this->travel(3)->minutes();

        // When the same refusal arrives at minute six
        $response = $this->refuseAnonymously();

        // Then the first row closed at minute five and the third refusal opens a new one
        $response->assertUnauthorized();
        $this->assertCountedRows([2, 1]);
    }

    #[Test]
    public function when_the_same_refusal_repeats_inside_the_window_then_one_row_counts_them(): void
    {
        // Given one refusal on record, a second short of five minutes ago
        $this->freezeTime();
        $this->refuseAnonymously();
        $before = AccessLogEntry::query()->sole();
        $this->travel(299)->seconds();

        // When the same refusal arrives again
        $response = $this->refuseAnonymously();

        // Then the one row counts both, and only its count and last sighting moved
        $response->assertUnauthorized();
        $this->assertCountedAgain($before);
    }

    /**
     * @param  array<string, string>  $request  method, uri and the client address it comes from;
     *                                          optionally the person it is sent `as` and the company it is `in`
     */
    protected function refuse(array $request): TestResponse
    {
        if (isset($request['as'])) {
            Sanctum::actingAs($this->people[$request['as']]);
        }

        $companyId = isset($request['in']) ? $this->companies[$request['in']]->getKey() : '';

        return $this->withServerVariables(['REMOTE_ADDR' => $request['from']])->json(
            $request['method'],
            str_replace('{in}', $companyId, $request['uri']),
            headers: ['X-Company-Context' => $companyId],
        );
    }

    protected function refuseAnonymously(): TestResponse
    {
        return $this->getJson('/api/_test/authenticated-probe');
    }

    /** @param  list<int>  $occurrences  each row's count, oldest first */
    protected function assertCountedRows(array $occurrences): void
    {
        $this->assertSame($occurrences, AccessLogEntry::query()->orderBy('first_seen_at')->pluck('occurrences')->all());
    }

    protected function assertCountedAgain(AccessLogEntry $before): void
    {
        $after = AccessLogEntry::query()->sole();
        $unchanged = fn (AccessLogEntry $entry): array => Arr::except($entry->getAttributes(), ['occurrences', 'last_seen_at']);

        $this->assertSame(2, $after->occurrences);
        $this->assertSame(now()->toDateTimeString(), $after->last_seen_at->toDateTimeString());
        $this->assertSame($unchanged($before), $unchanged($after));
    }
}

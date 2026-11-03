<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\DB;
use Database\Seeders\CountriesSeeder;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\CountryConfig\Entities\Country;
use App\Foundation\CountryConfig\Exceptions\CountrySourceException;

/**
 * @testclass-carveout: seeder — the artifact IS the behavior (the countries
 * reference-data seeder and its source-file contract; conv-plan §2.14 Rule B).
 */
class CountriesSeederTest extends FoundationTestCase
{
    #[Test]
    public function when_the_source_file_is_missing_then_the_seeder_throws_with_the_file_path(): void
    {
        // Given a seeder reading a file that does not exist
        $seeder = $this->newSeeder('/nonexistent/countries.yaml');

        // When the seeder runs
        // Then it throws naming the missing file
        $this->expectException(CountrySourceException::class);
        $this->expectExceptionMessage('/nonexistent/countries.yaml');

        $seeder->run();
    }

    #[Test]
    public function when_the_source_file_is_malformed_then_the_seeder_throws_and_changes_no_country(): void
    {
        // Given the stored countries and an unparseable source file
        $before = $this->storedCountries();
        $path = $this->givenTempSource('invalid: yaml: : : malformed: bad: :');

        // When the seeder runs
        // Then it throws naming the file and the stored countries are unchanged
        try {
            $this->newSeeder($path)->run();
            $this->fail('CountrySourceException was not thrown.');
        } catch (CountrySourceException $e) {
            $this->assertStringContainsString($path, $e->getMessage());
            $this->assertSame($before, $this->storedCountries());
        }
    }

    #[Test]
    public function when_the_source_file_lists_no_country_then_the_stored_countries_are_unchanged(): void
    {
        // Given the stored countries and a file that lists none
        $this->givenSriLankaSeeded();
        $before = $this->storedCountries();
        $path = $this->givenTempSource("[]\n");

        // When the seeder runs
        $this->newSeeder($path)->run();

        // Then the stored countries are unchanged
        $this->assertSame($before, $this->storedCountries());
    }

    #[Test]
    public function when_a_stored_country_is_missing_from_the_file_then_it_is_kept(): void
    {
        // Given a stored country the file does not list
        $this->givenSriLankaSeeded();
        Country::factory()->create();
        $path = $this->givenTempSource($this->sriLankaYamlWithWorkingDays(5));

        // When the seeder runs
        $this->newSeeder($path)->run();

        // Then both countries are still stored
        $this->assertSame(['LK', 'MV'], Country::query()->orderBy('code')->pluck('code')->all());
    }

    #[Test]
    public function when_the_seeder_runs_twice_then_the_countries_are_unchanged(): void
    {
        // Given Sri Lanka seeded from the bundled file
        $this->givenSriLankaSeeded();
        $before = $this->storedCountries();

        // When the bundled file is seeded again
        CountriesSeeder::fromDefaultPath()->run();

        // Then the stored countries are unchanged
        $this->assertSame($before, $this->storedCountries());
    }

    #[Test]
    public function when_a_country_value_changes_in_the_file_then_its_row_is_updated_and_keeps_its_key(): void
    {
        // Given Sri Lanka seeded from the bundled file
        $original = $this->givenSriLankaSeeded();
        $path = $this->givenTempSource($this->sriLankaYamlWithWorkingDays(6));
        $this->travel(1)->seconds();

        // When a file that changes Sri Lanka's working days is seeded
        $this->newSeeder($path)->run();

        // Then the same row carries the new value
        $this->assertCountryRowUpdated($original, 6);
    }

    #[Test]
    public function when_the_bundled_file_is_seeded_then_sri_lanka_has_its_six_defaults(): void
    {
        // Given no stored country
        DB::table('countries')->delete();

        // When the bundled countries file is seeded
        CountriesSeeder::fromDefaultPath()->run();

        // Then Sri Lanka is stored with its six defaults
        $this->assertSriLankaDefaults();
    }

    protected function newSeeder(string $path): CountriesSeeder
    {
        return new CountriesSeeder(countriesYamlPath: $path);
    }

    protected function givenTempSource(string $yaml): string
    {
        $path = sys_get_temp_dir().'/countries-seeder-'.uniqid().'.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    protected function givenSriLankaSeeded(): Country
    {
        DB::table('countries')->delete();
        CountriesSeeder::fromDefaultPath()->run();

        return Country::query()->where('code', 'LK')->sole();
    }

    protected function sriLankaYamlWithWorkingDays(int $days): string
    {
        return "- code: LK\n  name: Sri Lanka\n  timezone: Asia/Colombo\n  currency: LKR\n".
            "  locale_default: en\n  fiscal_year_start_month: 4\n".
            "  working_days_per_week: {$days}\n  default_work_hours_per_day: 8.00\n";
    }

    protected function assertCountryRowUpdated(Country $original, int $workingDays): void
    {
        $stored = Country::query()->where('code', 'LK')->sole();

        $this->assertSame($workingDays, $stored->working_days_per_week);
        $this->assertSame($original->getKey(), $stored->getKey());
        $this->assertEquals($original->created_at, $stored->created_at);
        $this->assertNotNull($original->updated_at);
        $this->assertTrue($stored->updated_at?->greaterThan($original->updated_at) ?? false);
    }

    protected function assertSriLankaDefaults(): void
    {
        $this->assertDatabaseCount('countries', 1);
        $this->assertDatabaseHas('countries', [
            'code' => 'LK',
            'name' => 'Sri Lanka',
            'timezone' => 'Asia/Colombo',
            'currency' => 'LKR',
            'locale_default' => 'en',
            'fiscal_year_start_month' => 4,
            'working_days_per_week' => 5,
            'default_work_hours_per_day' => 8.00,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function storedCountries(): array
    {
        return DB::table('countries')->orderBy('code')->get()->map(fn (object $row): array => (array) $row)->all();
    }
}

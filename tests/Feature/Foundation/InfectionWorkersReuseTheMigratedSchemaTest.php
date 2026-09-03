<?php

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * @testclass-carveout: infra-primitive — the schema-reuse contract of the base
 * test case (conv-plan §2.1, OQ-1-181) is provable only at the migration seam;
 * no HTTP walk can observe which migration command a worker ran.
 */
class InfectionWorkersReuseTheMigratedSchemaTest extends FoundationTestCase
{
    private const MARKER = 'zz_9999_99_99_000000_oq_1_181_marker';

    private string|false $infectionEnvBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->infectionEnvBefore = getenv('INFECTION');
    }

    protected function tearDown(): void
    {
        putenv($this->infectionEnvBefore === false ? 'INFECTION' : 'INFECTION='.$this->infectionEnvBefore);
        RefreshDatabaseState::$migrated = true;

        parent::tearDown();
    }

    #[Test]
    public function when_the_process_is_not_an_infection_worker_then_the_schema_is_migrated_fresh(): void
    {
        // Given an ordinary test process (ParaTest, phpunit, artisan test) with a stale marker in the schema
        $this->markThisProcessAsAnOrdinaryRunner();
        $this->plantMigrationMarker();

        // When the base class migrates for the first test of the process
        $this->migrateDatabases();

        // Then the schema was rebuilt from scratch and the stale marker is gone
        $this->assertSchemaMigratedFresh();
    }

    #[Test]
    public function when_an_infection_worker_finds_the_schema_current_then_it_reuses_it_instead_of_migrating_fresh(): void
    {
        // Given a process marked as an Infection worker whose token database is already migrated
        $this->markThisProcessAsAnInfectionWorker();
        $this->plantMigrationMarker();

        // When the base class migrates for the first test of the process
        $this->migrateDatabases();

        // Then the current schema is reused: nothing was dropped and nothing was pending
        $this->assertSchemaReused();
    }

    private function markThisProcessAsAnOrdinaryRunner(): void
    {
        putenv('INFECTION');
        RefreshDatabaseState::$migrated = false;
        $this->withoutMockingConsoleOutput();
    }

    private function markThisProcessAsAnInfectionWorker(): void
    {
        putenv('INFECTION=1');
        RefreshDatabaseState::$migrated = false;
        $this->withoutMockingConsoleOutput();
    }

    private function plantMigrationMarker(): void
    {
        $this->app['db']->table('migrations')->insert(['migration' => self::MARKER, 'batch' => 999]);
    }

    protected function assertSchemaMigratedFresh(): void
    {
        $this->assertStringContainsString('Dropping all tables', Artisan::output());
        $this->assertDatabaseMissing('migrations', ['migration' => self::MARKER]);
    }

    protected function assertSchemaReused(): void
    {
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
        $this->assertDatabaseHas('migrations', ['migration' => self::MARKER]);
    }
}
